<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Operation;
use App\Models\OperationReport;
use Carbon\CarbonImmutable;

/**
 * Operation lifecycle commands. Every state change is validated, audited and reported.
 */
class OperationService
{
    public function __construct(
        private readonly DependencyService $dependencies,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly LanguageService $lang,
    ) {
    }

    // ---- executor actions ---------------------------------------------------------------------------------------

    public function start(string $id, AppUser $user): void
    {
        $op = $this->ownTask($id, $user);
        $this->require($op, OperationStatus::ASSIGNED);
        $this->change($op, OperationStatus::IN_PROGRESS, $user->id, null);
        $op->started_at = CarbonImmutable::now();
        $op->progress_percent = 0;
        $this->save($op);
        $this->report($op, $user->id, ReportType::START, 0, null);
        $this->dependencies->evaluateAll($user->id); // START_TO_START successors may unlock
    }

    public function progress(string $id, int $percent, ?string $note, AppUser $user): void
    {
        $op = $this->ownTask($id, $user);
        $this->require($op, OperationStatus::IN_PROGRESS);
        if ($percent < 0 || $percent > 100) {
            throw ApiException::badRequest('Progress must be between 0 and 100');
        }
        $before = $op->progress_percent;
        $op->progress_percent = $percent;
        $this->save($op);
        $this->audit->record($user->id, 'PROGRESS', 'Operation', $id, $before, $percent, $note);
        $this->report($op, $user->id, ReportType::PROGRESS, $percent, $note);
    }

    public function complete(string $id, ?string $note, AppUser $user): void
    {
        $op = $this->ownTask($id, $user);
        $this->require($op, OperationStatus::IN_PROGRESS);
        $this->change($op, OperationStatus::COMPLETED, $user->id, $note);
        $op->progress_percent = 100;
        $op->completed_at = CarbonImmutable::now();
        $op->completion_approved = false;
        $this->save($op);
        $this->report($op, $user->id, ReportType::COMPLETE, 100, $note);
        $this->notifications->notifyManagers('OPERATION_COMPLETED',
            "{$user->id} completed {$op->id} ({$op->name})", $id);
        $this->dependencies->evaluateAll($user->id);
    }

    public function block(string $id, ?string $reason, AppUser $user): void
    {
        $op = $this->ownTask($id, $user);
        if ($op->status !== OperationStatus::ASSIGNED && $op->status !== OperationStatus::IN_PROGRESS) {
            throw ApiException::conflict('Only assigned or in-progress operations can be reported as blocked');
        }
        if ($reason === null || trim($reason) === '') {
            throw ApiException::badRequest('A reason is required');
        }
        $op->status_before_block = $op->status;
        $op->block_reason = trim($reason);
        $this->change($op, OperationStatus::BLOCKED, $user->id, $reason);
        $this->save($op);
        $this->report($op, $user->id, ReportType::BLOCK, null, $reason);
        $this->notifications->notifyManagers('OPERATION_BLOCKED',
            "{$op->id} ({$op->name}) is blocked: {$reason}", $id);
    }

    public function comment(string $id, ?string $note, AppUser $user): void
    {
        $op = $this->find($id);
        if ($user->user_role->isExecutor() && $user->id !== $op->assigned_user_id) {
            throw ApiException::forbidden('This operation is not assigned to you');
        }
        if ($note === null || trim($note) === '') {
            throw ApiException::badRequest('A note is required');
        }
        $this->report($op, $user->id, ReportType::COMMENT, null, trim($note));
        $this->audit->record($user->id, 'COMMENT', 'Operation', $id, null, null, $note);
    }

    // ---- manager actions ----------------------------------------------------------------------------------------

    public function unblock(string $id, ?string $note, AppUser $user): void
    {
        $op = $this->find($id);
        $this->require($op, OperationStatus::BLOCKED);
        $back = $op->status_before_block ?? OperationStatus::ASSIGNED;
        $this->change($op, $back, $user->id, $note);
        $op->block_reason = null;
        $op->status_before_block = null;
        $this->save($op);
        $this->report($op, $user->id, ReportType::UNBLOCK, null, $note);
        $this->notifications->notifyUser($op->assigned_user_id, 'OPERATION_UNBLOCKED',
            "{$op->id} ({$op->name}) was unblocked.", $id);
    }

    public function cancel(string $id, ?string $reason, AppUser $user): void
    {
        $op = $this->find($id);
        if ($op->status->isTerminal()) {
            throw ApiException::conflict('Operation is already ' . $op->status->value);
        }
        if ($reason === null || trim($reason) === '') {
            throw ApiException::badRequest('A reason is required to cancel an operation');
        }
        $this->notifications->notifyUser($op->assigned_user_id, 'OPERATION_CANCELLED',
            "{$op->id} ({$op->name}) was cancelled: {$reason}", $id);
        $this->change($op, OperationStatus::CANCELLED, $user->id, $reason);
        $op->cancel_reason = trim($reason);
        $this->save($op);
        $this->report($op, $user->id, ReportType::CANCEL, null, $reason);
        $this->dependencies->evaluateAll($user->id);
    }

    /** Separate manager confirmation of a user-reported completion. */
    public function approve(string $id, ?string $note, AppUser $user): void
    {
        $op = $this->find($id);
        $this->require($op, OperationStatus::COMPLETED);
        if ($op->completion_approved) {
            throw ApiException::conflict('Completion is already approved');
        }
        $op->completion_approved = true;
        $op->completion_approved_by = $user->id;
        $this->save($op);
        $this->audit->record($user->id, 'COMPLETION_APPROVED', 'Operation', $id, false, true, $note);
        $this->report($op, $user->id, ReportType::APPROVE, null, $note);
        $this->dependencies->evaluateAll($user->id);
    }

    public function unassign(string $id, AppUser $user): void
    {
        $op = $this->find($id);
        $this->require($op, OperationStatus::ASSIGNED);
        $previous = $op->assigned_user_id;
        $this->change($op, OperationStatus::READY, $user->id, 'Unassigned');
        $op->assigned_user_id = null;
        $op->assigned_at = null;
        $this->save($op);
        $this->report($op, $user->id, ReportType::UNASSIGN, null, "Unassigned from {$previous}");
        $this->notifications->notifyUser($previous, 'OPERATION_UNASSIGNED', "{$op->id} was taken off your list.", $id);
    }

    /** Assigns a READY (or re-assigns an ASSIGNED) operation. Used by approved proposals and by manual assignment. */
    public function assign(string $id, string $userId, ?\DateTimeInterface $plannedStart, ?\DateTimeInterface $plannedEnd,
                           string $actorId, ?string $reason): void
    {
        $op = $this->find($id);
        if ($op->status !== OperationStatus::READY && $op->status !== OperationStatus::ASSIGNED) {
            throw ApiException::conflict("Operation {$id} is {$op->status->value} and cannot be assigned");
        }
        $target = AppUser::query()->find($userId);
        if ($target === null) {
            throw ApiException::badRequest("Unknown user {$userId}");
        }
        if (!$target->isActive() || !$target->user_role->isExecutor()) {
            throw ApiException::badRequest("User {$userId} is not an active executive user");
        }
        $previous = $op->assigned_user_id;
        if ($op->status !== OperationStatus::ASSIGNED) {
            $this->change($op, OperationStatus::ASSIGNED, $actorId, $reason);
        }
        $op->assigned_user_id = $userId;
        $op->assigned_at = CarbonImmutable::now();
        $op->planned_start = $plannedStart;
        $op->planned_end = $plannedEnd;
        $this->save($op);
        $this->audit->record($actorId, 'ASSIGN', 'Operation', $id, $previous, $userId, $reason);
        $this->report($op, $actorId, ReportType::ASSIGN, null,
            'Assigned to ' . $userId . ($reason === null ? '' : " - {$reason}"));
        $this->notifications->notifyUser($userId, 'OPERATION_ASSIGNED', "New task: {$op->id} ({$op->name})", $id);
        if ($previous !== null && $previous !== $userId) {
            $this->notifications->notifyUser($previous, 'OPERATION_UNASSIGNED', "{$op->id} was reassigned.", $id);
        }
    }

    /** New planned start/end (the baseline its executor works to) of an ASSIGNED operation, set by a manager FORCE. */
    public function reschedule(string $id, ?\DateTimeInterface $plannedStart, ?\DateTimeInterface $plannedEnd,
                               string $actorId, ?string $reason): void
    {
        $op = $this->find($id);
        $this->require($op, OperationStatus::ASSIGNED);
        $before = $op->planned_start;
        $op->planned_start = $plannedStart;
        $op->planned_end = $plannedEnd;
        $this->save($op);
        $this->audit->record($actorId, 'RESCHEDULE', 'Operation', $id, $before, $plannedStart, $reason);
        $this->notifications->notifyUser($op->assigned_user_id, 'OPERATION_RESCHEDULED',
            'Planned start of ' . $op->id . ' moved to ' . ForceService::tehranTime($plannedStart) . ' by a manager FORCE', $id);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    private function find(string $id): Operation
    {
        $op = Operation::query()->find($id);
        if ($op === null) {
            throw ApiException::notFound("Operation {$id} not found");
        }

        return $op;
    }

    private function ownTask(string $id, AppUser $user): Operation
    {
        $op = $this->find($id);
        if ($user->id !== $op->assigned_user_id) {
            throw ApiException::forbidden('This operation is not assigned to you');
        }

        return $op;
    }

    private function require(Operation $op, OperationStatus $expected): void
    {
        if ($op->status !== $expected) {
            throw ApiException::conflict("Operation {$op->id} is {$op->status->value}, expected {$expected->value}");
        }
    }

    private function change(Operation $op, OperationStatus $to, string $actor, ?string $reason): void
    {
        $from = $op->status;
        $op->status = $to;
        $this->audit->record($actor, 'STATUS_CHANGE', 'Operation', $op->id, $from, $to, $reason);
    }

    private function save(Operation $op): void
    {
        $op->updated_at = CarbonImmutable::now();
        $op->save();
    }

    private function report(Operation $op, string $userId, ReportType $type, ?int $percent, ?string $note): void
    {
        OperationReport::query()->create([
            'operation_id' => $op->id,
            'user_id' => $userId,
            'report_type' => $type,
            'progress_percent' => $percent,
            'note' => $note === null ? null : $this->lang->forStorage($note),
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}

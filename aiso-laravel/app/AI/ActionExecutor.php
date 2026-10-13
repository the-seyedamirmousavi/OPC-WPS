<?php

namespace App\AI;

use App\Enums\DepartmentPermission;
use App\Models\AppRole;
use App\Exceptions\ApiException;
use App\Models\AiPendingAction;
use App\Models\AiRun;
use App\Models\AppUser;
use App\Services\AuditService;
use App\Services\DepartmentService;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only way the AI changes anything, and only as a proposal. Each proposal is checked here (registry, parameters,
 * role, permission) and then follows the owner's policy for that action (ActionPolicy):
 *  - DENY: it is dropped; the assistant was not even told about the action.
 *  - AUTO: low risk only, runs at once.
 *  - SELF: waits as a pending action that only the person who asked can confirm, within a few minutes.
 *  - OWNER: waits for the owner. The person who asked cannot confirm it; the owner approves or rejects it, and the action
 *    then runs as the person who asked, so the services check that person's own rights. (When the owner is the one asking,
 *    this is the same as SELF: the owner's confirmation is the approval.)
 * Everything is checked again when the action finally runs. Nothing is ever deleted: the operations are changes of state.
 */
class ActionExecutor
{
    public function __construct(
        private readonly DepartmentRegistry $registry,
        private readonly ProtocolValidator $validator,
        private readonly DepartmentService $departments,
        private readonly AuditService $audit,
        private readonly ActionPolicy $policy,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param array<int, array> $actions validated by ProtocolValidator
     * @return array{pending: array<int, array>, executed: array<int, array>, dropped: string[]}
     */
    public function propose(AiRun $run, AppUser $user, array $actions): array
    {
        $pending = [];
        $executed = [];
        $dropped = [];
        foreach ($actions as $a) {
            $why = $this->mayDo($user, $a);
            if ($why !== null) {
                $dropped[] = "{$a['operation']}: {$why}";
                continue;
            }
            $mode = $this->modeOf($user, $a);
            if ($mode === ActionPolicy::DENY) {
                $dropped[] = "{$a['operation']}: the owner has not allowed the assistant to do this";
                continue;
            }
            if ($mode === ActionPolicy::AUTO) {
                $executed[] = ['operation' => $a['operation']] + $this->run($user, $a);
                continue;
            }

            $now = CarbonImmutable::now();
            $needsOwner = $mode === ActionPolicy::OWNER && $user->user_role !== AppRole::OWNER;
            $row = AiPendingAction::query()->create([
                'run_id' => $run->id, 'user_id' => $user->id, 'department' => $a['department'], 'operation' => $a['operation'],
                'params' => json_encode($a['params'], JSON_UNESCAPED_UNICODE), 'reason' => $a['reason'], 'risk' => $a['risk'],
                'status' => $needsOwner ? 'AWAITING_OWNER' : 'PENDING', 'approval' => $needsOwner ? 'OWNER' : 'SELF',
                'expires_at' => $needsOwner ? $now->addHours((int) config('aiso.agent.approval_ttl_hours')) : $now->addMinutes((int) config('aiso.agent.pending_ttl_minutes')),
                'created_at' => $now,
            ]);
            if ($needsOwner) {
                $this->tellOwners("{$user->full_name} asked the assistant for {$a['operation']}: it waits for your approval");
            }
            $pending[] = $this->view($row);
        }

        return ['pending' => $pending, 'executed' => $executed, 'dropped' => $dropped];
    }

    /** The requester confirms a SELF action. An action that needs the owner cannot be confirmed by the requester. */
    public function confirm(AppUser $user, int $id): array
    {
        $row = DB::transaction(function () use ($user, $id) {
            $row = AiPendingAction::query()->where('user_id', $user->id)->lockForUpdate()->find($id);
            if ($row === null) {
                throw ApiException::notFound("Action {$id} not found");
            }
            if ($row->status === 'AWAITING_OWNER') {
                throw ApiException::conflict("This action waits for the owner's approval");
            }
            if ($row->status !== 'PENDING') {
                throw ApiException::conflict('This action has already been decided');
            }
            if ($row->expires_at->isPast()) {
                $row->update(['status' => 'EXPIRED', 'decided_at' => CarbonImmutable::now()]);

                return $row;
            }
            $row->update(['status' => 'RUNNING']); // claimed: a second confirmation cannot start it again

            return $row;
        });
        if ($row->status === 'EXPIRED') {
            throw new ApiException(410, 'This action has expired. Ask again.');
        }

        return $this->runClaimed($row, $user, 'confirmed by the user', null);
    }

    /** The owner approves or rejects an action that waited for the owner. It runs as the person who asked. */
    public function ownerDecide(AppUser $owner, int $id, bool $approve, ?string $note): array
    {
        if ($owner->user_role !== AppRole::OWNER) {
            throw ApiException::forbidden('Only the owner approves this');
        }
        $row = DB::transaction(function () use ($owner, $id, $approve, $note) {
            $row = AiPendingAction::query()->lockForUpdate()->find($id);
            if ($row === null) {
                throw ApiException::notFound("Action {$id} not found");
            }
            if ($row->status !== 'AWAITING_OWNER') {
                throw ApiException::conflict('This action has already been decided');
            }
            if ($row->expires_at->isPast()) {
                $row->update(['status' => 'EXPIRED', 'decided_at' => CarbonImmutable::now()]);

                return $row;
            }
            if (!$approve) {
                $row->update(['status' => 'REJECTED', 'approved_by' => $owner->id, 'decision_note' => $note === null ? null : mb_substr(trim($note), 0, 500),
                    'decided_at' => CarbonImmutable::now()]);

                return $row;
            }
            $row->update(['status' => 'RUNNING', 'approved_by' => $owner->id, 'decision_note' => $note === null ? null : mb_substr(trim($note), 0, 500)]);

            return $row;
        });

        if ($row->status === 'EXPIRED') {
            throw new ApiException(410, 'This request has expired');
        }
        $requester = AppUser::query()->find($row->user_id);
        if ($row->status === 'REJECTED') {
            $this->audit->record($owner->id, 'AI_ACTION_REJECTED', 'AiAction', (string) $row->id, null, $row->operation . ' ' . $row->params, $row->decision_note);
            $this->tell($row->user_id, "The owner rejected your assistant request ({$row->operation})" . ($row->decision_note ? ": {$row->decision_note}" : ''));

            return $this->view($row);
        }

        if ($requester === null || !$requester->isActive()) {
            $row->update(['status' => 'FAILED', 'error' => 'The person who asked is not active any more', 'decided_at' => CarbonImmutable::now()]);
            throw ApiException::conflict('The person who asked is not active any more');
        }

        return $this->runClaimed($row, $requester, "approved by the owner {$owner->id}", $owner);
    }

    public function cancel(AppUser $user, int $id): array
    {
        $row = AiPendingAction::query()->where('user_id', $user->id)->find($id);
        if ($row === null) {
            throw ApiException::notFound("Action {$id} not found");
        }
        if (!in_array($row->status, ['PENDING', 'AWAITING_OWNER'], true)) {
            throw ApiException::conflict('This action has already been decided');
        }
        $row->update(['status' => 'CANCELLED', 'decided_at' => CarbonImmutable::now()]);

        return $this->view($row);
    }

    /** @return array<int, array> the requests waiting for the owner, oldest first */
    public function awaitingOwner(): array
    {
        AiPendingAction::query()->where('status', 'AWAITING_OWNER')->where('expires_at', '<', CarbonImmutable::now())
            ->update(['status' => 'EXPIRED', 'decided_at' => CarbonImmutable::now()]);
        $rows = AiPendingAction::query()->where('status', 'AWAITING_OWNER')->orderBy('id')->limit(100)->get();
        $names = AppUser::query()->whereIn('id', $rows->pluck('user_id'))->pluck('full_name', 'id');

        return $rows->map(fn (AiPendingAction $r) => $this->view($r) + ['requested_by' => $r->user_id, 'requested_by_name' => $names[$r->user_id] ?? $r->user_id])->values()->all();
    }

    /** Runs a claimed (RUNNING) action as $actor, after checking everything again. */
    private function runClaimed(AiPendingAction $row, AppUser $actor, string $how, ?AppUser $owner): array
    {
        $action = [
            'department' => $row->department, 'operation' => $row->operation,
            'params' => json_decode($row->params, true) ?? [], 'reason' => (string) $row->reason, 'risk' => $row->risk,
        ];
        try {
            // the proposal may be minutes (or hours) old: access and policy are checked again now
            $why = $this->mayDo($actor, $action);
            if ($why !== null) {
                throw ApiException::forbidden($why);
            }
            if ($this->modeOf($actor, $action) === ActionPolicy::DENY) {
                throw ApiException::forbidden('The owner no longer allows the assistant to do this');
            }
            $result = $this->run($actor, $action);
            $row->update(['status' => 'CONFIRMED', 'result' => json_encode($result, JSON_UNESCAPED_UNICODE), 'decided_at' => CarbonImmutable::now()]);
            $this->audit->record($actor->id, 'AI_ACTION_EXECUTED', 'AiAction', (string) $row->id, null,
                $row->operation . ' ' . $row->params, "AI assistant, {$how}");
            if ($owner !== null) {
                $this->tell($row->user_id, "The owner approved your assistant request ({$row->operation}) and it was done");
            }
        } catch (\Throwable $e) {
            $row->update(['status' => 'FAILED', 'error' => mb_substr($e->getMessage(), 0, 1000), 'decided_at' => CarbonImmutable::now()]);
            if ($owner !== null) {
                $this->tell($row->user_id, "The owner approved your assistant request ({$row->operation}) but it could not be done: " . mb_substr($e->getMessage(), 0, 200));
            }
            if (!$e instanceof ApiException) {
                Log::error('AI action failed: ' . $e->getMessage());
                throw new ApiException(500, 'The action could not be completed');
            }
            throw $e;
        }

        return $this->view($row->refresh());
    }

    /** The owner's mode for this action, for this person. */
    private function modeOf(AppUser $user, array $a): string
    {
        $entry = $this->registry->find($user, $a['department']);

        return $entry === null ? ActionPolicy::DENY : $this->policy->modeFor($entry['kind'], $a['operation'], $a['risk']);
    }

    /** Why this user may not do this action now, or null when they may. */
    private function mayDo(AppUser $user, array $a): ?string
    {
        $entry = $this->registry->find($user, $a['department']);
        if ($entry === null) {
            return 'the department is not available to you';
        }
        if ($this->validator->action($a, [$a['department'] => $entry], $why) === null) {
            return $why ?? 'invalid action';
        }
        $spec = config("aiso.agent.kinds.{$entry['kind']}.actions.{$a['operation']}");
        if (!$user->allows(IntentCatalog::actionCapability($entry['kind'], $spec))) {
            return 'your role does not allow this';
        }
        if (isset($spec['permission'])) {
            try {
                $this->departments->assertCan($user, $entry['department'], DepartmentPermission::from($spec['permission']));
            } catch (ApiException $e) {
                return $e->getMessage();
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function run(AppUser $user, array $a): array
    {
        $entry = $this->registry->find($user, $a['department']);

        return $this->registry->handler($entry['kind'])->execute($a['operation'], $a['params'], $user, $entry['department']);
    }

    private function tellOwners(string $message): void
    {
        foreach (AppUser::query()->where('user_role', AppRole::OWNER)->where('active', true)->pluck('id') as $id) {
            $this->tell($id, $message);
        }
    }

    private function tell(string $userId, string $message): void
    {
        $this->notifications->notifyUser($userId, 'AI_APPROVAL', $message, null);
    }

    /** What the page shows for a pending action. */
    public function view(AiPendingAction $row): array
    {
        return [
            'id' => $row->id, 'department' => $row->department, 'operation' => $row->operation,
            'params' => json_decode($row->params, true) ?? [], 'reason' => $row->reason, 'risk' => $row->risk,
            'status' => $row->status, 'approval' => $row->approval, 'error' => $row->error, 'note' => $row->decision_note,
            'expires_at' => $row->expires_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            // what the action did, once it ran (e.g. the new operation's id, who got it and when it is planned to finish)
            'result' => $row->result === null ? null : json_decode($row->result, true),
        ];
    }
}

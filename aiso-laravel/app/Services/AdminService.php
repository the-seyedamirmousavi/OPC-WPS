<?php

namespace App\Services;

use App\Enums\AssignmentMode;
use App\Enums\SystemStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\AssignmentProposal;
use App\Models\AssignmentRun;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Operation;
use App\Models\OperationReport;
use App\Models\PlanBaseline;
use App\Models\Predecessor;
use App\Models\Project;
use App\Models\ScheduleForce;
use App\Models\SystemSetting;
use App\Models\WorkResource;
use Illuminate\Support\Facades\DB;

/** Owner settings, system status, controlled reset. */
class AdminService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DependencyService $dependencies,
    ) {
    }

    public function view(): array
    {
        return self::toView(SystemSetting::current());
    }

    public function update(?string $projectName, ?string $messengerPlatform, ?int $maxActiveTasks, ?bool $requireApproval,
                           ?string $language, AppUser $actor): array
    {
        return DB::transaction(function () use ($projectName, $messengerPlatform, $maxActiveTasks, $requireApproval, $language, $actor) {
            $s = SystemSetting::current();
            $before = self::summary($s);
            $approvalChanged = false;
            if ($projectName !== null && trim($projectName) !== '') {
                $s->project_name = trim($projectName);
            }
            if ($messengerPlatform !== null && trim($messengerPlatform) !== '') {
                $s->messenger_platform = strtoupper(trim($messengerPlatform));
            }
            if ($language !== null && trim($language) !== '') {
                $l = strtolower(trim($language));
                if ($l !== 'fa' && $l !== 'en') {
                    throw ApiException::badRequest('language must be fa or en');
                }
                $s->language = $l;
            }
            if ($maxActiveTasks !== null) {
                if ($maxActiveTasks < 1 || $maxActiveTasks > 50) {
                    throw ApiException::badRequest('maxActiveTasksPerUser must be between 1 and 50');
                }
                $s->max_active_tasks_per_user = $maxActiveTasks;
            }
            if ($requireApproval !== null && $requireApproval !== (bool) $s->require_completion_approval) {
                $s->require_completion_approval = $requireApproval;
                $approvalChanged = true;
            }
            self::saveChanged($s);
            $this->audit->record($actor->id, 'SETTINGS_CHANGED', 'Settings', '1', $before, self::summary($s), null);
            if ($approvalChanged) {
                $this->dependencies->evaluateAll($actor->id);
            }

            return self::toView($s);
        });
    }

    public function setAssignmentMode(AssignmentMode $mode, AppUser $actor): array
    {
        return DB::transaction(function () use ($mode, $actor) {
            $s = SystemSetting::current();
            $before = $s->assignment_mode;
            if ($before !== $mode) {
                $s->assignment_mode = $mode;
                self::saveChanged($s);
                $this->audit->record($actor->id, 'ASSIGNMENT_MODE_CHANGED', 'Settings', '1', $before, $mode, null);
            }

            return self::toView($s);
        });
    }

    public function setSystemStatus(SystemStatus $status, ?string $reason, AppUser $actor): array
    {
        if ($reason === null || trim($reason) === '') {
            throw ApiException::badRequest('A reason is required');
        }

        return DB::transaction(function () use ($status, $reason, $actor) {
            $s = SystemSetting::current();
            $before = $s->system_status;
            if ($before !== $status) {
                $s->system_status = $status;
                self::saveChanged($s);
                $this->audit->record($actor->id, 'SYSTEM_STATUS_CHANGED', 'Settings', '1', $before, $status, $reason);
            }

            return self::toView($s);
        });
    }

    /**
     * Controlled reset: removes operational data (projects, operations, resources, items, predecessors, reports,
     * proposals, notifications, manager FORCEs, the reference plan). Users, settings and the audit trail are kept; the
     * reset itself is recorded with its reason.
     */
    public function reset(?string $reason, AppUser $actor): void
    {
        if ($reason === null || trim($reason) === '') {
            throw ApiException::badRequest('A reason is required for a reset');
        }
        DB::transaction(function () use ($reason, $actor) {
            foreach ([ScheduleForce::class, PlanBaseline::class, AssignmentProposal::class, AssignmentRun::class,
                OperationReport::class, Notification::class, Predecessor::class, Operation::class, Project::class,
                Item::class, WorkResource::class] as $model) {
                $model::query()->delete();
            }
            $s = SystemSetting::current();
            $before = $s->data_version;
            $s->data_version = '0';
            self::saveChanged($s);
            $this->audit->record($actor->id, 'SYSTEM_RESET', 'System', '1', $before, '0', $reason);
        });
    }

    /** Increments the configuration version and saves, as the Settings service did. */
    public static function saveChanged(SystemSetting $s): void
    {
        $s->configuration_version = (int) $s->configuration_version + 1;
        $s->updated_at = now();
        $s->save();
    }

    public static function toView(SystemSetting $s): array
    {
        $llm = config('aiso.llm');

        return [
            'projectId' => $s->project_id,
            'projectName' => $s->project_name,
            'ownerId' => $s->owner_id,
            'managerId' => $s->manager_id,
            'messengerPlatform' => $s->messenger_platform,
            'systemStatus' => $s->system_status->value,
            'dataVersion' => $s->data_version,
            'configurationVersion' => (int) $s->configuration_version,
            'assignmentMode' => $s->assignment_mode->value,
            'maxActiveTasksPerUser' => (int) $s->max_active_tasks_per_user,
            'requireCompletionApproval' => (bool) $s->require_completion_approval,
            'llmAvailable' => !empty($llm['api_key']) && !empty($llm['model']),
            'llmModel' => $llm['model'] ?: null,
            'language' => $s->language,
        ];
    }

    private static function summary(SystemSetting $s): string
    {
        return 'project=' . $s->project_name . ', messenger=' . $s->messenger_platform . ', maxActive='
            . $s->max_active_tasks_per_user . ', requireApproval=' . ($s->require_completion_approval ? 'true' : 'false')
            . ', language=' . $s->language;
    }
}

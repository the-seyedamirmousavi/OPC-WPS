<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Models\AppUser;
use App\Models\AssignmentProposal;
use App\Models\AuditEvent;
use App\Models\ImportLog;
use App\Models\Notification;
use App\Models\Operation;
use App\Models\OperationReport;
use Carbon\CarbonImmutable;

/**
 * The three dashboards (manager, owner, executive user) and the per-user performance report. Results are arrays
 * shaped for the JSON API.
 */
class DashboardService
{
    public function __construct(
        private readonly ProjectDataService $dataService,
        private readonly PlanService $planService,
        private readonly OperationViewService $viewService,
        private readonly SchedulingService $scheduling,
    ) {
    }

    // ---- manager ------------------------------------------------------------------------------------------------

    public function manager(): array
    {
        $data = $this->dataService->load();
        $plan = $this->planService->plan($data);
        $all = [];
        foreach ($data->ops as $o) {
            $all[] = $this->viewService->toView($o, $data, $plan);
        }
        $ready = array_values(array_filter($all, static fn (array $v) => $v['status'] === 'READY'));
        $blocked = array_values(array_filter($all, static fn (array $v) => $v['status'] === 'BLOCKED'));
        $delayed = array_values(array_filter($all, static fn (array $v) => $v['delayed']));
        $inProgress = array_values(array_filter($all, static fn (array $v) => $v['status'] === 'IN_PROGRESS'));
        $open = array_values(array_filter($all, static fn (array $v) => !OperationStatus::from($v['status'])->isTerminal()));
        usort($open, static fn (array $a, array $b): int => $b['tailHours'] <=> $a['tailHours']);
        $critical = array_slice($open, 0, 6);

        $resources = [];
        foreach ($data->resources as $r) {
            $busy = 0;
            $readyWaiting = 0;
            $notReady = 0;
            foreach ($data->ops as $o) {
                if ($o->resource_id !== $r->id) {
                    continue;
                }
                if ($o->status->isActive()) {
                    $busy++;
                } elseif ($o->status === OperationStatus::READY) {
                    $readyWaiting++;
                } elseif ($o->status === OperationStatus::NOT_READY) {
                    $notReady++;
                }
            }
            $resources[] = [
                'id' => $r->id,
                'name' => $r->name,
                'capacity' => (int) $r->capacity,
                'busy' => $busy,
                'readyWaiting' => $readyWaiting,
                'notReady' => $notReady,
                'active' => $r->isActive(),
            ];
        }

        $users = [];
        foreach ($data->users as $u) {
            if ($u->user_role->isExecutor()) {
                $users[] = $this->userLoad($u, $data);
            }
        }

        $recent = [];
        foreach (OperationReport::query()->orderByDesc('created_at')->orderByDesc('id')->limit(12)->get() as $r) {
            $recent[] = self::reportView($r, $data);
        }

        $s = $data->settings;

        return [
            'statusCounts' => self::statusCounts($data->ops),
            'progress' => self::progress($data, $plan),
            'assignmentMode' => $s->assignment_mode->value,
            'llmAvailable' => !empty(config('aiso.llm.api_key')) && !empty(config('aiso.llm.model')),
            'llmModel' => config('aiso.llm.model') ?: null,
            'pendingProposals' => AssignmentProposal::query()->where('status', 'PENDING')->count(),
            'ready' => $ready,
            'blocked' => $blocked,
            'delayed' => $delayed,
            'inProgress' => $inProgress,
            'criticalPath' => $critical,
            'resources' => $resources,
            'users' => $users,
            'recentReports' => $recent,
            'projects' => $this->scheduling->summaries($data, $plan),
            'schedule' => $this->scheduling->metrics($data, $plan),
            'naiveSchedule' => $this->scheduling->metrics($data, $this->planService->planNaive($data, null)),
        ];
    }

    // ---- owner --------------------------------------------------------------------------------------------------

    public function owner(): array
    {
        $data = $this->dataService->load();
        $plan = $this->planService->plan($data);
        $s = $data->settings;
        $byRole = [];
        foreach (Role::cases() as $r) {
            $byRole[$r->value] = 0;
        }
        $active = 0;
        foreach ($data->users as $u) {
            $byRole[$u->user_role->value]++;
            if ($u->isActive()) {
                $active++;
            }
        }
        $imports = [];
        foreach (ImportLog::query()->orderByDesc('imported_at')->orderByDesc('id')->limit(5)->get() as $l) {
            $imports[] = [
                'id' => $l->id,
                'importedAt' => self::iso($l->imported_at),
                'actorId' => $l->actor_id,
                'fileName' => $l->file_name,
                'format' => $l->file_format,
                'status' => $l->status,
                'errorCount' => (int) $l->error_count,
                'summary' => $l->summary,
            ];
        }
        $audit = [];
        foreach (AuditEvent::query()->orderByDesc('occurred_at')->limit(10)->get() as $e) {
            $audit[] = self::auditView($e);
        }

        return [
            'projectId' => $s->project_id,
            'projectName' => $s->project_name,
            'systemStatus' => $s->system_status->value,
            'dataVersion' => $s->data_version,
            'configurationVersion' => (int) $s->configuration_version,
            'assignmentMode' => $s->assignment_mode->value,
            'llmAvailable' => !empty(config('aiso.llm.api_key')) && !empty(config('aiso.llm.model')),
            'llmModel' => config('aiso.llm.model') ?: null,
            'messengerPlatform' => $s->messenger_platform,
            'requireCompletionApproval' => (bool) $s->require_completion_approval,
            'maxActiveTasksPerUser' => (int) $s->max_active_tasks_per_user,
            'usersByRole' => $byRole,
            'activeUsers' => $active,
            'statusCounts' => self::statusCounts($data->ops),
            'progress' => self::progress($data, $plan),
            'failedNotifications' => Notification::query()->where('delivery_status', 'FAILED')->count(),
            'recentImports' => $imports,
            'recentAudit' => $audit,
        ];
    }

    // ---- executive user -----------------------------------------------------------------------------------------

    public function user(string $userId): array
    {
        $data = $this->dataService->load();
        $plan = $this->planService->plan($data);
        $mine = array_values(array_filter($data->ops, static fn (Operation $o) => $o->assigned_user_id === $userId));

        $active = [];
        foreach ($mine as $o) {
            if (!$o->status->isTerminal()) {
                $active[] = $this->viewService->toView($o, $data, $plan);
            }
        }
        usort($active, static function (array $a, array $b): int {
            $ka = $a['status'] === 'IN_PROGRESS' ? 0 : 1;
            $kb = $b['status'] === 'IN_PROGRESS' ? 0 : 1;

            return ($ka <=> $kb) ?: strcmp($a['plannedEnd'] ?? '9999', $b['plannedEnd'] ?? '9999');
        });

        $done = array_values(array_filter($mine, static fn (Operation $o) => $o->status === OperationStatus::COMPLETED));
        usort($done, static fn (Operation $a, Operation $b): int => self::nullsLastDesc($a->completed_at, $b->completed_at));
        $recentDone = [];
        foreach (array_slice($done, 0, 8) as $o) {
            $recentDone[] = $this->viewService->toView($o, $data, $plan);
        }

        $notes = [];
        foreach (Notification::query()->where('recipient_id', $userId)->orderByDesc('created_at')->orderByDesc('id')->limit(15)->get() as $n) {
            $notes[] = self::notificationView($n);
        }

        return [
            'userId' => $userId,
            'name' => $data->userName($userId),
            'statusCounts' => self::statusCounts($mine),
            'activeTasks' => $active,
            'recentCompleted' => $recentDone,
            'performance' => $this->performance($userId, $data),
            'unreadNotifications' => Notification::query()->where('recipient_id', $userId)->whereNull('read_at')->count(),
            'notifications' => $notes,
        ];
    }

    // ---- reports ------------------------------------------------------------------------------------------------

    /** @return array[] */
    public function performanceAll(): array
    {
        $data = $this->dataService->load();
        $out = [];
        foreach ($data->users as $u) {
            if ($u->user_role->isExecutor()) {
                $out[] = $this->performance($u->id, $data);
            }
        }

        return $out;
    }

    private function performance(string $userId, ProjectData $data): array
    {
        $assigned = 0;
        $inProgress = 0;
        $blocked = 0;
        $completed = 0;
        $late = 0;
        $withDue = 0;
        $onTime = 0;
        $completedHours = 0.0;
        $actual = 0.0;
        $planned = 0.0;
        foreach ($data->ops as $o) {
            if ($o->assigned_user_id !== $userId) {
                continue;
            }
            switch ($o->status) {
                case OperationStatus::ASSIGNED:
                    $assigned++;
                    break;
                case OperationStatus::IN_PROGRESS:
                    $inProgress++;
                    break;
                case OperationStatus::BLOCKED:
                    $blocked++;
                    break;
                case OperationStatus::COMPLETED:
                    $completed++;
                    $completedHours += $o->totalHours();
                    if ($o->planned_end !== null && $o->completed_at !== null) {
                        $withDue++;
                        if ($o->completed_at->greaterThan($o->planned_end)) {
                            $late++;
                        } else {
                            $onTime++;
                        }
                    }
                    if ($o->started_at !== null && $o->completed_at !== null && $o->totalHours() > 0) {
                        $actual += ($o->completed_at->getTimestamp() - $o->started_at->getTimestamp()) / 3600.0;
                        $planned += $o->totalHours();
                    }
                    break;
                default:
                    break;
            }
        }
        $blockReports = OperationReport::query()->where('user_id', $userId)
            ->where('report_type', ReportType::BLOCK->value)->count();

        return [
            'userId' => $userId,
            'name' => $data->userName($userId),
            'assigned' => $assigned,
            'inProgress' => $inProgress,
            'blocked' => $blocked,
            'completed' => $completed,
            'completedHours' => $completedHours,
            'onTimeRate' => $withDue === 0 ? null : $onTime / $withDue,
            'actualToPlanned' => $planned == 0.0 ? null : $actual / $planned,
            'lateCount' => $late,
            'blockReports' => $blockReports,
        ];
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    private function userLoad(AppUser $u, ProjectData $data): array
    {
        $assigned = 0;
        $inProgress = 0;
        $blocked = 0;
        $completed = 0;
        $load = 0.0;
        $doneHours = 0.0;
        foreach ($data->ops as $o) {
            if ($o->assigned_user_id !== $u->id) {
                continue;
            }
            switch ($o->status) {
                case OperationStatus::ASSIGNED:
                    $assigned++;
                    $load += $o->totalHours();
                    break;
                case OperationStatus::IN_PROGRESS:
                    $inProgress++;
                    $load += $o->totalHours() * (1 - $o->progress_percent / 100.0);
                    break;
                case OperationStatus::BLOCKED:
                    $blocked++;
                    break;
                case OperationStatus::COMPLETED:
                    $completed++;
                    $doneHours += $o->totalHours();
                    break;
                default:
                    break;
            }
        }

        return [
            'userId' => $u->id,
            'name' => $u->full_name,
            'active' => $u->isActive(),
            'assigned' => $assigned,
            'inProgress' => $inProgress,
            'blocked' => $blocked,
            'loadHours' => $load,
            'completed' => $completed,
            'completedHours' => $doneHours,
        ];
    }

    /** @param iterable<Operation> $ops */
    public static function statusCounts(iterable $ops): array
    {
        $m = [];
        foreach (OperationStatus::cases() as $s) {
            $m[$s->value] = 0;
        }
        foreach ($ops as $o) {
            $m[$o->status->value]++;
        }

        return $m;
    }

    private static function progress(ProjectData $data, PlanView $plan): array
    {
        $total = 0.0;
        $done = 0.0;
        $count = 0;
        foreach ($data->ops as $o) {
            if ($o->status === OperationStatus::CANCELLED) {
                continue;
            }
            $count++;
            $total += $o->totalHours();
            if ($o->status === OperationStatus::COMPLETED) {
                $done += $o->totalHours();
            }
        }

        return [
            'totalOperations' => $count,
            'totalHours' => $total,
            'completedHours' => $done,
            'progressPercent' => $total == 0.0 ? 0.0 : $done / $total * 100.0,
            'projectedEnd' => $plan->projectEnd() === null ? null : self::iso($plan->projectEnd()),
            'remainingCriticalHours' => $plan->makespanHours,
        ];
    }

    private static function reportView(OperationReport $r, ProjectData $data): array
    {
        return [
            'id' => $r->id,
            'userId' => $r->user_id,
            'userName' => $data->userName($r->user_id),
            'type' => $r->report_type->value,
            'progressPercent' => $r->progress_percent,
            'note' => $r->note,
            'createdAt' => self::iso($r->created_at),
        ];
    }

    public static function auditView(AuditEvent $e): array
    {
        return [
            'eventId' => $e->event_id,
            'occurredAt' => self::iso($e->occurred_at),
            'actorId' => $e->actor_id,
            'action' => $e->action_name,
            'entityType' => $e->entity_type,
            'entityId' => $e->entity_id,
            'previousValue' => $e->previous_value,
            'newValue' => $e->new_value,
            'reason' => $e->reason,
        ];
    }

    public static function notificationView(Notification $n): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->kind,
            'message' => $n->message,
            'relatedOperationId' => $n->related_operation_id,
            'createdAt' => self::iso($n->created_at),
            'readAt' => self::iso($n->read_at),
            'deliveryStatus' => $n->delivery_status,
            'deliveryError' => $n->delivery_error,
        ];
    }

    private static function nullsLastDesc(?\DateTimeInterface $a, ?\DateTimeInterface $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
        }

        return $b->getTimestamp() <=> $a->getTimestamp();
    }

    public static function iso(?\DateTimeInterface $t): ?string
    {
        return $t === null ? null : CarbonImmutable::instance($t)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}

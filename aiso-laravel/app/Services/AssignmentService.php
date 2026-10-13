<?php

namespace App\Services;

use App\Assignment\AlgorithmStrategy;
use App\Assignment\AssignmentContext;
use App\Assignment\AssignmentState;
use App\Assignment\LlmStrategy;
use App\Assignment\LlmUnavailableException;
use App\Assignment\ReadyOp;
use App\Assignment\ResourceInfo;
use App\Assignment\Suggestion;
use App\Assignment\UserInfo;
use App\Enums\AssignmentMode;
use App\Enums\OperationStatus;
use App\Enums\ProposalStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\AssignmentProposal;
use App\Models\AssignmentRun;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates task assignment. The active mode picks the strategy; the result is always a set of PENDING proposals
 * that a manager reviews and approves. Nothing is assigned automatically.
 */
class AssignmentService
{
    public function __construct(
        private readonly ProjectDataService $dataService,
        private readonly PlanService $planService,
        private readonly OperationService $operationService,
        private readonly AuditService $audit,
        private readonly LanguageService $lang,
        private readonly LicenseService $license,
    ) {
    }

    /**
     * Generates proposals with the requested mode (or the configured default). The LLM call is made outside the
     * database transaction, so it does not hold a connection while it waits.
     */
    public function suggest(?AssignmentMode $requested, AppUser $actor): array
    {
        $data = $this->dataService->load();
        $mode = $requested ?? $data->settings->assignment_mode;
        $plan = $this->planService->plan($data);
        $ctx = $this->buildContext($data, $plan);
        $started = hrtime(true);
        $effective = $mode;
        $fallback = false;
        $warnings = [];

        if ($mode === AssignmentMode::LLM && !$this->license->llmAllowed()) {
            $warnings[] = 'LLM mode is not included in this license. The algorithm produced these proposals instead.';
            $suggestion = app(AlgorithmStrategy::class)->suggest($ctx);
            $effective = AssignmentMode::ALGORITHM;
            $fallback = true;
        } elseif ($mode === AssignmentMode::LLM) {
            try {
                $suggestion = app(LlmStrategy::class)->suggest($ctx);
            } catch (LlmUnavailableException $e) {
                if (!config('aiso.llm.fallback_to_algorithm')) {
                    throw new ApiException(502, 'LLM assignment failed: ' . $e->getMessage());
                }
                $warnings[] = "LLM mode failed ({$e->getMessage()}). The algorithm produced these proposals instead.";
                $suggestion = app(AlgorithmStrategy::class)->suggest($ctx);
                $effective = AssignmentMode::ALGORITHM;
                $fallback = true;
            }
        } else {
            $suggestion = app(AlgorithmStrategy::class)->suggest($ctx);
        }
        $warnings = array_merge($warnings, $suggestion->warnings);
        $elapsed = intdiv(hrtime(true) - $started, 1_000_000);

        return DB::transaction(fn () => $this->persist($data, $plan, $mode, $effective, $fallback, $suggestion, $warnings, $elapsed, $actor));
    }

    private function persist(ProjectData $data, PlanView $plan, AssignmentMode $requested, AssignmentMode $effective,
                             bool $fallback, Suggestion $s, array $warnings, int $elapsed, AppUser $actor): array
    {
        foreach (AssignmentProposal::query()->where('status', ProposalStatus::PENDING->value)->get() as $old) {
            $old->status = ProposalStatus::SUPERSEDED;
            $old->decided_at = CarbonImmutable::now();
            $old->save();
        }
        $run = AssignmentRun::query()->create([
            'id' => (string) Str::uuid(),
            'requested_mode' => $requested,
            'effective_mode' => $effective,
            'fallback' => $fallback,
            'model' => $effective === AssignmentMode::LLM ? $s->model : null,
            'summary' => self::clip($this->lang->forStorage($s->summary), 2000),
            'warnings' => self::clip(implode("\n", array_map(fn ($w) => $this->lang->forStorage($w), $warnings)), 4000),
            'unassigned' => self::clip(implode("\n", array_map(
                fn (\App\Assignment\Skip $k) => $k->operationId . '|' . $this->lang->forStorage($k->reason), $s->skips)), 4000),
            'requested_by' => $actor->id,
            'created_at' => CarbonImmutable::now(),
            'duration_ms' => $elapsed,
            'input_tokens' => $s->inputTokens,
            'output_tokens' => $s->outputTokens,
        ]);

        foreach ($s->picks as $p) {
            AssignmentProposal::query()->create([
                'run_id' => $run->id,
                'operation_id' => $p->operationId,
                'user_id' => $p->userId,
                'source' => $effective,
                'reason' => self::clip($this->lang->forStorage($p->reason), 2000),
                'planned_start' => $plan->start($p->operationId),
                'planned_end' => $plan->end($p->operationId),
                'status' => ProposalStatus::PENDING,
                'created_at' => CarbonImmutable::now(),
            ]);
        }
        $this->audit->record($actor->id, 'ASSIGNMENT_SUGGESTED', 'AssignmentRun', $run->id, $requested, $effective,
            count($s->picks) . ' proposals' . ($fallback ? ' (LLM fallback to algorithm)' : ''));

        return $this->runView($run, $data);
    }

    /** Latest run with its proposals, or null if none yet. */
    public function latest(): ?array
    {
        $run = AssignmentRun::query()->orderByDesc('created_at')->first();

        return $run === null ? null : $this->runView($run, $this->dataService->load());
    }

    /** @return array[] */
    public function history(int $limit): array
    {
        $data = $this->dataService->load();

        return AssignmentRun::query()->orderByDesc('created_at')->limit(min($limit, 50))->get()
            ->map(fn (AssignmentRun $r) => $this->runView($r, $data))->all();
    }

    /** Applies PENDING proposals after re-checking them against the current state of the project. */
    public function approve(array $ids, AppUser $actor): array
    {
        return DB::transaction(function () use ($ids, $actor) {
            $data = $this->dataService->load();
            $plan = $this->planService->plan($data);
            $state = new AssignmentState($this->buildContext($data, $plan));
            $out = [];
            foreach (AssignmentProposal::query()->whereIn('id', $ids)->get() as $p) {
                if ($p->status !== ProposalStatus::PENDING) {
                    continue;
                }
                $op = $data->ops[$p->operation_id] ?? null;
                $problem = $op === null || $op->status !== OperationStatus::READY
                    ? 'Operation is no longer READY'
                    : $state->reject($p->operation_id, $p->user_id);
                if ($problem !== null) {
                    $this->decide($p, ProposalStatus::REJECTED, $actor, "Not applied: {$problem}");
                } else {
                    $this->operationService->assign($p->operation_id, $p->user_id, $p->planned_start, $p->planned_end,
                        $actor->id, "Approved {$p->source->value} proposal: {$p->reason}");
                    $state->apply($p->operation_id, $p->user_id);
                    $this->decide($p, ProposalStatus::APPROVED, $actor, null);
                }
                $out[] = $this->view($p, $data, $plan);
            }

            return $out;
        });
    }

    public function reject(array $ids, ?string $note, AppUser $actor): array
    {
        return DB::transaction(function () use ($ids, $note, $actor) {
            $data = $this->dataService->load();
            $plan = $this->planService->plan($data);
            $out = [];
            foreach (AssignmentProposal::query()->whereIn('id', $ids)->get() as $p) {
                if ($p->status === ProposalStatus::PENDING) {
                    $this->decide($p, ProposalStatus::REJECTED, $actor, $note === null || trim($note) === '' ? 'Rejected by manager' : $note);
                    $out[] = $this->view($p, $data, $plan);
                }
            }

            return $out;
        });
    }

    /** Manager override: assign a specific user regardless of what was proposed. */
    public function manualAssign(string $operationId, string $userId, ?string $note, AppUser $actor): void
    {
        DB::transaction(function () use ($operationId, $userId, $note, $actor) {
            $data = $this->dataService->load();
            $force = $data->forceOf($operationId);
            if ($force !== null && $force->forced_user_id !== null && $force->forced_user_id !== $userId) {
                throw ApiException::conflict("Operation {$operationId} is forced to executor {$force->forced_user_id} by the manager. Change or remove the FORCE first.");
            }
            $plan = $this->planService->plan($data);
            $this->operationService->assign($operationId, $userId, $plan->start($operationId), $plan->end($operationId),
                $actor->id, 'Manual assignment' . (($note === null || trim($note) === '') ? '' : ": {$note}"));
            foreach (AssignmentProposal::query()->where('status', ProposalStatus::PENDING->value)->get() as $p) {
                if ($p->operation_id === $operationId) {
                    $this->decide($p, ProposalStatus::SUPERSEDED, $actor, 'Manually assigned');
                }
            }
        });
    }

    // ---- context & views ----------------------------------------------------------------------------------------

    public function buildContext(ProjectData $data, PlanView $plan): AssignmentContext
    {
        $used = [];
        $tasks = [];
        $load = [];
        foreach ($data->ops as $o) {
            if ($o->status->isActive()) {
                $used[$o->resource_id] = ($used[$o->resource_id] ?? 0) + 1;
                if ($o->assigned_user_id !== null) {
                    $tasks[$o->assigned_user_id] = ($tasks[$o->assigned_user_id] ?? 0) + 1;
                    $remaining = $o->totalHours() * (1 - $o->progress_percent / 100.0);
                    $load[$o->assigned_user_id] = ($load[$o->assigned_user_id] ?? 0.0) + $remaining;
                }
            }
        }

        $ready = [];
        foreach ($data->ops as $o) {
            if ($o->status !== OperationStatus::READY) {
                continue;
            }
            $force = $data->forceOf($o->id);
            $forcedUser = $force !== null && $force->forced_user_id !== null;
            $item = $o->item_id === null ? null : ($data->items[$o->item_id] ?? null);
            $ready[] = new ReadyOp(
                $o->id,
                $o->name,
                $item?->name,
                $o->resource_id,
                $data->resourceName($o->resource_id),
                $o->totalHours(),
                $plan->tailHours[$o->id] ?? $o->totalHours(),
                $forcedUser ? $force->forced_user_id : $o->responsible_user_id,
                $o->project_id,
                $data->projectName($o->project_id),
                $data->rankOf($o),
                $plan->startHours($o->id),
                $forcedUser,
            );
        }

        $users = [];
        foreach ($data->users as $u) {
            if ($u->receivesTasks()) {
                $users[] = new UserInfo($u->id, $u->full_name, $u->isActive(), $tasks[$u->id] ?? 0, $load[$u->id] ?? 0.0);
            }
        }

        $resources = [];
        foreach ($data->resources as $r) {
            $resources[] = new ResourceInfo($r->id, $r->name, (int) $r->capacity, $used[$r->id] ?? 0,
                $r->responsible_user_id, $r->isActive());
        }

        return new AssignmentContext($ready, $users, $resources, (int) $data->settings->max_active_tasks_per_user,
            (float) config('aiso.assignment.responsible_bonus_hours'));
    }

    private function runView(AssignmentRun $run, ProjectData $data): array
    {
        $plan = $this->planService->plan($data);
        $proposals = AssignmentProposal::query()->where('run_id', $run->id)->orderBy('id')->get();
        $skipped = [];
        if ($run->unassigned !== null && trim($run->unassigned) !== '') {
            foreach (explode("\n", $run->unassigned) as $line) {
                $i = strpos($line, '|');
                if ($i !== false && $i > 0) {
                    $id = substr($line, 0, $i);
                    $o = $data->ops[$id] ?? null;
                    $skipped[] = ['operationId' => $id, 'operationName' => $o === null ? $id : $o->name,
                        'reason' => substr($line, $i + 1)];
                }
            }
        }
        $warnings = $run->warnings === null || trim($run->warnings) === '' ? [] : explode("\n", $run->warnings);

        return [
            'id' => $run->id,
            'requestedMode' => $run->requested_mode->value,
            'effectiveMode' => $run->effective_mode->value,
            'fallback' => (bool) $run->fallback,
            'model' => $run->model,
            'summary' => $run->summary,
            'warnings' => $warnings,
            'unassigned' => $skipped,
            'proposals' => $proposals->map(fn (AssignmentProposal $p) => $this->view($p, $data, $plan))->values()->all(),
            'createdAt' => $run->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'durationMs' => (int) $run->duration_ms,
            'inputTokens' => $run->input_tokens,
            'outputTokens' => $run->output_tokens,
            'requestedBy' => $run->requested_by,
        ];
    }

    private function view(AssignmentProposal $p, ProjectData $data, PlanView $plan): array
    {
        $o = $data->ops[$p->operation_id] ?? null;
        $u = $data->users[$p->user_id] ?? null;

        return [
            'id' => $p->id,
            'runId' => $p->run_id,
            'operationId' => $p->operation_id,
            'operationName' => $o?->name,
            'projectName' => $o === null ? null : $data->projectName($o->project_id),
            'projectRank' => $o === null ? 0 : $data->rankOf($o),
            'resourceName' => $o === null ? null : $data->resourceName($o->resource_id),
            'userId' => $p->user_id,
            'userName' => $u === null ? $p->user_id : $u->full_name,
            'source' => $p->source->value,
            'reason' => $p->reason,
            'plannedStart' => $p->planned_start?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'plannedEnd' => $p->planned_end?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'status' => $p->status->value,
            'decisionNote' => $p->decision_note,
            'hours' => $o === null ? 0.0 : $o->totalHours(),
            'tailHours' => $plan->tailHours[$p->operation_id] ?? 0.0,
        ];
    }

    private function decide(AssignmentProposal $p, ProposalStatus $status, AppUser $actor, ?string $note): void
    {
        $p->status = $status;
        $p->decided_at = CarbonImmutable::now();
        $p->decided_by = $actor->id;
        $p->decision_note = self::clip($note, 500);
        $p->save();
    }

    private static function clip(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }

        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 3) . '...';
    }
}

<?php

namespace App\Services;

use App\Enums\OperationStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Models\Project;

/**
 * Measures schedules (finish per project, wasted resource time) and previews the effect of a ranking. Results are
 * arrays shaped for the JSON API.
 */
class SchedulingService
{
    public function __construct(
        private readonly ProjectDataService $dataService,
        private readonly PlanService $planService,
    ) {
    }

    public function overview(): array
    {
        $data = $this->dataService->load();
        $optimized = $this->planService->plan($data);
        $naive = $this->planService->planNaive($data, null);

        return [
            'optimized' => $this->metrics($data, $optimized),
            'naive' => $this->metrics($data, $naive),
            'projects' => $this->summaries($data, $optimized),
        ];
    }

    /** What would happen to every project if the active projects were ranked in this order? Nothing is stored. */
    public function previewRanking(array $ranking): array
    {
        $data = $this->dataService->load();
        $ranks = self::ranks($ranking, self::activeIds($data));

        return $this->impact($data, $data, $ranks);
    }

    /**
     * @param ProjectData $current        data as it is now (ranks as stored)
     * @param ProjectData $proposed       data after the change (e.g. after an import), scheduled with $proposedRanks
     * @param array<string, int> $proposedRanks
     */
    public function impact(ProjectData $current, ProjectData $proposed, array $proposedRanks): array
    {
        $now = $this->metrics($current, $this->planService->plan($current));
        $optimized = $this->metrics($proposed, $this->planService->plan($proposed, $proposedRanks));
        $naive = $this->metrics($proposed, $this->planService->planNaive($proposed, $proposedRanks));

        $before = [];
        foreach ($now['projects'] as $p) {
            $before[$p['projectId']] = $p;
        }
        $deltas = [];
        foreach ($optimized['projects'] as $p) {
            $old = $before[$p['projectId']] ?? null;
            $deltas[] = [
                'projectId' => $p['projectId'],
                'name' => $p['name'],
                'currentRank' => $old['rank'] ?? null,
                'proposedRank' => $p['rank'],
                'currentFinishHours' => $old['finishHours'] ?? null,
                'proposedFinishHours' => $p['finishHours'],
                'deltaHours' => $old === null ? null : $p['finishHours'] - $old['finishHours'],
            ];
        }

        return [
            'current' => $now,
            'proposed' => $optimized,
            'naive' => $naive,
            'deltas' => $deltas,
            'wasteSavedVsNaive' => $naive['wasteHours'] - $optimized['wasteHours'],
            'makespanSavedVsNaive' => $naive['makespanHours'] - $optimized['makespanHours'],
        ];
    }

    // ---- metrics ------------------------------------------------------------------------------------------------

    public function metrics(ProjectData $data, PlanView $view): array
    {
        $byProject = [];
        foreach ($view->tasks as $t) {
            $byProject[$t->projectId][] = $t;
        }
        $active = array_values(array_filter($data->projects, static fn (Project $p) => $p->status === ProjectStatus::ACTIVE));
        usort($active, static fn (Project $a, Project $b): int => ($a->priority <=> $b->priority) ?: strcmp($a->id, $b->id));

        $plans = [];
        foreach ($active as $p) {
            $tasks = $byProject[$p->id] ?? [];
            $start = PHP_FLOAT_MAX;
            $finish = 0.0;
            foreach ($tasks as $t) {
                $s = $view->slots[$t->id] ?? null;
                if ($s !== null) {
                    $start = min($start, $s->start);
                    $finish = max($finish, $s->end);
                }
            }
            $tardiness = 0.0;
            if ($p->due_date !== null && $tasks !== []) {
                $due = PlanService::hoursFrom($view->now, $p->due_date);
                $tardiness = max(0.0, $finish - $due);
            }
            $rank = $tasks === [] ? (int) $p->priority : $tasks[0]->projectRank;
            $plans[] = [
                'projectId' => $p->id,
                'name' => $p->name,
                'rank' => $rank,
                'openOperations' => count($tasks),
                'startHours' => $start === PHP_FLOAT_MAX ? 0.0 : $start,
                'finishHours' => $finish,
                'finishAt' => $tasks === [] ? null : self::iso($view->at($finish)),
                'dueDate' => self::iso($p->due_date),
                'tardinessHours' => $tardiness,
                'rule' => $view->rules[$p->id] ?? null,
            ];
        }
        usort($plans, static fn (array $a, array $b): int => ($a['rank'] <=> $b['rank']) ?: strcmp($a['projectId'], $b['projectId']));

        $busyAll = 0.0;
        $wasteAll = 0.0;
        $resources = [];
        $lanes = $view->lanes->all();
        ksort($lanes, SORT_STRING);
        foreach ($lanes as $resourceId => $resourceLanes) {
            $busy = 0.0;
            $waste = 0.0;
            foreach ($resourceLanes as $lane) {
                $end = 0.0;
                $b = 0.0;
                foreach ($lane as $iv) {
                    $b += $iv[1] - $iv[0];
                    $end = max($end, $iv[1]);
                }
                $busy += $b;
                $waste += $end - $b;
            }
            if ($busy <= 0 && $waste <= 0) {
                continue;
            }
            $r = $data->resources[$resourceId] ?? null;
            $resources[] = [
                'resourceId' => (string) $resourceId,
                'name' => $r === null ? (string) $resourceId : $r->name,
                'capacity' => $r === null ? count($resourceLanes) : (int) $r->capacity,
                'busyHours' => $busy,
                'wasteHours' => $waste,
                'utilizationPercent' => self::percent($busy, $busy + $waste),
            ];
            $busyAll += $busy;
            $wasteAll += $waste;
        }

        return [
            'makespanHours' => $view->makespanHours,
            'finishAt' => $view->slots === [] ? null : self::iso($view->projectEnd()),
            'busyHours' => $busyAll,
            'wasteHours' => $wasteAll,
            'utilizationPercent' => self::percent($busyAll, $busyAll + $wasteAll),
            'projects' => $plans,
            'resources' => $resources,
            'candidatesTried' => $view->candidates,
        ];
    }

    private static function percent(float $part, float $whole): float
    {
        return $whole <= 0 ? 100.0 : $part / $whole * 100.0;
    }

    // ---- project summaries --------------------------------------------------------------------------------------

    /** @return array[] */
    public function summaries(ProjectData $data, PlanView $view): array
    {
        $m = $this->metrics($data, $view);
        $plans = [];
        foreach ($m['projects'] as $p) {
            $plans[$p['projectId']] = $p;
        }
        $out = [];
        foreach ($data->projects as $p) {
            $counts = [];
            foreach (OperationStatus::cases() as $s) {
                $counts[$s->value] = 0;
            }
            $total = 0.0;
            $done = 0.0;
            $ops = 0;
            foreach ($data->ops as $o) {
                if ($o->project_id !== $p->id) {
                    continue;
                }
                $ops++;
                $counts[$o->status->value]++;
                if ($o->status !== OperationStatus::CANCELLED) {
                    $total += $o->totalHours();
                    if ($o->status === OperationStatus::COMPLETED) {
                        $done += $o->totalHours();
                    }
                }
            }
            $plan = $plans[$p->id] ?? null;
            $out[] = [
                'id' => $p->id,
                'name' => $p->name,
                'priority' => (int) $p->priority,
                'status' => $p->status->value,
                'dueDate' => self::iso($p->due_date),
                'createdAt' => self::iso($p->created_at),
                'totalOperations' => $ops,
                'statusCounts' => $counts,
                'totalHours' => $total,
                'completedHours' => $done,
                'progressPercent' => $total === 0.0 ? 0.0 : $done / $total * 100.0,
                'openOperations' => $plan === null ? 0 : $plan['openOperations'],
                'finishHours' => $plan === null ? 0.0 : $plan['finishHours'],
                'finishAt' => $plan === null ? null : $plan['finishAt'],
                'tardinessHours' => $plan === null ? 0.0 : $plan['tardinessHours'],
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $sa = $a['status'] === 'ACTIVE' ? 0 : 1;
            $sb = $b['status'] === 'ACTIVE' ? 0 : 1;

            return ($sa <=> $sb) ?: ($a['priority'] <=> $b['priority']) ?: strcmp($a['id'], $b['id']);
        });

        return $out;
    }

    // ---- ranking helpers (shared with ProjectService and the import preview) ------------------------------------

    /** @return string[] */
    public static function activeIds(ProjectData $data): array
    {
        $active = array_values(array_filter($data->projects, static fn (Project $p) => $p->status === ProjectStatus::ACTIVE));
        usort($active, static fn (Project $a, Project $b): int => ($a->priority <=> $b->priority) ?: strcmp($a->id, $b->id));

        return array_map(static fn (Project $p) => $p->id, $active);
    }

    /**
     * Validates that $ranking lists every expected project exactly once and returns id => rank (1-based).
     *
     * @param string[] $ranking
     * @param string[] $expected
     * @return array<string, int>
     */
    public static function ranks(array $ranking, array $expected): array
    {
        if ($ranking === []) {
            throw ApiException::badRequest('A ranking is required');
        }
        $want = array_fill_keys($expected, true);
        $seen = [];
        foreach ($ranking as $id) {
            if (isset($seen[$id])) {
                throw ApiException::badRequest("Project {$id} appears twice in the ranking");
            }
            if (!isset($want[$id])) {
                throw ApiException::badRequest("Unknown or inactive project {$id} in the ranking");
            }
            $seen[$id] = true;
        }
        if (count($seen) !== count($want)) {
            $missing = array_values(array_diff(array_keys($want), array_keys($seen)));
            throw ApiException::badRequest('The ranking must include every active project; missing: ' . implode(', ', $missing));
        }
        $ranks = [];
        foreach ($ranking as $i => $id) {
            $ranks[$id] = $i + 1;
        }

        return $ranks;
    }

    private static function iso(?\DateTimeInterface $t): ?string
    {
        return $t === null ? null : \Carbon\CarbonImmutable::instance($t)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}

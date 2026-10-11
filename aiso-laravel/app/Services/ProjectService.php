<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Models\Project;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Project list, priorities and lifecycle. Priorities are a strict ranking 1..n of the active projects. */
class ProjectService
{
    public function __construct(
        private readonly ProjectDataService $dataService,
        private readonly PlanService $planService,
        private readonly AuditService $audit,
    ) {
    }

    public function list(): array
    {
        $data = $this->dataService->load();

        return app(SchedulingService::class)->summaries($data, $this->planService->plan($data));
    }

    /**
     * Applies a complete ranking of the active projects (first = highest priority) and optional due dates.
     *
     * @param string[]                $ranking
     * @param array<string, ?string>  $dueDates project id => date; an empty value clears it; absent = unchanged
     */
    public function setPriorities(array $ranking, ?array $dueDates, AppUser $actor): array
    {
        return DB::transaction(function () use ($ranking, $dueDates, $actor) {
            $active = Project::query()->where('status', ProjectStatus::ACTIVE->value)
                ->orderBy('priority')->orderBy('id')->get()->all();
            $ranks = SchedulingService::ranks($ranking, array_map(static fn (Project $p) => $p->id, $active));
            $before = self::describe($active);
            foreach ($active as $p) {
                $p->priority = $ranks[$p->id];
            }
            if ($dueDates !== null) {
                foreach ($active as $p) {
                    if (array_key_exists($p->id, $dueDates)) {
                        $p->due_date = self::parseDue($dueDates[$p->id]);
                    }
                }
            }
            foreach ($active as $p) {
                $p->save();
            }
            usort($active, static fn (Project $a, Project $b): int => $a->priority <=> $b->priority);
            $this->audit->record($actor->id, 'PROJECT_PRIORITIES_CHANGED', 'Project', '*', $before,
                self::describe($active), null);

            return $this->list();
        });
    }

    public function update(string $id, ?string $name, ?string $dueDate, bool $clearDueDate, ?ProjectStatus $status,
                           AppUser $actor): array
    {
        return DB::transaction(function () use ($id, $name, $dueDate, $clearDueDate, $status, $actor) {
            $p = Project::query()->find($id);
            if ($p === null) {
                throw ApiException::notFound("Project {$id} not found");
            }
            $before = "{$p->name}/{$p->status->value}/" . ($p->due_date?->toIso8601String() ?? 'null');
            if ($name !== null && trim($name) !== '') {
                $p->name = trim($name);
            }
            if ($clearDueDate) {
                $p->due_date = null;
            } elseif ($dueDate !== null) {
                $p->due_date = self::parseDue($dueDate);
            }
            if ($status !== null && $status !== $p->status) {
                if ($status === ProjectStatus::ARCHIVED) {
                    $open = 0;
                    foreach ($this->dataService->load()->ops as $o) {
                        if ($o->project_id === $id && !$o->status->isTerminal()) {
                            $open++;
                        }
                    }
                    if ($open > 0) {
                        throw ApiException::conflict("Project {$id} still has {$open} unfinished operation(s). Complete or cancel them first.");
                    }
                    $p->status = ProjectStatus::ARCHIVED;
                } else {
                    $last = Project::query()->where('status', ProjectStatus::ACTIVE->value)->count();
                    $p->status = ProjectStatus::ACTIVE;
                    $p->priority = $last + 1;
                }
            }
            $p->save();
            self::renumber();
            $this->audit->record($actor->id, 'PROJECT_UPDATED', 'Project', $id, $before,
                "{$p->name}/{$p->status->value}/" . ($p->due_date?->toIso8601String() ?? 'null'), null);

            return $this->list();
        });
    }

    /** Keeps the active ranks gap-free (1..n) after an archive. */
    public static function renumber(): void
    {
        $active = Project::query()->where('status', ProjectStatus::ACTIVE->value)
            ->orderBy('priority')->orderBy('id')->get()->all();
        foreach ($active as $i => $p) {
            $p->priority = $i + 1;
            $p->save();
        }
    }

    /**
     * Accepts YYYY-MM-DD, a Jalali date like 1405/07/25 (Persian digits allowed) or an ISO instant. A date-only value
     * means the end of that day in Tehran, so a due date "on the 25th" includes the 25th.
     */
    public static function parseDue(?string $text): ?CarbonImmutable
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $t = Jalali::latin(trim($text));
        try {
            $jalali = Jalali::parseDate($t);
            if ($jalali !== null) {
                return self::endOfDay($jalali);
            }
            if (strlen($t) <= 10 && preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $t) === 1) {
                return self::endOfDay(CarbonImmutable::parse($t, Jalali::TEHRAN));
            }

            return CarbonImmutable::parse($t);
        } catch (\Throwable) {
            throw ApiException::badRequest("Due date must be YYYY-MM-DD, a Jalali date like 1405/07/25, or an ISO instant, got '{$text}'");
        }
    }

    /** End of the given calendar day in Tehran. */
    private static function endOfDay(CarbonImmutable $d): CarbonImmutable
    {
        return $d->setTimezone(Jalali::TEHRAN)->startOfDay()->addDay()->subSecond();
    }

    /** @param Project[] $list */
    private static function describe(array $list): string
    {
        return implode(', ', array_map(static fn (Project $p) => "{$p->id}={$p->priority}", $list));
    }
}

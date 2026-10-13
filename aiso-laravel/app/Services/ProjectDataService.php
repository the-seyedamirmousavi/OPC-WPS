<?php

namespace App\Services;

use App\Models\AppUser;
use App\Models\Item;
use App\Models\Operation;
use App\Models\PlanBaseline;
use App\Models\Predecessor;
use App\Models\Project;
use App\Models\ScheduleForce;
use App\Models\SystemSetting;
use App\Models\WorkResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Loads the whole project into an immutable {@see ProjectData} snapshot. */
class ProjectDataService
{
    public function load(): ProjectData
    {
        $ops = [];
        $all = Operation::query()->get()->all();
        usort($all, static fn (Operation $a, Operation $b): int => strcmp($a->id, $b->id));
        foreach ($all as $o) {
            $ops[$o->id] = $o;
        }

        $preds = [];
        $succ = [];
        foreach (Predecessor::query()->orderBy('id')->get() as $p) {
            $preds[$p->operation_id][] = $p;
            $succ[$p->predecessor_id][] = $p->operation_id;
        }

        return new ProjectData(
            SystemSetting::current(),
            self::keyed(Project::query()->get(), fn (Project $p) => $p->id),
            $ops,
            self::keyed(WorkResource::inEnteredOrder()->get(), fn (WorkResource $r) => $r->id),
            self::keyed(AppUser::query()->get(), fn (AppUser $u) => $u->id),
            self::keyed(Item::query()->get(), fn (Item $i) => $i->id),
            $preds,
            $succ,
            self::keyed(ScheduleForce::query()->get(), fn (ScheduleForce $f) => $f->operation_id),
            self::keyed(PlanBaseline::query()->get(), fn (PlanBaseline $b) => $b->operation_id),
            // planner times are in milliseconds: FORCEd instants round-trip exactly
            // (Carbon's clock, so a test can fix the moment)
            CarbonImmutable::createFromTimestampMs(CarbonImmutable::now()->getTimestampMs()),
        );
    }

    /**
     * @template T of object
     * @param iterable<T> $rows
     * @param callable(T): string $key
     * @return array<string, T>
     */
    private static function keyed(iterable $rows, callable $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$key($row)] = $row;
        }

        return $out;
    }
}

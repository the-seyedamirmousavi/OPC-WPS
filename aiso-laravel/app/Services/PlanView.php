<?php

namespace App\Services;

use App\Scheduling\Conflict;
use App\Scheduling\Lanes;
use App\Scheduling\Slot;
use App\Scheduling\Task;
use App\Scheduling\WorkCalendar;
use Carbon\CarbonImmutable;

/**
 * The projected schedule of all unfinished work, with hours converted to instants.
 *
 * @param array<string, Slot>   $slots
 * @param array<string, float>  $tailHours
 * @param Task[]                $tasks     the planner input the schedule was computed from
 * @param array<string, string> $rules     which dispatching rule won for each project
 * @param Conflict[]            $conflicts FORCEs that are not honoured, with the reason
 * @param array<string, true>   $honoured  operations placed exactly at their FORCEd start
 * @param array<string, true>   $escalated operations moved ahead of project priority so that a FORCE can be met
 */
final class PlanView
{
    public function __construct(
        public readonly array $slots,
        public readonly float $makespanHours,
        public readonly CarbonImmutable $now,
        public readonly array $tailHours,
        public readonly array $tasks,
        public readonly Lanes $lanes,
        public readonly array $rules,
        public readonly int $candidates,
        public readonly array $conflicts,
        public readonly array $honoured,
        public readonly array $escalated,
        /** open operations left out of the plan, with the reason (e.g. their resource is inactive) */
        public readonly array $unplanned = [],
        /** working time; null: hours are continuous */
        public readonly ?WorkCalendar $calendar = null,
    ) {
    }

    public function start(string $id): ?CarbonImmutable
    {
        return isset($this->slots[$id]) ? $this->at($this->slots[$id]->start) : null;
    }

    public function end(string $id): ?CarbonImmutable
    {
        return isset($this->slots[$id]) ? $this->at($this->slots[$id]->end) : null;
    }

    public function startHours(string $id): float
    {
        return isset($this->slots[$id]) ? $this->slots[$id]->start : 0.0;
    }

    public function projectEnd(): ?CarbonImmutable
    {
        return $this->slots === [] ? null : $this->at($this->makespanHours);
    }

    /**
     * The instant $hours of planned work after now. With a working calendar, nights, days off and holidays are skipped.
     * Millisecond precision, so a FORCEd start comes back as exactly the instant that was forced.
     */
    public function at(float $hours): CarbonImmutable
    {
        if ($this->calendar !== null) {
            return $this->calendar->add($this->now, $hours);
        }

        return $this->now->addMilliseconds((int) round($hours * 3_600_000));
    }
}

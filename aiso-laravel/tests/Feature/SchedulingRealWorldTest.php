<?php

namespace Tests\Feature;

use App\Scheduling\WorkCalendar;
use App\Services\PlanService;
use App\Services\ProjectDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The plan of live work, as the shop floor sees it: assigned work holds its machine, blocked work waits, nothing is
 * planned on a machine that is switched off, and dates follow the working calendar.
 */
class SchedulingRealWorldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en']);
        $this->seed(\Database\Seeders\AisoSeeder::class);
        DB::table('project')->insert([
            ['id' => 'TOP', 'name' => 'Top', 'priority' => 1, 'status' => 'ACTIVE', 'created_at' => now()],
            ['id' => 'LOW', 'name' => 'Low', 'priority' => 2, 'status' => 'ACTIVE', 'created_at' => now()],
        ]);
        DB::table('work_resource')->insert([
            ['id' => 'M', 'name' => 'Machine', 'capacity' => 1, 'status' => 'ACTIVE'],
            ['id' => 'N', 'name' => 'Other', 'capacity' => 1, 'status' => 'ACTIVE'],
            ['id' => 'OFF', 'name' => 'Broken', 'capacity' => 1, 'status' => 'INACTIVE'],
        ]);
    }

    private function op(string $id, string $project, string $resource, float $hours, string $status = 'READY', array $extra = []): void
    {
        DB::table('operation')->insert([
            'id' => $id, 'name' => $id, 'project_id' => $project, 'resource_id' => $resource, 'status' => $status,
            'direct_time' => $hours, 'created_at' => now(), 'updated_at' => now(),
        ] + $extra);
    }

    private function plan(): \App\Services\PlanView
    {
        return app(PlanService::class)->plan(app(ProjectDataService::class)->load());
    }

    public function test_assigned_work_keeps_its_machine_and_the_forecast_says_when_new_work_can_really_start(): void
    {
        $this->op('HELD', 'LOW', 'M', 2, 'ASSIGNED', ['assigned_user_id' => 'user1', 'assigned_at' => now()]);
        $this->op('NEW', 'TOP', 'M', 1);

        $view = $this->plan();

        $this->assertSame(0.0, $view->slots['HELD']->start);
        $this->assertSame(2.0, $view->slots['NEW']->start, 'top-priority work waits for the executor holding the machine');
    }

    public function test_blocked_work_is_planned_after_the_expected_wait(): void
    {
        config(['aiso.scheduling.blocked_release_hours' => 8]);
        $this->op('B', 'TOP', 'M', 2, 'BLOCKED', ['block_reason' => 'missing part', 'status_before_block' => 'READY']);
        $this->op('AFTER', 'TOP', 'N', 1, 'NOT_READY');
        DB::table('predecessor')->insert(['operation_id' => 'AFTER', 'predecessor_id' => 'B', 'dependency_type' => 'FINISH_TO_START', 'mandatory' => true]);
        $this->op('FILL', 'LOW', 'M', 3);

        $view = $this->plan();

        $this->assertGreaterThanOrEqual(8.0, $view->slots['B']->start);
        $this->assertGreaterThanOrEqual($view->slots['B']->end, $view->slots['AFTER']->start);
        $this->assertSame(0.0, $view->slots['FILL']->start, 'the machine is used while the blocked work waits');
    }

    public function test_nothing_is_planned_on_a_switched_off_machine_and_the_reason_is_given(): void
    {
        $this->op('DOWN', 'TOP', 'OFF', 2);
        $this->op('NEXT', 'TOP', 'N', 1, 'NOT_READY');
        DB::table('predecessor')->insert(['operation_id' => 'NEXT', 'predecessor_id' => 'DOWN', 'dependency_type' => 'FINISH_TO_START', 'mandatory' => true]);
        $this->op('FINE', 'TOP', 'M', 1);

        $view = $this->plan();

        $this->assertArrayNotHasKey('DOWN', $view->slots);
        $this->assertArrayNotHasKey('NEXT', $view->slots);
        $this->assertArrayHasKey('FINE', $view->slots);
        $this->assertStringContainsString('inactive', $view->unplanned['DOWN']);
        $this->assertStringContainsString('DOWN', $view->unplanned['NEXT']);
    }

    public function test_the_working_calendar_skips_nights_days_off_and_holidays(): void
    {
        $cal = WorkCalendar::fromConfig(array_merge(config('aiso.calendar'), ['enabled' => true]));
        $tehran = static fn (string $s) => CarbonImmutable::parse($s, 'Asia/Tehran');

        // Wednesday 14:00 + 8 working hours: 2 on Wednesday, 4 on Thursday (half day), Friday off, 2 on Saturday
        $this->assertSame('2026-10-17 10:00', $cal->add($tehran('2026-10-14 14:00'), 8)->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
        // a request on Friday evening starts on Saturday morning
        $this->assertSame('2026-10-17 08:00', $cal->add($tehran('2026-10-16 20:00'), 0)->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
        // and back: the same span is 8 working hours
        $this->assertEqualsWithDelta(8.0, $cal->between($tehran('2026-10-14 14:00'), $tehran('2026-10-17 10:00')), 1e-6);
        $this->assertEqualsWithDelta(-8.0, $cal->between($tehran('2026-10-17 10:00'), $tehran('2026-10-14 14:00')), 1e-6);

        $holiday = WorkCalendar::fromConfig(array_merge(config('aiso.calendar'), ['enabled' => true, 'holidays' => ['2026-10-17']]));
        $this->assertSame('2026-10-18 10:00', $holiday->add($tehran('2026-10-14 14:00'), 8)->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
    }

    public function test_with_the_calendar_planned_dates_fall_in_working_time(): void
    {
        config(['aiso.calendar.enabled' => true]);
        WorkCalendar::reset();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 14:00', 'Asia/Tehran'));
        $this->op('LONG', 'TOP', 'M', 8);

        $view = $this->plan();

        $this->assertSame('2026-10-14 14:00', $view->start('LONG')->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-17 10:00', $view->end('LONG')->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
        // the forecast tool reads the same dates
        $this->assertSame(8.0, $view->slots['LONG']->end);
    }

    public function test_without_the_calendar_time_is_continuous_as_before(): void
    {
        config(['aiso.calendar.enabled' => false]);
        WorkCalendar::reset();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 14:00', 'Asia/Tehran'));
        $this->op('LONG', 'TOP', 'M', 8);

        $this->assertSame('2026-10-14 22:00', $this->plan()->end('LONG')->setTimezone('Asia/Tehran')->format('Y-m-d H:i'));
    }
}

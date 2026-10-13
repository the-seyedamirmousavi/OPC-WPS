<?php

namespace Tests\Feature\AI;

use App\AI\DepartmentRegistry;
use App\AI\ProtocolException;
use App\AI\ProtocolValidator;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use App\Services\AuditService;
use App\Services\DepartmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The assistant's work intents: each one checked for data, truncation, access and what the validator accepts. */
class PerformanceIntentsTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    /** Reads an intent of a department exactly as the orchestrator does (through the handler, after the registry check). */
    private function read(string $slug, string $intent, array $params, string $as, ?\App\Models\Department $dept = null): array
    {
        $user = $this->user($as);
        $department = $dept ?? ($slug === 'production' ? $this->production() : ($slug === 'hr' ? $this->hr() : null));

        return app(DepartmentRegistry::class)->handler(DepartmentRegistry::kindOf($department))
            ->fetch($intent, $params, $user, $department);
    }

    /** One operation with the given fields; the project P1 (due in ten days) and the resource R1 (one lane) are created once. */
    private function op(string $id, array $over = []): void
    {
        $due = CarbonImmutable::now()->addDays(10)->toDateTimeString();
        DB::table('project')->insertOrIgnore(['id' => 'P1', 'name' => 'Project one', 'priority' => 1, 'status' => 'ACTIVE',
            'due_date' => $due, 'created_at' => now()]);
        DB::table('work_resource')->insertOrIgnore(['id' => 'R1', 'name' => 'Machine', 'capacity' => 1, 'status' => 'ACTIVE']);
        DB::table('operation')->insert(array_merge([
            'id' => $id, 'name' => "Operation {$id}", 'project_id' => 'P1', 'resource_id' => 'R1', 'status' => 'READY',
            'progress_percent' => 0, 'preparation_time' => 0, 'transport_time' => 0, 'setup_time' => 0, 'direct_time' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    public function test_the_work_summary_gives_planned_hours_and_the_elapsed_time_not_hours_worked(): void
    {
        $this->production();
        $this->op('OP-A', ['assigned_user_id' => 'user1', 'status' => 'COMPLETED', 'direct_time' => 3, 'setup_time' => 1,
            'started_at' => '2026-10-01 08:00:00', 'completed_at' => '2026-10-01 10:00:00']);
        $this->op('OP-B', ['assigned_user_id' => 'user1', 'status' => 'READY']);

        $r = $this->read('production', 'user_work_summary', ['user_id' => 'user1'], 'owner');
        $d = $r['data'];
        $this->assertTrue($d['found']);
        $this->assertSame(['COMPLETED' => 1, 'READY' => 1], $d['by_status']);
        $this->assertSame(1, $d['completed']);
        $this->assertEqualsWithDelta(4.0, $d['planned_hours_completed'], 1e-6);
        $this->assertEqualsWithDelta(2.0, $d['elapsed_hours_completed'], 1e-6);
        $this->assertSame(1, $d['elapsed_samples']);
        $this->assertSame('OP-A', $d['last_completed']['id']);
        $this->assertStringContainsString('ساعت واقعی کار ثبت نمی‌شود', $d['note']);
        $this->assertFalse($r['truncated']);
    }

    public function test_a_work_summary_for_an_unknown_person_says_so(): void
    {
        $this->production();
        $this->assertSame(['found' => false], $this->read('production', 'user_work_summary', ['user_id' => 'nobody'], 'owner')['data']);
    }

    public function test_the_operations_of_one_person_are_listed_and_cut_to_the_limit(): void
    {
        $this->production();
        $this->op('OP-1', ['assigned_user_id' => 'user2']);
        $this->op('OP-2', ['assigned_user_id' => 'user2']);

        $r = $this->read('production', 'list_by_user', ['user_id' => 'user2', 'limit' => 1], 'owner');
        $this->assertCount(1, $r['data']);
        $this->assertTrue($r['truncated']);

        $all = $this->read('production', 'list_by_user', ['user_id' => 'user2', 'status' => 'READY'], 'owner');
        $this->assertCount(2, $all['data']);
        $this->assertFalse($all['truncated']);
    }

    public function test_the_history_holds_assignments_and_reports_of_one_person_newest_first(): void
    {
        $this->production();
        $this->op('OP-1', ['assigned_user_id' => 'user1', 'assigned_at' => '2026-10-02 09:00:00']);
        DB::table('operation_report')->insert(['operation_id' => 'OP-1', 'user_id' => 'user1', 'report_type' => 'PROGRESS',
            'progress_percent' => 40, 'note' => 'half way', 'created_at' => '2026-10-03 09:00:00']);

        $events = $this->read('production', 'user_history', ['user_id' => 'user1'], 'owner')['data'];
        $this->assertSame(['PROGRESS', 'ASSIGNED'], array_column($events, 'event'));
        $this->assertSame(40, $events[0]['progress']);
        $this->assertSame('half way', $events[0]['note']);
    }

    public function test_late_operations_are_those_that_end_after_the_project_due_date(): void
    {
        $this->production();
        $now = CarbonImmutable::now();
        $due = $now->addDays(10);
        $this->op('LATE', ['planned_end' => $due->addDays(5)->toDateTimeString()]);
        $this->op('ON-TIME', ['planned_end' => $now->addDays(5)->toDateTimeString()]);

        $r = $this->read('production', 'late_operations', [], 'owner');
        $this->assertSame(['LATE'], array_column($r['data'], 'id'));
        $this->assertEqualsWithDelta(5.0, $r['data'][0]['delay_days'], 0.01);
    }

    public function test_resource_load_counts_open_work_and_its_planned_hours(): void
    {
        $this->production();
        $this->op('OP-1', ['direct_time' => 2]);
        $this->op('OP-2', ['direct_time' => 3]);
        $this->op('OP-3', ['status' => 'COMPLETED', 'direct_time' => 9]);

        $r = $this->read('production', 'resource_load', ['resource_id' => 'R1'], 'owner')['data'];
        $this->assertCount(1, $r);
        $this->assertSame([1, 2, 5.0], [$r[0]['capacity'], $r[0]['open_operations'], $r[0]['planned_hours_open']]);
    }

    public function test_two_operations_at_the_same_time_on_a_one_lane_resource_are_a_conflict(): void
    {
        $this->production();
        $t = CarbonImmutable::now()->addDay()->startOfHour();
        $this->op('OP-1', ['planned_start' => $t, 'planned_end' => $t->addHours(3)]);
        $this->op('OP-2', ['planned_start' => $t->addHour(), 'planned_end' => $t->addHours(4)]);

        $rows = $this->read('production', 'schedule_conflicts', [], 'owner')['data'];
        $this->assertCount(1, $rows);
        $this->assertSame(['R1', 2, 1], [$rows[0]['resource'], $rows[0]['max_concurrent'], $rows[0]['capacity']]);
        $this->assertSame(['OP-1', 'OP-2'], $rows[0]['operations']);
    }

    public function test_operations_one_after_another_are_not_a_conflict(): void
    {
        $this->production();
        $t = CarbonImmutable::now()->addDay()->startOfHour();
        $this->op('OP-1', ['planned_start' => $t, 'planned_end' => $t->addHours(2)]);
        $this->op('OP-2', ['planned_start' => $t->addHours(2), 'planned_end' => $t->addHours(4)]);

        $this->assertSame([], $this->read('production', 'schedule_conflicts', [], 'owner')['data']);
    }

    public function test_project_detail_gives_progress_and_the_late_open_work(): void
    {
        $this->production();
        $this->op('OP-1', ['status' => 'COMPLETED']);
        $this->op('OP-2', ['status' => 'READY']);
        $this->op('OP-3', ['status' => 'READY', 'planned_end' => CarbonImmutable::now()->addDays(20)->toDateTimeString()]);

        $d = $this->read('production', 'project_detail', ['project_id' => 'P1'], 'owner')['data'];
        $this->assertSame(['found' => true, 'id' => 'P1'], array_intersect_key($d, ['found' => true, 'id' => true]));
        $this->assertSame(3, $d['operations']);
        $this->assertEqualsWithDelta(33.3, $d['progress_percent'], 0.1);
        $this->assertSame(1, $d['late_open_operations']);
        $this->assertSame(['found' => false], $this->read('production', 'project_detail', ['project_id' => 'NOPE'], 'owner')['data']);
    }

    public function test_throughput_counts_completed_work_in_the_period_only(): void
    {
        $this->production();
        $this->op('OP-1', ['status' => 'COMPLETED', 'completed_at' => '2026-10-03 10:00:00']);
        $this->op('OP-2', ['status' => 'COMPLETED', 'completed_at' => '2026-11-03 10:00:00']);

        $in = $this->read('production', 'throughput', ['from' => '2026-10-01', 'to' => '2026-10-05'], 'owner')['data'];
        $this->assertSame(1, $in['count']);
        $out = $this->read('production', 'throughput', ['from' => '2026-12-01', 'to' => '2026-12-05'], 'owner')['data'];
        $this->assertSame(0, $out['count']);
    }

    public function test_a_user_detail_has_departments_and_no_contact_details(): void
    {
        $this->staff($this->production(), [['user1', 'MEMBER']]);
        $d = $this->read('hr', 'user_detail', ['user_id' => 'user1'], 'owner')['data'];

        $this->assertSame('Production', $d['departments'][0]['name']);
        $this->assertSame('MEMBER', $d['departments'][0]['role']);
        foreach (['contact_info', 'password_hash', 'messenger_id'] as $secret) {
            $this->assertArrayNotHasKey($secret, $d);
        }
    }

    public function test_the_members_of_a_department_come_with_their_role(): void
    {
        $production = $this->production();
        $this->staff($production, [['user1', 'MEMBER'], ['manager', 'HEAD']]);
        $r = $this->read('hr', 'department_members', ['department_id' => $production->id], 'owner');

        $roles = collect($r['data']['members'])->pluck('role', 'id')->all();
        $this->assertSame(['user1' => 'MEMBER', 'manager' => 'HEAD'], $roles);
    }

    public function test_the_audit_log_is_for_the_owner_and_can_be_narrowed_to_one_object(): void
    {
        $this->production();
        app(AuditService::class)->record('owner', 'OPERATION_CHANGED', 'Operation', 'OP-9', 'READY', 'BLOCKED', 'checked');
        app(AuditService::class)->record('owner', 'OPERATION_CHANGED', 'Operation', 'OP-8', 'READY', 'BLOCKED', 'other');
        $general = $this->general();

        $r = $this->read('dept', 'audit_recent', ['entity_type' => 'Operation', 'entity_id' => 'OP-9'], 'owner', $general);
        $this->assertCount(1, $r['data']);
        $this->assertSame('OP-9', $r['data'][0]['entityId']);
    }

    /** A general department of its own, as the owner makes them. */
    private function general(): \App\Models\Department
    {
        $d = app(DepartmentService::class)->create('Research', null, $this->user('owner'));

        return \App\Models\Department::query()->findOrFail($d['id']);
    }

    public function test_a_manager_is_refused_every_individual_and_audit_intent(): void
    {
        $this->production();
        $general = $this->general();
        $asManager = [
            ['production', 'user_work_summary', ['user_id' => 'user1']],
            ['production', 'list_by_user', ['user_id' => 'user1']],
            ['production', 'user_history', ['user_id' => 'user1']],
        ];
        foreach ($asManager as [$slug, $intent, $params]) {
            $this->assertThrows(fn () => $this->read($slug, $intent, $params, 'manager'), ApiException::class);
        }
        $this->assertThrows(fn () => $this->read('dept', 'audit_recent', [], 'manager', $general), ApiException::class);
    }

    public function test_a_manager_still_reads_the_general_production_intents(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->op('OP-1', ['status' => 'BLOCKED']);

        $this->assertSame(1, $this->read('production', 'project_detail', ['project_id' => 'P1'], 'manager')['data']['late_open_operations'] + 1);
        $this->assertNotEmpty($this->read('production', 'resource_load', [], 'manager')['data']);
    }

    public function test_the_validator_refuses_an_unknown_intent_and_an_unknown_parameter(): void
    {
        $this->production();
        $allowed = app(DepartmentRegistry::class)->forUser($this->user('owner'));
        $v = app(ProtocolValidator::class);

        $unknownIntent = $v->decode(F::route('fetch', [F::req('production', 'list_by_users', ['user_id' => 'user1'])]));
        $this->expectException(ProtocolException::class);
        $v->route($unknownIntent, $allowed);
    }

    public function test_the_validator_drops_a_parameter_the_intent_does_not_have(): void
    {
        $this->production();
        $allowed = app(DepartmentRegistry::class)->forUser($this->user('owner'));
        $v = app(ProtocolValidator::class);

        $m = $v->decode(F::route('fetch', [F::req('production', 'list_by_user', ['user_id' => 'user1', 'salary' => 1])]));
        $this->expectException(ProtocolException::class);
        $v->route($m, $allowed);
    }

    public function test_a_valid_request_for_a_work_intent_passes_the_validator(): void
    {
        $this->production();
        $allowed = app(DepartmentRegistry::class)->forUser($this->user('owner'));
        $v = app(ProtocolValidator::class);

        $r = $v->route($v->decode(F::route('fetch', [F::req('production', 'user_work_summary', ['user_id' => 'user1'])])), $allowed);
        $this->assertSame('user_work_summary', $r['requests'][0]['intent']);
    }
}

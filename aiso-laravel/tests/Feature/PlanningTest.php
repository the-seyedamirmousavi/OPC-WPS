<?php

namespace Tests\Feature;

use App\Imports\ImportService;
use App\Models\AppUser;
use App\Models\Operation;
use App\Models\Project;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Planning without an Excel file: forms in, the import rules applied, an importable workbook out. */
class PlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
    }

    private function as(string $id): AppUser
    {
        $u = AppUser::query()->find($id);
        $this->actingAs($u);

        return $u;
    }

    /** A small plan: weld (capacity 2) then paint, painting depends on welding. */
    private function draft(array $overrides = []): array
    {
        return array_replace([
            'newProjectName' => 'Plant A',
            'resources' => [
                ['id' => 'R-WELD', 'name' => 'Welding', 'capacity' => 2, 'status' => 'ACTIVE'],
                ['id' => 'R-PAINT', 'name' => 'Painting', 'capacity' => 1, 'status' => 'ACTIVE', 'userId' => 'user1'],
            ],
            'operations' => [
                ['id' => 'OP-1', 'name' => 'Weld box', 'resourceId' => 'R-WELD', 'prep' => 0, 'transport' => 0, 'setup' => 1, 'direct' => 8],
                ['id' => 'OP-2', 'name' => 'Paint box', 'resourceId' => 'R-PAINT', 'prep' => 0, 'transport' => 1, 'setup' => 0.5, 'direct' => 6],
            ],
            'predecessors' => [
                ['operationId' => 'OP-2', 'predecessorId' => 'OP-1', 'type' => 'FINISH_TO_START', 'mandatory' => true],
            ],
        ], $overrides);
    }

    public function test_a_plan_is_checked_then_saved_without_any_file(): void
    {
        $this->as('manager');

        // a check writes nothing
        $this->postJson('/api/planning/submit', $this->draft() + ['apply' => false])
            ->assertOk()->assertJsonPath('status', 'VALID')->assertJsonPath('newProject', true);
        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(0, Project::query()->count());

        $this->postJson('/api/planning/submit', $this->draft() + ['apply' => true])
            ->assertOk()->assertJsonPath('status', 'APPLIED')->assertJsonPath('projectName', 'Plant A');

        $this->assertSame(2, Operation::query()->count());
        // the first operation has no prerequisite, the second waits for it
        $this->assertSame('READY', Operation::query()->find('OP-1')->status->value);
        $this->assertSame('NOT_READY', Operation::query()->find('OP-2')->status->value);

        $plan = $this->getJson('/api/planning/plan')->assertOk()->json();
        $this->assertCount(2, $plan['operations']);
        $this->assertCount(2, $plan['resources']);
        $this->assertSame('OP-1', $plan['predecessors'][0]['predecessorId']);
        $this->assertFalse($plan['operations'][0]['locked']);
    }

    public function test_a_bad_draft_is_rejected_with_row_level_errors_and_saves_nothing(): void
    {
        $this->as('manager');
        // 1) malformed values are reported first
        $shape = $this->draft(['operations' => [
            ['id' => 'OP-1', 'name' => 'B', 'resourceId' => 'R-WELD', 'direct' => -1],
            ['id' => 'bad id!', 'name' => '', 'resourceId' => 'R-WELD'],
        ]]);
        $r = $this->postJson('/api/planning/submit', $shape + ['apply' => true])->assertOk()->assertJsonPath('status', 'REJECTED');
        $text = collect($r->json('errors'))->pluck('message')->implode(' | ');
        $this->assertStringContainsString('Direct_Time must be a number of hours', $text);
        $this->assertStringContainsString('may only contain letters', $text);
        $this->assertStringContainsString('Operation_Name must not be blank', $text);

        // 2) then the references and rules
        $refs = $this->draft([
            'operations' => [
                ['id' => 'OP-1', 'name' => 'A', 'resourceId' => 'R-NOPE', 'direct' => 2],
                ['id' => 'OP-1', 'name' => 'B', 'resourceId' => 'R-WELD', 'direct' => 1],
            ],
            'predecessors' => [['operationId' => 'OP-1', 'predecessorId' => 'OP-1']],
        ]);
        $r = $this->postJson('/api/planning/submit', $refs + ['apply' => true])->assertOk()->assertJsonPath('status', 'REJECTED');
        $text = collect($r->json('errors'))->pluck('message')->implode(' | ');
        $this->assertStringContainsString("Resource 'R-NOPE' does not exist", $text);
        $this->assertStringContainsString("Duplicate id 'OP-1'", $text);
        $this->assertStringContainsString('cannot be its own predecessor', $text);
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_errors_come_back_in_persian_for_persian_requests(): void
    {
        $this->as('manager');
        $bad = $this->draft(['operations' => [['id' => 'OP-1', 'name' => 'A', 'resourceId' => 'R-NOPE', 'direct' => 2]]]);

        $r = $this->postJson('/api/planning/submit', $bad + ['apply' => false], ['Accept-Language' => 'fa'])
            ->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertStringContainsString('منبع «R-NOPE» وجود ندارد', collect($r->json('errors'))->pluck('message')->implode(' '));

        $blank = $this->draft(['operations' => [['id' => 'OP-1', 'name' => '', 'resourceId' => 'R-WELD']]]);
        $r = $this->postJson('/api/planning/submit', $blank + ['apply' => false], ['Accept-Language' => 'fa'])->assertOk();
        $this->assertStringContainsString('نباید خالی باشد', collect($r->json('errors'))->pluck('message')->implode(' '));
    }

    public function test_dependency_cycles_are_refused(): void
    {
        $this->as('manager');
        $cyclic = $this->draft(['predecessors' => [
            ['operationId' => 'OP-2', 'predecessorId' => 'OP-1'],
            ['operationId' => 'OP-1', 'predecessorId' => 'OP-2'],
        ]]);

        $r = $this->postJson('/api/planning/submit', $cyclic + ['apply' => true])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertStringContainsString('Dependency cycle', collect($r->json('errors'))->pluck('message')->implode(' '));
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_an_existing_plan_can_be_edited_and_extended(): void
    {
        $this->as('manager');
        $this->postJson('/api/planning/submit', $this->draft() + ['apply' => true])->assertOk();
        $pid = Project::query()->first()->id;

        $edit = $this->getJson('/api/planning/plan?projectId=' . $pid)->json();
        $edit['operations'][0]['direct'] = 12;
        $edit['operations'][] = ['id' => 'OP-3', 'name' => 'Pack', 'resourceId' => 'R-PAINT', 'direct' => 2];
        $edit['predecessors'][] = ['operationId' => 'OP-3', 'predecessorId' => 'OP-2', 'type' => 'FINISH_TO_START', 'mandatory' => true];

        $this->postJson('/api/planning/submit', [
            'apply' => true, 'projectId' => $pid, 'resources' => $edit['resources'],
            'operations' => $edit['operations'], 'predecessors' => $edit['predecessors'],
        ])->assertOk()->assertJsonPath('status', 'APPLIED')->assertJsonPath('newProject', false);

        $this->assertSame(3, Operation::query()->count());
        $this->assertEquals(12.0, Operation::query()->find('OP-1')->direct_time);
    }

    public function test_started_operations_are_locked_and_their_resource_cannot_change(): void
    {
        $this->as('manager');
        $this->postJson('/api/planning/submit', $this->draft() + ['apply' => true])->assertOk();
        $pid = Project::query()->first()->id;
        Operation::query()->where('id', 'OP-1')->update(['status' => 'IN_PROGRESS']);

        $plan = $this->getJson('/api/planning/plan?projectId=' . $pid)->json();
        $this->assertTrue($plan['operations'][0]['locked']);

        $plan['operations'][0]['resourceId'] = 'R-PAINT';
        $r = $this->postJson('/api/planning/submit', [
            'apply' => true, 'projectId' => $pid, 'resources' => $plan['resources'],
            'operations' => $plan['operations'], 'predecessors' => $plan['predecessors'],
        ])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertStringContainsString('its resource cannot be changed', collect($r->json('errors'))->pluck('message')->implode(' '));
    }

    public function test_a_second_project_is_added_with_the_lowest_priority(): void
    {
        $this->as('manager');
        $this->postJson('/api/planning/submit', $this->draft() + ['apply' => true])->assertOk();

        $second = [
            'apply' => true, 'newProjectName' => 'Plant B',
            'resources' => [['id' => 'R-PACK', 'name' => 'Packing', 'capacity' => 1]],
            'operations' => [['id' => 'B-1', 'name' => 'Pack', 'resourceId' => 'R-PACK', 'direct' => 3]],
            'predecessors' => [],
        ];
        $this->postJson('/api/planning/submit', $second)->assertOk()->assertJsonPath('status', 'APPLIED');

        $this->assertSame(1, (int) Project::query()->where('name', 'Plant A')->value('priority'));
        $this->assertSame(2, (int) Project::query()->where('name', 'Plant B')->value('priority'));
        $this->getJson('/api/planning/plan')->assertOk()->assertJsonCount(2, 'projects');
    }

    public function test_the_saved_plan_exports_as_a_workbook_that_imports_back(): void
    {
        $this->as('manager');
        $this->postJson('/api/planning/submit', $this->draft() + ['apply' => true])->assertOk();
        $pid = Project::query()->first()->id;

        foreach (['en', 'fa'] as $lang) {
            $res = $this->get('/api/planning/export?lang=' . $lang . '&projectId=' . $pid)->assertOk();
            $this->assertStringContainsString('spreadsheetml.sheet', (string) $res->headers->get('Content-Type'));

            $path = tempnam(sys_get_temp_dir(), 'plan') . '.xlsx';
            file_put_contents($path, $res->getContent());
            try {
                $r = app(ImportService::class)->process($path, 'export.xlsx', false, AppUser::query()->find('manager'), ['projectId' => $pid]);
            } finally {
                @unlink($path);
            }
            $this->assertSame('VALID', $r['status'], $lang . ': ' . json_encode($r['errors']));
            $ops = collect($r['counts'])->firstWhere('entity', 'operations');
            $this->assertSame(2, $ops['created']);
        }
    }

    public function test_only_management_can_use_the_planner(): void
    {
        $this->as('user1');
        $this->getJson('/api/planning/plan')->assertStatus(403);
        $this->postJson('/api/planning/submit', $this->draft())->assertStatus(403);
        $this->getJson('/api/planning/export')->assertStatus(403);
    }
}

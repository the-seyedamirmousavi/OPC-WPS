<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\AuditEvent;
use App\Models\Operation;
use App\Models\SystemSetting;
use App\Services\PlanService;
use App\Services\ProjectDataService;
use App\Services\SchedulingService;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** The three planning goals (project order, resources, time) and Excel inside the plan builder. */
class PlanningGoalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
        $this->actingAs(AppUser::query()->find('manager'));
    }

    /**
     * Plant A (first by priority): weld 9 h, then paint 7.5 h. Plant B: one paint job of 12 h on the same painting
     * resource. By priority B waits for A (finishes at 28.5 h) and painting stands idle for the first 9 h.
     */
    private function twoProjects(): array
    {
        $a = $this->postJson('/api/planning/submit', [
            'apply' => true, 'newProjectName' => 'Plant A',
            'resources' => [
                ['id' => 'R-WELD', 'name' => 'Welding', 'capacity' => 1, 'status' => 'ACTIVE'],
                ['id' => 'R-PAINT', 'name' => 'Painting', 'capacity' => 1, 'status' => 'ACTIVE'],
            ],
            'operations' => [
                ['id' => 'OP-1', 'name' => 'Weld', 'resourceId' => 'R-WELD', 'setup' => 1, 'direct' => 8],
                ['id' => 'OP-2', 'name' => 'Paint', 'resourceId' => 'R-PAINT', 'transport' => 1, 'setup' => 0.5, 'direct' => 6],
            ],
            'predecessors' => [['operationId' => 'OP-2', 'predecessorId' => 'OP-1', 'type' => 'FINISH_TO_START', 'mandatory' => true]],
        ])->assertOk()->assertJsonPath('status', 'APPLIED')->json('projectId');
        $b = $this->postJson('/api/planning/submit', [
            'apply' => true, 'newProjectName' => 'Plant B',
            'operations' => [['id' => 'OP-3', 'name' => 'Paint big', 'resourceId' => 'R-PAINT', 'direct' => 12]],
        ])->assertOk()->assertJsonPath('status', 'APPLIED')->json('projectId');

        return [$a, $b];
    }

    /** @return array<string, array> goal => option */
    private function goalOptions(string $query = ''): array
    {
        $r = $this->getJson('/api/planning/goals' . $query)->assertOk()->json();

        return collect($r['options'])->keyBy('goal')->all();
    }

    private static function finish(array $option, string $projectId): float
    {
        return (float) collect($option['projects'])->firstWhere('projectId', $projectId)['finishHours'];
    }

    public function test_goals_show_what_each_one_gives_on_the_saved_plan(): void
    {
        [$a, $b] = $this->twoProjects();

        $r = $this->getJson('/api/planning/goals?focus=' . $b)->assertOk();
        $r->assertJsonPath('goal', 'PRIORITY')->assertJsonPath('focusProjectId', $b);
        $o = $this->goalOptions('?focus=' . $b);

        // project order: B waits for A and painting is idle at the start
        $this->assertEqualsWithDelta(16.5, self::finish($o['PRIORITY'], $a), 1e-6);
        $this->assertEqualsWithDelta(28.5, self::finish($o['PRIORITY'], $b), 1e-6);
        $this->assertEqualsWithDelta(9.0, $o['PRIORITY']['wasteHours'], 1e-6);

        // resources: no idle time, whoever finishes when
        $this->assertEqualsWithDelta(0.0, $o['RESOURCES']['wasteHours'], 1e-6);
        $this->assertLessThan($o['PRIORITY']['wasteHours'], $o['RESOURCES']['wasteHours'] + 1e-9);
        $this->assertLessThanOrEqual($o['PRIORITY']['makespanHours'], $o['RESOURCES']['makespanHours'] + 1e-9);
        $this->assertEqualsWithDelta(19.5, self::finish($o['RESOURCES'], $a), 1e-6);
        $this->assertEqualsWithDelta(12.0, self::finish($o['RESOURCES'], $b), 1e-6);

        // time: the focus project first; the other one pays for it
        $this->assertEqualsWithDelta(12.0, self::finish($o['TIME'], $b), 1e-6);
        $this->assertEqualsWithDelta(19.5, self::finish($o['TIME'], $a), 1e-6);
    }

    public function test_the_stored_goal_drives_the_real_plan_and_is_audited(): void
    {
        [$a, $b] = $this->twoProjects();
        $finishOf = function (string $id): float {
            $data = app(ProjectDataService::class)->load();
            $m = app(SchedulingService::class)->metrics($data, app(PlanService::class)->plan($data));

            return (float) collect($m['projects'])->firstWhere('projectId', $id)['finishHours'];
        };
        $this->assertEqualsWithDelta(28.5, $finishOf($b), 1e-6);

        // the time goal needs a project to put first
        $this->putJson('/api/planning/goal', ['goal' => 'TIME'])->assertStatus(400);
        $this->putJson('/api/planning/goal', ['goal' => 'SIDEWAYS'])->assertStatus(400);
        $this->putJson('/api/planning/goal', ['goal' => 'TIME', 'focusProjectId' => 'NOPE'])->assertStatus(400);

        $this->putJson('/api/planning/goal', ['goal' => 'TIME', 'focusProjectId' => $b])->assertOk()->assertJsonPath('goal', 'TIME');
        $this->assertEqualsWithDelta(12.0, $finishOf($b), 1e-6);
        // stored priorities are untouched: the time goal only reorders this calculation
        $this->assertSame([$a, $b], $this->getJson('/api/planning/plan')->json('projects.*.id'));

        $this->putJson('/api/planning/goal', ['goal' => 'PRIORITY'])->assertOk();
        $this->assertEqualsWithDelta(28.5, $finishOf($b), 1e-6);
        $this->assertSame('PRIORITY', SystemSetting::current()->planning_goal->value);
        $this->assertSame(2, AuditEvent::query()->where('action_name', 'PLANNING_GOAL_CHANGED')->count());
    }

    public function test_only_management_may_change_the_goal(): void
    {
        $this->actingAs(AppUser::query()->find('user1'));
        $this->putJson('/api/planning/goal', ['goal' => 'RESOURCES'])->assertStatus(403);
        $this->getJson('/api/planning/goals')->assertStatus(403);
    }

    // ---- Excel inside the plan builder ---------------------------------------------------------------------------

    private function fixture(string $name): UploadedFile
    {
        return new UploadedFile(__DIR__ . '/../Fixtures/test-data/' . $name, $name, null, null, true);
    }

    public function test_a_good_file_fills_the_draft_and_saves_nothing(): void
    {
        $r = $this->post('/api/planning/from-excel', ['file' => $this->fixture('I01-valid.xlsx'), 'newProjectName' => 'From file'],
            ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'VALID')->json();

        $this->assertCount(3, $r['draft']['resources']);
        $this->assertCount(3, $r['draft']['operations']);
        $this->assertCount(2, $r['draft']['predecessors']);
        $this->assertSame(['id', 'name', 'itemId', 'resourceId', 'userId', 'prep', 'transport', 'setup', 'direct', 'description'],
            array_keys($r['draft']['operations'][0]));
        // BOM items are not part of the builder, so no operation keeps an item that is not saved
        $this->assertSame([null], array_values(array_unique(array_column($r['draft']['operations'], 'itemId'))));
        $this->assertSame(0, Operation::query()->count());
    }

    public function test_a_bad_file_lists_the_problems_and_fills_nothing(): void
    {
        $r = $this->post('/api/planning/from-excel', ['file' => $this->fixture('I05-bad-references.xlsx'), 'newProjectName' => 'X'],
            ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'REJECTED')->json();
        $this->assertNull($r['draft']);
        $this->assertContains("Resource 'R-NOPE' does not exist", array_column($r['errors'], 'message'));
    }

    public function test_an_empty_or_missing_file_is_a_bad_request(): void
    {
        $this->postJson('/api/planning/from-excel', [])->assertStatus(400);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AppUser;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * End-to-end scenarios through the HTTP layer (sessions, roles, validation, JSON shapes) against an in-memory database,
 * driven by the sample workbook shipped in resources/samples. Port of AisoFlowIntegrationTest.java.
 */
class AisoFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd!test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => self::PASSWORD, 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
    }

    private function as(string $userId): AppUser
    {
        $user = AppUser::query()->find($userId);
        $this->actingAs($user);

        return $user;
    }

    public function test_authentication_and_role_boundaries(): void
    {
        $this->postJson('/api/auth/login', ['userId' => 'owner', 'password' => 'wrong-password'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid user id or password');
        $this->getJson('/api/operations')->assertStatus(401);

        $this->as('user1');
        $this->getJson('/api/operations')->assertStatus(403);
        $this->getJson('/api/dashboard/manager')->assertStatus(403);
        $this->getJson('/api/users')->assertStatus(403);

        $this->as('manager');
        $this->getJson('/api/users')->assertOk();
        $this->postJson('/api/users', ['id' => 'x1', 'fullName' => 'X', 'role' => 'USER_1', 'password' => 'abcdefgh1'])
            ->assertStatus(403);
        $this->postJson('/api/admin/reset', ['reason' => 'x', 'confirm' => 'RESET'])->assertStatus(403);
    }

    public function test_login_returns_the_user_and_a_session(): void
    {
        $this->postJson('/api/auth/login', ['userId' => 'manager', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.id', 'manager')
            ->assertJsonPath('user.role', 'MANAGER');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('id', 'manager');
    }

    public function test_sample_workbook_imports_and_dependencies_decide_readiness(): void
    {
        $this->as('owner');
        $this->postJson('/api/admin/demo-data')
            ->assertOk()
            ->assertJsonPath('status', 'APPLIED')
            ->assertJsonPath('format', 'SIMPLE');

        $this->as('manager');
        $this->getJson('/api/dashboard/manager')
            ->assertOk()
            ->assertJsonPath('progress.totalOperations', 16)
            ->assertJsonPath('statusCounts.READY', 5)
            ->assertJsonPath('statusCounts.NOT_READY', 11);

        // OP-014 (assembly) needs rows 3 and 8: it must explain what it is waiting for
        $waiting = collect($this->getJson('/api/operations/OP-014')->assertOk()->json('operation.waitingFor'))
            ->pluck('id')->sort()->values()->all();
        $this->assertSame(['OP-003', 'OP-008'], $waiting);
    }

    public function test_algorithm_proposes_approves_and_executors_drive_the_dependency_chain(): void
    {
        $this->as('owner');
        $this->postJson('/api/admin/demo-data')->assertOk();

        $this->as('manager');
        $run = $this->postJson('/api/assignments/suggest', ['mode' => 'ALGORITHM'])
            ->assertOk()
            ->assertJsonPath('effectiveMode', 'ALGORITHM')
            ->assertJsonPath('fallback', false)
            ->json();

        // each of the two single-slot resources takes exactly one operation: the one with the longest remaining chain
        $proposed = collect($run['proposals'])->pluck('operationId')->sort()->values()->all();
        $this->assertSame(['OP-001', 'OP-004'], $proposed);
        $ids = array_map(static fn (array $p): int => $p['id'], $run['proposals']);
        $this->postJson('/api/assignments/approve', ['proposalIds' => $ids])->assertOk();

        $assignee = $this->getJson('/api/operations/OP-001')->assertOk()
            ->assertJsonPath('operation.status', 'ASSIGNED')->json('operation.assignedUserId');
        $other = $assignee === 'user1' ? 'user2' : 'user1';

        // somebody else's task is invisible and untouchable
        $this->as($other);
        $this->postJson('/api/operations/OP-001/start')->assertStatus(403);
        $this->getJson('/api/operations/OP-001')->assertStatus(403);

        $this->as($assignee);
        $this->postJson('/api/operations/OP-001/complete')->assertStatus(409); // cannot complete before starting
        $this->postJson('/api/operations/OP-001/start')->assertOk();
        $this->postJson('/api/operations/OP-001/progress', ['percent' => 50, 'note' => 'half way'])->assertOk();
        $this->postJson('/api/operations/OP-001/complete', ['note' => 'done'])->assertOk();
        $this->getJson('/api/dashboard/user')
            ->assertOk()
            ->assertJsonPath('performance.completed', 1);

        // OP-002 only needed OP-001: it is READY now; OP-003 still waits for OP-002
        $this->as('manager');
        $this->getJson('/api/operations/OP-002')->assertJsonPath('operation.status', 'READY');
        $this->getJson('/api/operations/OP-003')->assertJsonPath('operation.status', 'NOT_READY');

        $actions = collect($this->getJson('/api/audit?entityType=Operation&entityId=OP-001')->assertOk()->json())
            ->pluck('action')->all();
        $this->assertContains('STATUS_CHANGE', $actions);
        $this->assertContains('ASSIGN', $actions);
    }

    public function test_llm_mode_without_api_key_falls_back_visibly_and_the_mode_can_be_switched(): void
    {
        $this->as('owner');
        $this->postJson('/api/admin/demo-data')->assertOk();

        $this->as('manager');
        $this->putJson('/api/settings/assignment-mode', ['mode' => 'LLM'])->assertOk();
        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('assignmentMode', 'LLM')
            ->assertJsonPath('llmAvailable', false);

        // no mode in the body: the configured one (LLM) is used, there is no key, so the answer says it fell back
        $run = $this->postJson('/api/assignments/suggest')->assertOk()
            ->assertJsonPath('requestedMode', 'LLM')
            ->assertJsonPath('effectiveMode', 'ALGORITHM')
            ->assertJsonPath('fallback', true)
            ->json();
        $this->assertTrue(collect($run['warnings'])->contains(fn (string $w) => str_contains($w, 'ANTHROPIC_API_KEY')));

        $this->putJson('/api/settings/assignment-mode', ['mode' => 'ALGORITHM'])->assertOk();
    }

    public function test_an_unreadable_file_is_rejected_and_nothing_is_changed(): void
    {
        $this->as('owner');
        $file = UploadedFile::fake()->createWithContent('bad.xlsx', "this is not a workbook\n");
        $this->post('/api/import?apply=1', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 'REJECTED');
        $this->assertSame([], $this->getJson('/api/operations')->assertOk()->json());
    }

    public function test_template_and_report_downloads_are_real_workbooks(): void
    {
        $this->as('owner');
        $this->get('/api/import/template?lang=en')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get('/api/reports/export?lang=fa')->assertOk();
    }

    public function test_force_preview_and_apply_return_the_board(): void
    {
        $this->as('owner');
        $this->postJson('/api/admin/demo-data')->assertOk();

        $this->as('manager');
        $start = now('Asia/Tehran')->addDays(3)->setTime(8, 0)->format('Y-m-d H:i');
        $this->postJson('/api/scheduling/forces/OP-011/preview', ['start' => $start])
            ->assertOk()
            ->assertJsonStructure(['feasible', 'operationId', 'conflicts', 'warnings', 'moved', 'projects']);
        $this->putJson('/api/scheduling/forces/OP-011', ['start' => $start, 'reason' => 'customer date'])
            ->assertOk()
            ->assertJsonStructure(['forces', 'conflicts', 'escalated', 'baselineSavedAt']);
        $this->getJson('/api/scheduling/forces')->assertOk()->assertJsonPath('forces.0.operationId', 'OP-011');
    }
}

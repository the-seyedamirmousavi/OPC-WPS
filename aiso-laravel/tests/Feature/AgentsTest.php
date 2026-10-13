<?php

namespace Tests\Feature;

use App\Models\AppRole;
use App\Exceptions\ApiException;
use App\Models\AgentTask;
use App\Models\AiPendingAction;
use App\Models\AiRun;
use App\Models\AppUser;
use App\Models\Notification;
use App\Services\AgentService;
use App\Services\OperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** AI agents: virtual users in the users table, labelled, who do jobs the owner or a manager gives them. */
class AgentsTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    private function makeAgent(array $body = []): array
    {
        $this->as('owner');

        return $this->postJson('/api/agents', $body + ['name' => 'Report writer', 'instructions' => 'Summarise the state of production.'])
            ->assertOk()->json();
    }

    // ---- they are users, with a label, and nothing more -------------------------------------------------------

    public function test_an_agent_is_a_labelled_user_in_the_users_table(): void
    {
        $this->production();
        $a = $this->makeAgent();

        $row = AppUser::query()->findOrFail($a['id']);
        $this->assertSame(AppRole::AGENT, $row->user_role);
        $this->assertTrue($row->isAgent());
        $this->assertSame('owner', $row->agent_created_by);
        $this->assertEqualsCanonicalizing(['hr', 'production'], $a['scope']);

        $users = collect($this->getJson('/api/users')->assertOk()->json());
        $listed = $users->firstWhere('id', $a['id']);
        $this->assertSame(['AGENT', true, 'Report writer'], [$listed['role'], $listed['isAgent'], $listed['fullName']]);
        $this->assertFalse($users->firstWhere('id', 'owner')['isAgent']);
    }

    public function test_nobody_can_sign_in_as_an_agent(): void
    {
        $a = $this->makeAgent();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', ['userId' => $a['id'], 'password' => 'anything'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['userId' => $a['id'], 'password' => ''])->assertStatus(400);
    }

    public function test_the_users_api_cannot_make_or_change_an_agent(): void
    {
        $a = $this->makeAgent();

        $this->postJson('/api/users', ['id' => 'sneaky', 'fullName' => 'S', 'role' => 'AGENT', 'password' => 'Passw0rd!long'])->assertStatus(400);
        $this->patchJson('/api/users/user1', ['role' => 'AGENT'])->assertStatus(400);
        $this->patchJson("/api/users/{$a['id']}", ['active' => false])->assertForbidden();
        $this->postJson("/api/users/{$a['id']}/reset-password", ['newPassword' => 'Passw0rd!long'])->assertForbidden();
    }

    public function test_an_agent_is_not_an_executor_and_takes_no_licence_seat(): void
    {
        $this->operation('OP-1', 'READY');
        $a = $this->makeAgent();

        $this->assertFalse(AppRole::query()->find(AppRole::AGENT)->has('tasks.receive'));
        try {
            app(OperationService::class)->assign('OP-1', $a['id'], null, null, 'manager', null);
            $this->fail('an agent was given an operation');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->status);
        }
        // only the five people count against the licence
        $this->assertSame(5, AppUser::query()->people()->where('active', true)->count());
        $this->assertSame(6, AppUser::query()->where('active', true)->count());
    }

    public function test_an_agent_cannot_be_made_a_department_member(): void
    {
        $a = $this->makeAgent();
        $d = $this->production();

        $this->putJson("/api/departments/{$d->id}/members", ['members' => [['userId' => $a['id'], 'role' => 'MEMBER']]])->assertStatus(400);
    }

    // ---- only owner and managers make them; the owner manages them --------------------------------------------

    public function test_only_the_owner_uses_the_agents_api(): void
    {
        $a = $this->makeAgent();

        foreach (['manager', 'user1'] as $who) {
            $this->as($who);
            $this->getJson('/api/agents')->assertForbidden();
            $this->postJson('/api/agents', ['name' => 'x', 'instructions' => 'y'])->assertForbidden();
            $this->patchJson("/api/agents/{$a['id']}", ['active' => false])->assertForbidden();
            $this->postJson("/api/agents/{$a['id']}/tasks", ['task' => 'x'])->assertForbidden();
        }
        $this->as('owner');
        $this->getJson('/api/agents')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $a['id']);
    }

    public function test_an_executor_cannot_make_an_agent_even_through_the_service(): void
    {
        $this->expectException(ApiException::class);
        app(AgentService::class)->create($this->user('user1'), 'x', 'y', null);
    }

    public function test_the_owner_renames_narrows_and_switches_off_an_agent(): void
    {
        $this->production();
        $a = $this->makeAgent();

        $this->patchJson("/api/agents/{$a['id']}", ['name' => 'Night reporter', 'scope' => ['hr'], 'active' => false])->assertOk()
            ->assertJsonPath('name', 'Night reporter')->assertJsonPath('scope', ['hr'])->assertJsonPath('active', false);

        $this->postJson("/api/agents/{$a['id']}/tasks", ['task' => 'work'])->assertStatus(409);
        $this->patchJson("/api/agents/{$a['id']}", ['scope' => ['dept-999']])->assertStatus(400);
        $this->patchJson("/api/agents/{$a['id']}", ['scope' => []])->assertOk()->assertJsonPath('scope', []);
    }

    public function test_the_number_of_active_agents_is_limited(): void
    {
        config(['aiso.agent.max_active_agents' => 1]);
        $this->makeAgent();

        $this->postJson('/api/agents', ['name' => 'second', 'instructions' => 'y'])->assertStatus(409);
    }

    // ---- tasks: what an agent does ------------------------------------------------------------------------------

    public function test_a_task_runs_after_the_response_and_the_result_goes_to_the_person(): void
    {
        $ai = $this->fakeAi([F::route('fetch', [F::req('hr', 'users_summary')]), F::answer('پنج کاربر فعال داریم.')]);
        $a = $this->makeAgent();

        $r = $this->postJson("/api/agents/{$a['id']}/tasks", ['task' => 'تعداد کاربران را گزارش بده'])->assertOk();
        $this->assertSame('PENDING', $r->json('status')); // the answer to the request did not wait for the AI

        $task = AgentTask::query()->findOrFail($r->json('id'));
        $this->assertSame(['DONE', 'پنج کاربر فعال داریم.'], [$task->status, $task->result]);
        $this->assertNotNull($task->finished_at);
        // the run is logged under the agent, not under the owner
        $this->assertSame($a['id'], AiRun::query()->findOrFail($task->run_id)->user_id);
        $this->assertStringContainsString('Report writer', $ai->input(0)['question']);
        // the standing job description travels in the briefing, on every stage
        $this->assertSame('Summarise the state of production.', $ai->input(0)['briefing']['agent']['instructions']);
        $this->assertSame('Summarise the state of production.', $ai->input(1)['briefing']['agent']['instructions']);

        $n = Notification::query()->where('recipient_id', 'owner')->where('kind', 'AGENT_TASK')->firstOrFail();
        $this->assertStringContainsString('پنج کاربر فعال داریم.', $n->message);
        $this->getJson("/api/agents/{$a['id']}/tasks")->assertOk()->assertJsonPath('0.status', 'DONE');
    }

    public function test_an_agent_reads_only_the_departments_it_was_given_and_never_writes(): void
    {
        $this->production();
        $ai = $this->fakeAi([
            F::route('answer'),
            F::answer('done', [['department' => 'production', 'operation' => 'cancel_operation', 'params' => ['operation_id' => 'OP-1', 'reason' => 'x'], 'reason' => 'x']]),
        ]);
        $a = $this->makeAgent(['scope' => ['hr']]);

        $this->postJson("/api/agents/{$a['id']}/tasks", ['task' => 'look around'])->assertOk();

        $this->assertSame(['hr'], array_column($ai->input(0)['departments'], 'slug'));
        $this->assertSame([], $ai->input(1)['allowed_actions']);
        $this->assertSame(0, AiPendingAction::query()->count());
    }

    public function test_a_failing_ai_marks_the_task_failed_and_tells_the_person(): void
    {
        $this->fakeAi([new ApiException(503, 'The AI assistant is not available. The owner can set it up in System administration.')]);
        $a = $this->makeAgent();

        $id = $this->postJson("/api/agents/{$a['id']}/tasks", ['task' => 'work'])->assertOk()->json('id');

        $task = AgentTask::query()->findOrFail($id);
        $this->assertSame('FAILED', $task->status);
        $this->assertNotEmpty($task->error);
        $this->assertTrue(Notification::query()->where('recipient_id', 'owner')->where('message', 'like', '%could not finish%')->exists());
    }

    public function test_a_task_whose_maker_is_no_longer_active_does_not_run(): void
    {
        $ai = $this->fakeAi([F::route('answer'), F::answer('x')]);
        $a = $this->makeAgent();
        AppUser::query()->whereKey('owner')->update(['active' => false]);
        $task = AgentTask::query()->create(['agent_id' => $a['id'], 'created_by' => 'owner', 'instruction' => 'x', 'status' => 'PENDING', 'created_at' => now()]);

        app(AgentService::class)->runTask($task->id);

        $this->assertSame('FAILED', $task->refresh()->status);
        $this->assertCount(0, $ai->calls);
    }

    public function test_a_task_is_taken_only_once(): void
    {
        $ai = $this->fakeAi([F::route('answer'), F::answer('once')]);
        $a = $this->makeAgent();
        $task = AgentTask::query()->create(['agent_id' => $a['id'], 'created_by' => 'owner', 'instruction' => 'x', 'status' => 'PENDING', 'created_at' => now()]);

        app(AgentService::class)->runTask($task->id);
        app(AgentService::class)->runTask($task->id);

        $this->assertCount(2, $ai->calls);
    }

    public function test_a_task_that_never_finished_is_marked_failed_when_listed(): void
    {
        $a = $this->makeAgent();
        $t = AgentTask::query()->create(['agent_id' => $a['id'], 'created_by' => 'owner', 'instruction' => 'x', 'status' => 'RUNNING',
            'created_at' => now()->subHour(), 'started_at' => now()->subMinutes(30)]);

        $this->getJson('/api/agents')->assertOk()->assertJsonPath('0.tasks.0.status', 'FAILED');
        $this->assertSame('FAILED', $t->refresh()->status);
    }

    // ---- the manager commands the assistant to make an agent --------------------------------------------------------

    public function test_a_manager_tells_the_assistant_to_make_an_agent_and_it_runs_after_confirmation(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->operation('OP-1', 'BLOCKED');
        $ai = $this->fakeAi([
            // the chat turn
            F::route('answer'),
            F::answer('یک ایجنت می‌سازم که عملیات مسدود را بررسی کند. لطفاً تأیید کنید.', [[
                'department' => 'production', 'operation' => 'create_agent', 'reason' => 'درخواست مدیر',
                'params' => ['name' => 'بازرس مسدودی‌ها', 'instructions' => 'عملیات مسدود را بررسی و گزارش کن.',
                    'task' => 'عملیات مسدود را گزارش بده', 'scope' => ['production']],
            ]]),
            // the agent's own task, run after the confirmation
            F::route('fetch', [F::req('production', 'list_by_status', ['status' => 'BLOCKED'])]),
            F::answer('یک عملیات مسدود دارید: OP-1.'),
        ]);
        $this->as('manager');

        $pending = $this->postJson('/api/chat', ['message' => 'یک ایجنت بساز که عملیات مسدود را بررسی کند'])->assertOk()
            ->assertJsonCount(1, 'pending_actions')->assertJsonPath('pending_actions.0.risk', 'high')
            ->assertJsonPath('pending_actions.0.operation', 'create_agent')->json('pending_actions.0');
        // proposing creates nothing
        $this->assertSame(0, AppUser::query()->where('user_role', 'AGENT')->count());

        // making a user is high risk: the manager cannot confirm it, the owner must approve it
        $this->assertSame('AWAITING_OWNER', $pending['status']);
        $this->postJson("/api/chat/actions/{$pending['id']}/confirm")->assertStatus(409);
        $this->assertSame(0, AppUser::query()->where('user_role', 'AGENT')->count());

        $this->as('owner');
        $this->postJson("/api/ai/approvals/{$pending['id']}/approve")->assertOk()->assertJsonPath('status', 'CONFIRMED');
        $this->as('manager');

        $agent = AppUser::query()->where('user_role', 'AGENT')->firstOrFail();
        $this->assertSame(['بازرس مسدودی‌ها', 'manager', ['production']], [$agent->full_name, $agent->agent_created_by, $agent->agentScope()]);
        $task = AgentTask::query()->firstOrFail();
        $this->assertSame(['DONE', 'manager', 'یک عملیات مسدود دارید: OP-1.'], [$task->status, $task->created_by, $task->result]);
        $this->assertSame('OP-1', $ai->input(3)['data'][0]['data'][0]['id']);
        $this->assertTrue(Notification::query()->where('recipient_id', 'manager')->where('kind', 'AGENT_TASK')->exists());

        // the owner sees it in human resources; the manager has no API for it
        $this->as('owner');
        $this->getJson('/api/agents')->assertOk()->assertJsonPath('0.createdBy', 'manager');
    }

    public function test_an_agent_can_never_be_wider_than_the_person_who_makes_it(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);

        // a manager is not offered human resources, so an agent of theirs cannot read it
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Department hr is not available to you');
        app(AgentService::class)->create($this->user('manager'), 'x', 'y', ['hr']);
    }

    public function test_a_manager_cannot_give_a_task_to_someone_elses_agent(): void
    {
        $a = $this->makeAgent();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This agent belongs to someone else');
        app(AgentService::class)->assignTask($this->user('manager'), $a['id'], 'work');
    }

    public function test_a_manager_without_a_department_is_not_offered_the_agent_actions(): void
    {
        $ai = $this->fakeAi([F::route('answer'), F::answer('ok')]);
        $this->as('manager');

        $this->postJson('/api/chat', ['message' => 'یک ایجنت بساز'])->assertOk();

        $this->assertSame([], $ai->input(1)['allowed_actions']);
    }
}

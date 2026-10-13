<?php

namespace Tests\Feature\AI;

use App\Models\AiPendingAction;
use App\Models\AppUser;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\DepartmentSuggestion;
use App\Models\Notification;
use App\Models\WorkResource;
use App\Services\DepartmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentFake;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The owner decides what the assistant may do: policy per action, a master switch, and the owner's approval. */
class ApprovalTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    private ?AgentFake $ai = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->operation('OP-1', 'BLOCKED');
    }

    /** One fake for the whole test (the router keeps the controller, and so the AI service it was built with). */
    private function ai(): AgentFake
    {
        return $this->ai ??= $this->fakeAi([]);
    }

    /** $who asks the assistant, which proposes these actions; returns the chat response. */
    private function ask(string $who, array $actions, string $department = 'production'): \Illuminate\Testing\TestResponse
    {
        $this->ai()->push(F::route('answer'), F::answer('ok', array_map(fn (array $a) => $a + ['department' => $department, 'reason' => 'test'], $actions)));
        $this->as($who);

        return $this->postJson('/api/chat', ['message' => 'do it'])->assertOk();
    }

    private function cancelOp(string $who = 'manager'): \Illuminate\Testing\TestResponse
    {
        return $this->ask($who, [['operation' => 'cancel_operation', 'params' => ['operation_id' => 'OP-1', 'reason' => 'obsolete']]]);
    }

    private function opStatus(string $id = 'OP-1'): string
    {
        return DB::table('operation')->where('id', $id)->value('status');
    }

    private function setMode(string $kind, string $action, string $mode): void
    {
        $this->as('owner');
        $this->putJson('/api/ai/policies', ['writesEnabled' => true, 'actions' => [['kind' => $kind, 'action' => $action, 'mode' => $mode]]])->assertOk();
    }

    // ---- the owner's approval ------------------------------------------------------------------------------------

    public function test_a_managers_high_risk_request_waits_for_the_owner_and_changes_nothing(): void
    {
        $r = $this->cancelOp()->assertJsonCount(1, 'pending_actions');

        $this->assertSame(['AWAITING_OWNER', 'OWNER', 'high'], [$r->json('pending_actions.0.status'), $r->json('pending_actions.0.approval'), $r->json('pending_actions.0.risk')]);
        $this->assertSame('BLOCKED', $this->opStatus());
        $this->assertTrue(Notification::query()->where('recipient_id', 'owner')->where('kind', 'AI_APPROVAL')->exists());

        // the manager cannot approve their own request, but can withdraw it
        $id = $r->json('pending_actions.0.id');
        $this->postJson("/api/chat/actions/{$id}/confirm")->assertStatus(409);
        $this->postJson("/api/chat/actions/{$id}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertSame('BLOCKED', $this->opStatus());
    }

    public function test_the_owner_approves_and_it_runs_as_the_manager(): void
    {
        $id = $this->cancelOp()->json('pending_actions.0.id');

        $this->as('owner');
        $queue = $this->getJson('/api/ai/approvals')->assertOk()->assertJsonCount(1)->json();
        $this->assertSame(['manager', 'Project Manager', 'cancel_operation'], [$queue[0]['requested_by'], $queue[0]['requested_by_name'], $queue[0]['operation']]);

        $this->postJson("/api/ai/approvals/{$id}/approve", ['note' => 'fine'])->assertOk()->assertJsonPath('status', 'CONFIRMED');

        $this->assertSame('CANCELLED', $this->opStatus());
        $row = AiPendingAction::query()->findOrFail($id);
        $this->assertSame(['owner', 'fine'], [$row->approved_by, $row->decision_note]);
        // the service saw the manager as the actor, and the audit trail names the approval
        $event = AuditEvent::query()->where('action_name', 'AI_ACTION_EXECUTED')->firstOrFail();
        $this->assertSame('manager', $event->actor_id);
        $this->assertStringContainsString('approved by the owner owner', (string) $event->reason);
        $this->assertTrue(Notification::query()->where('recipient_id', 'manager')->where('message', 'like', '%owner approved%')->exists());
        $this->getJson('/api/ai/approvals')->assertJsonCount(0);
    }

    public function test_the_owner_rejects_and_the_manager_is_told_why(): void
    {
        $id = $this->cancelOp()->json('pending_actions.0.id');

        $this->as('owner');
        $this->postJson("/api/ai/approvals/{$id}/reject", ['note' => 'not now'])->assertOk()->assertJsonPath('status', 'REJECTED');

        $this->assertSame('BLOCKED', $this->opStatus());
        $this->assertTrue(Notification::query()->where('recipient_id', 'manager')->where('message', 'like', '%rejected%not now%')->exists());
        $this->postJson("/api/ai/approvals/{$id}/approve")->assertStatus(409);
    }

    public function test_only_the_owner_reaches_the_approval_and_policy_screens(): void
    {
        $id = $this->cancelOp()->json('pending_actions.0.id');

        foreach (['manager', 'user1'] as $who) {
            $this->as($who);
            $this->getJson('/api/ai/approvals')->assertForbidden();
            $this->postJson("/api/ai/approvals/{$id}/approve")->assertForbidden();
            $this->postJson("/api/ai/approvals/{$id}/reject")->assertForbidden();
            $this->getJson('/api/ai/policies')->assertForbidden();
            $this->putJson('/api/ai/policies', ['writesEnabled' => true, 'actions' => []])->assertForbidden();
        }
        $this->assertSame('BLOCKED', $this->opStatus());
    }

    public function test_when_the_owner_asks_the_owners_own_confirmation_is_the_approval(): void
    {
        $this->production();
        $r = $this->cancelOp('owner');

        $this->assertSame('PENDING', $r->json('pending_actions.0.status'));
        $this->getJson('/api/ai/approvals')->assertJsonCount(0);
        $this->postJson('/api/chat/actions/' . $r->json('pending_actions.0.id') . '/confirm')->assertOk();
        $this->assertSame('CANCELLED', $this->opStatus());
    }

    public function test_an_approval_that_waited_too_long_expires(): void
    {
        $id = $this->cancelOp()->json('pending_actions.0.id');
        AiPendingAction::query()->whereKey($id)->update(['expires_at' => now()->subMinute()]);

        $this->as('owner');
        $this->getJson('/api/ai/approvals')->assertJsonCount(0);
        $this->postJson("/api/ai/approvals/{$id}/approve")->assertStatus(409);
        $this->assertSame('EXPIRED', AiPendingAction::query()->findOrFail($id)->status);
        $this->assertSame('BLOCKED', $this->opStatus());
    }

    // ---- everything is checked again when the owner approves ------------------------------------------------------

    public function test_the_policy_is_checked_again_at_approval(): void
    {
        $id = $this->cancelOp()->json('pending_actions.0.id');
        $this->setMode('production', 'cancel_operation', 'DENY');

        $this->postJson("/api/ai/approvals/{$id}/approve")->assertForbidden();

        $this->assertSame('BLOCKED', $this->opStatus());
        $this->assertSame('FAILED', AiPendingAction::query()->findOrFail($id)->status);
    }

    public function test_the_requesters_access_is_checked_again_at_approval(): void
    {
        $id = $this->cancelOp()->json('pending_actions.0.id');
        $this->staff($this->production(), []); // the manager left the department meanwhile

        $this->as('owner');
        $this->postJson("/api/ai/approvals/{$id}/approve")->assertForbidden();

        $this->assertSame('BLOCKED', $this->opStatus());
    }

    public function test_a_requester_who_is_no_longer_active_cannot_be_acted_for(): void
    {
        $id = $this->cancelOp()->json('pending_actions.0.id');
        AppUser::query()->whereKey('manager')->update(['active' => false]);

        $this->as('owner');
        $this->postJson("/api/ai/approvals/{$id}/approve")->assertStatus(409);

        $this->assertSame('BLOCKED', $this->opStatus());
        $this->assertSame('FAILED', AiPendingAction::query()->findOrFail($id)->status);
    }

    public function test_a_business_rule_failure_at_approval_is_reported_and_nothing_changes(): void
    {
        $id = $this->cancelOp()->json('pending_actions.0.id');
        DB::table('operation')->where('id', 'OP-1')->update(['status' => 'COMPLETED', 'status_before_block' => null]);

        $this->as('owner');
        $this->postJson("/api/ai/approvals/{$id}/approve")->assertStatus(409);

        $this->assertSame('COMPLETED', $this->opStatus());
        $this->assertSame('FAILED', AiPendingAction::query()->findOrFail($id)->status);
    }

    // ---- the owner's policy ------------------------------------------------------------------------------------------

    public function test_the_policy_lists_every_action_with_a_default_that_follows_its_risk(): void
    {
        $this->as('owner');

        $c = collect($this->getJson('/api/ai/policies')->assertOk()->json('actions'));

        $this->assertSame('OWNER', $c->first(fn ($a) => $a['kind'] === 'production' && $a['action'] === 'cancel_operation')['mode']);
        $this->assertSame('SELF', $c->first(fn ($a) => $a['kind'] === 'production' && $a['action'] === 'assign_operation')['mode']);
        $this->assertSame('AUTO', $c->first(fn ($a) => $a['kind'] === 'production' && $a['action'] === 'comment_on_operation')['mode']);
        $this->assertTrue($this->getJson('/api/ai/policies')->json('writesEnabled'));
    }

    public function test_a_disabled_action_is_neither_offered_nor_accepted(): void
    {
        $this->setMode('production', 'cancel_operation', 'DENY');

        $r = $this->cancelOp();

        $r->assertJsonPath('pending_actions', []);
        $offered = array_column($this->ai()->input(count($this->ai()->calls) - 1)['allowed_actions'], 'operation');
        $this->assertNotContains('cancel_operation', $offered);
        $this->assertContains('unblock_operation', $offered);
        $this->assertSame(0, AiPendingAction::query()->count());
    }

    public function test_the_master_switch_makes_the_assistant_read_only(): void
    {
        $this->as('owner');
        $this->putJson('/api/ai/policies', ['writesEnabled' => false, 'actions' => []])->assertOk()->assertJsonPath('writesEnabled', false);

        $this->ask('manager', [['operation' => 'unblock_operation', 'params' => ['operation_id' => 'OP-1']]])->assertJsonPath('pending_actions', []);

        $this->assertSame([], $this->ai()->input(count($this->ai()->calls) - 1)['allowed_actions']);
        $this->assertSame('BLOCKED', $this->opStatus());

        $this->as('owner');
        $this->putJson('/api/ai/policies', ['writesEnabled' => true, 'actions' => []])->assertOk();
        $this->ask('manager', [['operation' => 'unblock_operation', 'params' => ['operation_id' => 'OP-1']]])->assertJsonCount(1, 'pending_actions');
    }

    public function test_only_a_low_risk_action_can_run_without_confirmation(): void
    {
        $this->as('owner');

        $this->putJson('/api/ai/policies', ['writesEnabled' => true, 'actions' => [['kind' => 'production', 'action' => 'cancel_operation', 'mode' => 'AUTO']]])
            ->assertStatus(400);
        $this->putJson('/api/ai/policies', ['writesEnabled' => true, 'actions' => [['kind' => 'production', 'action' => 'invent', 'mode' => 'SELF']]])->assertStatus(400);
        $this->putJson('/api/ai/policies', ['writesEnabled' => true, 'actions' => [['kind' => 'production', 'action' => 'cancel_operation', 'mode' => 'NEVER']]])->assertStatus(400);
        $this->assertTrue(AuditEvent::query()->where('action_name', 'AI_POLICY_UPDATED')->doesntExist());
    }

    public function test_the_owner_can_make_an_action_stricter_or_looser_and_it_is_audited(): void
    {
        $this->setMode('production', 'unblock_operation', 'OWNER'); // stricter
        $r = $this->ask('manager', [['operation' => 'unblock_operation', 'params' => ['operation_id' => 'OP-1']]]);
        $this->assertSame('AWAITING_OWNER', $r->json('pending_actions.0.status'));

        $this->setMode('production', 'cancel_operation', 'SELF'); // looser
        $r = $this->cancelOp();
        $this->assertSame('PENDING', $r->json('pending_actions.0.status'));
        $this->assertTrue(AuditEvent::query()->where('action_name', 'AI_POLICY_UPDATED')->exists());
    }

    public function test_a_low_risk_action_runs_at_once_unless_the_owner_says_otherwise(): void
    {
        $r = $this->ask('manager', [['operation' => 'comment_on_operation', 'params' => ['operation_id' => 'OP-1', 'note' => 'checked by assistant']]]);

        $r->assertJsonPath('pending_actions', []);
        $this->assertSame(['operation' => 'comment_on_operation', 'operation_id' => 'OP-1'], array_intersect_key($r->json('executed.0'), array_flip(['operation', 'operation_id'])));
        $this->assertTrue(DB::table('operation_report')->where('operation_id', 'OP-1')->where('note', 'checked by assistant')->exists());

        $this->setMode('production', 'comment_on_operation', 'DENY');
        $this->ask('manager', [['operation' => 'comment_on_operation', 'params' => ['operation_id' => 'OP-1', 'note' => 'again']]])->assertJsonPath('executed', []);
        $this->assertFalse(DB::table('operation_report')->where('note', 'again')->exists());
    }

    // ---- the wider catalogue: real service calls ---------------------------------------------------------------------

    public function test_assigning_an_operation_keeps_its_planned_dates(): void
    {
        $this->operation('OP-2', 'READY');
        DB::table('operation')->where('id', 'OP-2')->update(['planned_start' => '2026-11-01 08:00:00', 'planned_end' => '2026-11-02 08:00:00']);
        $id = $this->ask('manager', [['operation' => 'assign_operation', 'params' => ['operation_id' => 'OP-2', 'user_id' => 'user1']]])->json('pending_actions.0.id');

        $this->postJson("/api/chat/actions/{$id}/confirm")->assertOk();

        $op = DB::table('operation')->where('id', 'OP-2')->first();
        $this->assertSame(['ASSIGNED', 'user1'], [$op->status, $op->assigned_user_id]);
        $this->assertStringStartsWith('2026-11-01', $op->planned_start);
        $this->assertStringStartsWith('2026-11-02', $op->planned_end);
    }

    public function test_an_operation_cannot_be_assigned_to_an_agent_or_the_owner(): void
    {
        $this->operation('OP-2', 'READY');
        $id = $this->ask('manager', [['operation' => 'assign_operation', 'params' => ['operation_id' => 'OP-2', 'user_id' => 'owner']]])->json('pending_actions.0.id');

        $this->postJson("/api/chat/actions/{$id}/confirm")->assertStatus(400);

        $this->assertSame('READY', $this->opStatus('OP-2'));
    }

    public function test_a_project_can_be_renamed_and_given_a_due_date(): void
    {
        $id = $this->ask('manager', [['operation' => 'update_project', 'params' => ['project_id' => 'P1', 'name' => 'Renamed by the assistant', 'due_date' => '2026-12-30']]])
            ->json('pending_actions.0.id');

        $this->postJson("/api/chat/actions/{$id}/confirm")->assertOk();

        $p = DB::table('project')->where('id', 'P1')->first();
        $this->assertSame('Renamed by the assistant', $p->name);
        $this->assertStringStartsWith('2026-12-30', $p->due_date);
    }

    public function test_a_department_resource_is_edited_through_the_department_service_and_its_permission(): void
    {
        $finance = Department::query()->create(['name' => 'Finance', 'status' => 'ACTIVE', 'sort_order' => 9, 'created_at' => now()]);
        $svc = app(DepartmentService::class);
        $svc->setPermissions($finance->id, ['HEAD' => ['PANEL_VIEW', 'RESOURCES_MANAGE'], 'MEMBER' => ['PANEL_VIEW']], $this->user('owner'));
        $this->staff($finance, [['manager', 'HEAD'], ['user1', 'MEMBER']]);
        WorkResource::query()->create(['id' => 'R-9', 'name' => 'Press', 'capacity' => 1, 'status' => 'ACTIVE', 'sort_order' => 1, 'department_id' => $finance->id]);
        $slug = "dept-{$finance->id}";

        // a member without the permission is stopped before anything is proposed
        $this->ask('user1', [['operation' => 'update_resource', 'params' => ['resource_id' => 'R-9', 'capacity' => 5]]], $slug)->assertJsonPath('pending_actions', []);
        $this->assertSame(1, WorkResource::query()->find('R-9')->capacity);

        $id = $this->ask('manager', [['operation' => 'update_resource', 'params' => ['resource_id' => 'R-9', 'capacity' => 5, 'description' => 'second shift']]], $slug)
            ->json('pending_actions.0.id');
        $this->postJson("/api/chat/actions/{$id}/confirm")->assertOk();

        $r = WorkResource::query()->findOrFail('R-9');
        $this->assertSame([5, 'second shift'], [$r->capacity, $r->description]);
        $this->assertNotNull($r->reviewed_at);
    }

    public function test_a_suggestion_is_sent_at_once_and_decided_by_a_manager_after_confirmation(): void
    {
        // made through the service, so it has the default permissions a real department has
        $finance = Department::query()->findOrFail(app(DepartmentService::class)->create('Finance', null, $this->user('owner'))['id']);
        $this->staff($finance, [['manager', 'HEAD'], ['user1', 'MEMBER']]);
        $slug = "dept-{$finance->id}";

        $r = $this->ask('user1', [['operation' => 'submit_suggestion', 'params' => ['title' => 'More budget', 'body' => 'We need it.']]], $slug);
        $r->assertJsonPath('pending_actions', []);
        $suggestion = DepartmentSuggestion::query()->firstOrFail();
        $this->assertSame(['More budget', 'user1', 'PENDING'], [$suggestion->title, $suggestion->author_id, $suggestion->status->value]);

        // an executor cannot decide it, a manager can
        $this->ask('user1', [['operation' => 'decide_suggestion', 'params' => ['suggestion_id' => $suggestion->id, 'decision' => 'ACCEPTED']]], $slug)->assertJsonPath('pending_actions', []);
        $id = $this->ask('manager', [['operation' => 'decide_suggestion', 'params' => ['suggestion_id' => $suggestion->id, 'decision' => 'ACCEPTED', 'note' => 'ok']]], $slug)
            ->json('pending_actions.0.id');
        $this->postJson("/api/chat/actions/{$id}/confirm")->assertOk();

        $this->assertSame('ACCEPTED', $suggestion->refresh()->status->value);
    }

    public function test_the_owner_changes_a_users_role_and_activity_and_department_members_through_human_resources(): void
    {
        $production = $this->production();
        $do = function (string $operation, array $params) {
            $id = $this->ask('owner', [['operation' => $operation, 'params' => $params]], 'hr')->assertJsonPath('pending_actions.0.status', 'PENDING')->json('pending_actions.0.id');
            $this->postJson("/api/chat/actions/{$id}/confirm")->assertOk();
        };

        $do('set_user_role', ['user_id' => 'user3', 'role' => 'MANAGER']);
        $this->assertSame('MANAGER', AppUser::query()->find('user3')->user_role);

        $do('set_user_active', ['user_id' => 'user2', 'active' => false]);
        $this->assertFalse((bool) AppUser::query()->find('user2')->active);

        $do('add_department_member', ['department_id' => $production->id, 'user_id' => 'user1', 'role' => 'MEMBER']);
        $this->assertSame('MEMBER', DepartmentMember::query()->where('department_id', $production->id)->where('user_id', 'user1')->firstOrFail()->role->value);
        $this->assertSame(2, DepartmentMember::query()->where('department_id', $production->id)->count()); // the manager stayed

        $do('remove_department_member', ['department_id' => $production->id, 'user_id' => 'user1']);
        $this->assertFalse(DepartmentMember::query()->where('department_id', $production->id)->where('user_id', 'user1')->exists());
    }

    public function test_the_assistant_cannot_hand_out_the_owner_role_or_an_agent_role(): void
    {
        $this->ask('owner', [
            ['operation' => 'set_user_role', 'params' => ['user_id' => 'user3', 'role' => 'OWNER']],
            ['operation' => 'set_user_role', 'params' => ['user_id' => 'user3', 'role' => 'AGENT']],
        ], 'hr')->assertJsonPath('pending_actions', []);

        $this->assertSame('USER_3', AppUser::query()->find('user3')->user_role);
    }

    public function test_a_manager_is_not_offered_the_human_resources_actions_at_all(): void
    {
        $this->ask('manager', [['operation' => 'set_user_role', 'params' => ['user_id' => 'user3', 'role' => 'MANAGER']]], 'hr')->assertJsonPath('pending_actions', []);

        $this->assertSame('USER_3', AppUser::query()->find('user3')->user_role);
    }
}

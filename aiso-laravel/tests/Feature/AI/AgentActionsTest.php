<?php

namespace Tests\Feature\AI;

use App\Models\AiPendingAction;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The AI only proposes. A change happens after the same user confirms it, in time, and the backend re-checks it. */
class AgentActionsTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->operation('OP-12', 'BLOCKED');
    }

    private function opStatus(string $id = 'OP-12'): string
    {
        return DB::table('operation')->where('id', $id)->value('status');
    }

    /** The manager asks to unblock OP-12; returns the pending action id. */
    private function propose(string $operation = 'unblock_operation', array $params = ['operation_id' => 'OP-12']): int
    {
        $this->fakeAi([
            F::route('fetch', [F::req('production', 'operation_detail', ['operation_id' => 'OP-12'])]),
            F::answer('این کار را آماده می‌کنم، لطفاً تأیید کنید.', [
                ['department' => 'production', 'operation' => $operation, 'params' => $params, 'reason' => 'درخواست کاربر'],
            ]),
        ]);
        $this->as('manager');

        return $this->postJson('/api/chat', ['message' => 'رفع انسداد OP-12'])->assertOk()
            ->assertJsonCount(1, 'pending_actions')->json('pending_actions.0.id');
    }

    public function test_a_proposal_is_pending_and_changes_nothing_until_confirmed(): void
    {
        $id = $this->propose();

        $this->assertSame('BLOCKED', $this->opStatus());
        $row = AiPendingAction::query()->findOrFail($id);
        $this->assertSame(['PENDING', 'medium', 'manager', 'production'], [$row->status, $row->risk, $row->user_id, $row->department]);
        $this->assertTrue($row->expires_at->isFuture());
    }

    public function test_the_pending_action_shows_what_it_will_do(): void
    {
        $this->propose();
        $view = AiPendingAction::query()->firstOrFail();

        $this->assertSame('unblock_operation', $view->operation);
        $this->assertSame(['operation_id' => 'OP-12'], json_decode($view->params, true));
    }

    public function test_confirming_runs_it_through_the_operation_service_and_audits_it(): void
    {
        $id = $this->propose();

        $this->postJson("/api/chat/actions/{$id}/confirm")->assertOk()->assertJsonPath('status', 'CONFIRMED');

        $this->assertSame('READY', $this->opStatus());
        $this->assertTrue(AuditEvent::query()->where('action_name', 'AI_ACTION_EXECUTED')->where('actor_id', 'manager')->exists());
        // it cannot be run twice
        $this->postJson("/api/chat/actions/{$id}/confirm")->assertStatus(409);
    }

    public function test_a_high_risk_action_also_waits_and_a_cancelled_one_never_runs(): void
    {
        $id = $this->propose('cancel_operation', ['operation_id' => 'OP-12', 'reason' => 'no longer needed']);
        $this->assertSame('high', AiPendingAction::query()->findOrFail($id)->risk);
        $this->assertSame('BLOCKED', $this->opStatus());

        $this->postJson("/api/chat/actions/{$id}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("/api/chat/actions/{$id}/confirm")->assertStatus(409);

        $this->assertSame('BLOCKED', $this->opStatus());
    }

    public function test_an_expired_action_is_not_run(): void
    {
        $id = $this->propose();
        AiPendingAction::query()->whereKey($id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/api/chat/actions/{$id}/confirm")->assertStatus(410);

        $this->assertSame('BLOCKED', $this->opStatus());
        $this->assertSame('EXPIRED', AiPendingAction::query()->findOrFail($id)->status);
    }

    public function test_another_user_cannot_confirm_or_cancel_it(): void
    {
        $id = $this->propose();

        foreach (['owner', 'user1'] as $who) {
            $this->as($who);
            $this->postJson("/api/chat/actions/{$id}/confirm")->assertNotFound();
            $this->postJson("/api/chat/actions/{$id}/cancel")->assertNotFound();
        }

        $this->assertSame('BLOCKED', $this->opStatus());
        $this->assertSame('PENDING', AiPendingAction::query()->findOrFail($id)->status);
    }

    public function test_the_state_is_checked_again_at_confirmation(): void
    {
        $id = $this->propose();
        DB::table('operation')->where('id', 'OP-12')->update(['status' => 'READY', 'status_before_block' => null]);

        $this->postJson("/api/chat/actions/{$id}/confirm")->assertStatus(409);

        $this->assertSame('READY', $this->opStatus());
        $this->assertSame('FAILED', AiPendingAction::query()->findOrFail($id)->status);
    }

    public function test_access_is_checked_again_at_confirmation(): void
    {
        $id = $this->propose();
        $this->staff($this->production(), []); // the manager no longer belongs to the department

        $this->postJson("/api/chat/actions/{$id}/confirm")->assertForbidden();

        $this->assertSame('BLOCKED', $this->opStatus());
    }

    public function test_an_action_the_config_does_not_know_is_never_stored(): void
    {
        $this->fakeAi([F::route('answer'), F::answer('ok', [
            ['department' => 'production', 'operation' => 'delete_project', 'params' => ['id' => 'P1'], 'reason' => 'x'],
            ['department' => 'production', 'operation' => 'unblock_operation', 'params' => ['operation_id' => 'NOPE'], 'reason' => 'x'],
        ])]);
        $this->as('manager');

        $this->postJson('/api/chat', ['message' => 'x'])->assertOk()->assertJsonPath('pending_actions', []);

        $this->assertSame(0, AiPendingAction::query()->count());
    }
}

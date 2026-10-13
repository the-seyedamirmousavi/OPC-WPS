<?php

namespace Tests\Feature\AI;

use App\Models\Operation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * The assistant acts on the organisation's data instead of asking: it can add an operation (through the plan builder's
 * path, with the approval policy), let the assignment algorithm pick the executor, tell when work is planned to finish,
 * keep the user's task across short replies, and answer with what it has when the reading limit is reached.
 */
class AssistantAutonomyTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
        config(['aiso.agent.engine' => 'dialogue']);
        $this->production();
        $this->operation('OP-1', 'READY');
        DB::table('work_resource')->insert(['id' => 'BUY', 'name' => 'خرید', 'capacity' => 1, 'status' => 'ACTIVE']);
    }

    public function test_the_assistant_adds_an_operation_which_runs_only_after_confirmation_and_reports_when_it_finishes(): void
    {
        $ai = $this->fakeAi([F::reply('عملیات خرید سیب را به پروژهٔ P1 روی منبع خرید پیشنهاد دادم؛ بعد از تأیید، زمان تحویل نشان داده می‌شود.', [
            F::act('production.add_operation', ['name' => 'خرید سیب', 'resource_id' => 'BUY', 'direct_hours' => 1, 'auto_assign' => true]),
        ])]);
        $this->as('owner');

        $r = $this->postJson('/api/chat', ['message' => 'یک عملیات خرید سیب اضافه کن و به اولین مجری آزاد بده، بگو کی می‌رسه'])->assertOk();

        $action = $r->json('pending_actions.0');
        $this->assertSame('add_operation', $action['operation']);
        $this->assertSame('PENDING', $action['status'], 'a medium-risk change waits for the user');
        $this->assertSame(0, Operation::query()->where('name', 'خرید سیب')->count(), 'nothing is added before the confirmation');

        $done = $this->postJson("/api/chat/actions/{$action['id']}/confirm")->assertOk()->json();

        $this->assertSame('CONFIRMED', $done['status']);
        $op = Operation::query()->where('name', 'خرید سیب')->firstOrFail();
        $this->assertSame('P1', $op->project_id);
        $this->assertSame('BUY', $op->resource_id);
        $this->assertSame($op->id, $done['result']['operation_id']);
        $this->assertNotNull($done['result']['planned_end'], 'the finish time comes from the scheduler');
        $this->assertTrue($done['result']['assigned_to'] !== null || $done['result']['not_assigned_because'] !== null);
        if ($done['result']['assigned_to'] !== null) {
            $this->assertSame('ASSIGNED', $op->status->value);
            $this->assertSame($done['result']['assigned_to'], $op->assigned_user_id);
        }
        $this->assertSame(1, Operation::query()->where('id', 'OP-1')->count(), 'the project keeps its other operations');
        $this->assertCount(1, $ai->calls);
    }

    public function test_an_operation_with_a_predecessor_waits_for_it(): void
    {
        $this->fakeAi([F::reply('پیشنهاد شد.', [
            F::act('production.add_operation', ['name' => 'بسته‌بندی', 'resource_id' => 'BUY', 'direct_hours' => 2, 'after' => 'OP-1', 'auto_assign' => true]),
        ])]);
        $this->as('owner');
        $id = $this->postJson('/api/chat', ['message' => 'بعد از OP-1 بسته‌بندی اضافه کن'])->json('pending_actions.0.id');

        $done = $this->postJson("/api/chat/actions/{$id}/confirm")->assertOk()->json();

        $op = Operation::query()->findOrFail($done['result']['operation_id']);
        $this->assertSame('NOT_READY', $op->status->value);
        $this->assertNull($done['result']['assigned_to']);
        $this->assertNotNull($done['result']['not_assigned_because']);
        $this->assertSame(['OP-1'], DB::table('predecessor')->where('operation_id', $op->id)->pluck('predecessor_id')->all());
    }

    public function test_an_inactive_resource_is_replaced_by_an_active_one_and_the_card_says_why(): void
    {
        DB::table('work_resource')->where('id', 'BUY')->update(['status' => 'INACTIVE']);
        $this->fakeAi([F::reply('پیشنهاد شد.', [F::act('production.add_operation', ['name' => 'x', 'resource_id' => 'BUY', 'direct_hours' => 1])])]);
        $this->as('owner');

        $action = $this->postJson('/api/chat', ['message' => 'یک کار اضافه کن'])->json('pending_actions.0');

        $this->assertSame('R1', $action['params']['resource_id']);
        $this->assertStringContainsString('BUY', $action['reason']);
        $done = $this->postJson("/api/chat/actions/{$action['id']}/confirm")->assertOk()->json();
        $this->assertSame('R1', Operation::query()->findOrFail($done['result']['operation_id'])->resource_id);
    }

    public function test_details_left_out_are_chosen_from_the_organisations_data(): void
    {
        DB::table('project')->insert(['id' => 'P0', 'name' => 'اولویت اول', 'priority' => 0, 'status' => 'ACTIVE', 'created_at' => now()]);
        $this->fakeAi([F::reply('پیشنهاد شد.', [F::act('production.add_operation', ['name' => 'خرید سیب', 'project_id' => 'NO-SUCH', 'auto_assign' => true])])]);
        $this->as('owner');

        $action = $this->postJson('/api/chat', ['message' => 'یک خرید سیب اضافه کن، هر کاری لازمه بکن'])->json('pending_actions.0');

        $this->assertSame('P0', $action['params']['project_id'], 'an invented project is replaced by the highest-priority one');
        $this->assertSame('BUY', $action['params']['resource_id'], 'the resource that fits the work (خرید) is chosen');
        $this->assertSame(1, $action['params']['direct_hours']);
        $this->assertStringContainsString('NO-SUCH', $action['reason']);
    }

    public function test_reading_in_circles_goes_straight_to_the_answer(): void
    {
        $ai = $this->fakeAi([F::call(['production.status_summary']), F::call(['production.status_summary']), F::reply('خلاصه آماده است.')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'تولید چه خبره؟'])->assertOk()->assertJsonPath('reply', 'خلاصه آماده است.');

        $this->assertArrayNotHasKey('tools', $ai->input(2), 'the third call is the final round, without tools');
    }

    public function test_the_forecast_tool_gives_planned_times_from_the_scheduler(): void
    {
        $ai = $this->fakeAi([F::call(['production.forecast', ['operation_id' => 'OP-1']], ['production.forecast']), F::reply('ok')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'OP-1 کی تموم می‌شه؟'])->assertOk();

        [$one, $all] = $ai->input(1)['results'];
        $this->assertTrue($one['ok']);
        $this->assertSame('OP-1', $one['data']['operation']['id']);
        $this->assertNotNull($one['data']['operation']['planned_end']);
        $this->assertTrue($one['data']['operation']['in_open_plan']);
        // in local (Tehran) time, as people read it, not UTC
        $end = app(\App\Services\PlanService::class)->plan(app(\App\Services\ProjectDataService::class)->load())->end('OP-1');
        $this->assertSame($end->setTimezone('Asia/Tehran')->format('Y-m-d H:i'), $one['data']['operation']['planned_end']);
        $this->assertSame('P1', $all['data']['projects'][0]['id']);
        $this->assertNotNull($all['data']['plan_finish_at']);
    }

    public function test_a_needless_question_goes_back_to_the_model_which_then_decides(): void
    {
        $ai = $this->fakeAi([
            F::ask('به کدوم پروژه وصل کنم؟'),
            F::reply('عملیات را به پروژهٔ با بالاترین اولویت و منبع خرید پیشنهاد دادم.', [
                F::act('production.add_operation', ['name' => 'خرید سیب', 'resource_id' => 'BUY', 'direct_hours' => 1, 'auto_assign' => true]),
            ]),
        ]);
        $this->as('owner');

        $r = $this->postJson('/api/chat', ['message' => 'یک خرید سیب اضافه کن، هر کاری لازمه بکن'])->assertOk();

        $this->assertSame('add_operation', $r->json('pending_actions.0.operation'), 'the user was not asked');
        $this->assertArrayHasKey('decide_yourself', $ai->input(1));
    }

    public function test_a_question_for_a_missing_required_value_still_reaches_the_user(): void
    {
        $ai = $this->fakeAi([F::ask('درباره‌ی کدوم پروژه؟', 'production.project_detail', [], ['project_id'])]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'وضعیت پروژه رو بگو'])->assertOk()->assertJsonPath('reply', 'درباره‌ی کدوم پروژه؟');
        $this->assertCount(1, $ai->calls);
    }

    public function test_without_autonomy_the_question_goes_to_the_user(): void
    {
        config(['aiso.agent.dialogue.autonomy' => false]);
        $ai = $this->fakeAi([F::ask('به کدوم پروژه وصل کنم؟')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'یک خرید سیب اضافه کن'])->assertOk()->assertJsonPath('reply', 'به کدوم پروژه وصل کنم؟');
        $this->assertCount(1, $ai->calls);
    }

    public function test_the_same_read_twice_in_a_turn_runs_once(): void
    {
        $ai = $this->fakeAi([F::call(['production.status_summary']), F::call(['production.status_summary']), F::reply('ok')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'تولید چه خبره؟'])->assertOk();

        $this->assertSame(1, \App\Models\AiRunStep::query()->where('stage', 'tool')->count());
        $this->assertCount(3, $ai->calls);
    }

    public function test_the_users_task_stays_the_goal_through_a_short_reply(): void
    {
        config(['aiso.agent.dialogue.autonomy' => false]);
        $ai = $this->fakeAi([F::ask('به پروژهٔ خاصی وصل کنم یا عمومی؟')]);
        $this->as('owner');
        $id = $this->postJson('/api/chat', ['message' => 'یک خرید سیب اضافه کن'])->json('conversationId');

        $ai->push(F::reply('باشه، پیشنهاد دادم.'));
        $this->postJson('/api/chat', ['message' => 'عمومی', 'conversationId' => $id])->assertOk();

        $this->assertSame('یک خرید سیب اضافه کن', $ai->input(1)['state']['goal']);

        $ai->push(F::reply('سلام!'));
        $this->postJson('/api/chat', ['message' => 'سلام', 'conversationId' => $id])->assertOk();
        $this->assertArrayNotHasKey('goal', $ai->input(2)['state'], 'a plain answer completed the task');
    }

    public function test_at_the_reading_limit_the_assistant_answers_with_what_it_has(): void
    {
        config(['aiso.agent.dialogue.max_rounds' => 2]);
        $ai = $this->fakeAi([F::call(['production.status_summary']), F::call(['production.project_summary']), F::reply('خلاصه: یک عملیات آماده.')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'همه چیز رو بررسی کن'])->assertOk()->assertJsonPath('reply', 'خلاصه: یک عملیات آماده.');

        $final = $ai->input(2);
        $this->assertArrayNotHasKey('tools', $final, 'no more tools in the last round');
        $this->assertTrue($final['must_reply']);
        $this->assertSame('production.status_summary', $final['results'][0]['tool']);
    }

    public function test_a_proposal_written_next_to_tool_calls_is_still_proposed(): void
    {
        $this->fakeAi([json_encode(['protocol' => 'aiso.v2', 'type' => 'call', 'calls' => [['tool' => 'production.status_summary', 'args' => (object) []]],
            'actions' => [F::act('production.add_operation', ['name' => 'خرید', 'resource_id' => 'BUY', 'direct_hours' => 1])]], JSON_UNESCAPED_UNICODE)]);
        $this->as('owner');

        $r = $this->postJson('/api/chat', ['message' => 'یک خرید اضافه کن'])->assertOk();

        $this->assertSame('add_operation', $r->json('pending_actions.0.operation'));
    }

    public function test_a_single_scope_becomes_a_list_and_rule_keys_are_ignored(): void
    {
        $this->fakeAi([F::reply('ایجنت پیشنهاد شد.', [F::act('hr.create_agent', ['name' => 'تحلیلگر', 'instructions' => 'گزارش راندمان', 'scope' => 'hr', 'scope.*' => ['x']])])]);
        $this->as('owner');

        $r = $this->postJson('/api/chat', ['message' => 'یک ایجنت برای راندمان منابع انسانی بساز'])->assertOk();

        $this->assertSame(['hr'], $r->json('pending_actions.0.params.scope'));
    }

    public function test_a_proposal_that_fails_validation_is_reported_not_claimed(): void
    {
        // no name: nothing can choose one, so the proposal is invalid
        $this->fakeAi([F::reply('عملیات را اضافه کردم.', [F::act('production.add_operation', ['resource_id' => 'BUY', 'direct_hours' => 1])])]);
        $this->as('owner');

        $r = $this->postJson('/api/chat', ['message' => 'یک کار اضافه کن'])->assertOk();

        $this->assertSame([], $r->json('pending_actions'));
        $this->assertStringContainsString('ثبت نشد', $r->json('reply'));
    }

    public function test_a_change_request_answered_with_only_a_promise_is_sent_back_to_propose_it(): void
    {
        config(['aiso.agent.gate.enabled' => true]);
        $ai = $this->fakeAi(['action', F::reply('الان اولین کارگر آزاد را پیدا می‌کنم و ثبت می‌کنم.'),
            F::reply('پیشنهاد دادم.', [F::act('production.add_operation', ['name' => 'خرید سیب', 'auto_assign' => true])])]);
        $this->as('owner');

        $r = $this->postJson('/api/chat', ['message' => 'یک خرید سیب اضافه کن'])->assertOk();

        $this->assertSame('add_operation', $r->json('pending_actions.0.operation'));
        $this->assertArrayHasKey('propose_now', $ai->input(2));
    }

    public function test_the_instructions_give_the_assistant_freedom_to_decide(): void
    {
        $ai = $this->fakeAi([F::reply('سلام')]);
        $this->as('owner');
        $this->postJson('/api/chat', ['message' => 'سلام'])->assertOk();

        $system = $ai->calls[0][0]['content'];
        $this->assertStringContainsString('خواندن داده هیچ اجازه‌ای لازم ندارد', $system);
        $this->assertStringContainsString('بهترین تصمیم را خودت بگیر', $system);
        $this->assertStringContainsString('هرگز سؤالی را که قبلاً پرسیدی', $system);
    }
}

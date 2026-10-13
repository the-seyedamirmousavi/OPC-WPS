<?php

namespace Tests\Feature\AI;

use App\AI\AgentOrchestrator;
use App\AI\Contracts\DepartmentHandler;
use App\AI\Departments\ProductionHandler;
use App\AI\Dialogue\ConversationStateStore;
use App\AI\Dialogue\DialogueEngine;
use App\AI\Dialogue\DialogueState;
use App\Exceptions\ApiException;
use App\Models\AiPendingAction;
use App\Models\AiRun;
use App\Models\AiRunStep;
use App\Models\AppUser;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Department;
use App\Services\AgentService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/**
 * The stateful dialogue engine: small context, tools read on demand, missing parameters collected over several turns,
 * and the same rights and approvals as before. The AI is a fake that answers with prepared aiso.v2 messages.
 */
class DialogueTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
        config(['aiso.agent.engine' => 'dialogue']);
        $this->production();
    }

    private function person(string $id, string $name, string $role = 'USER_1'): void
    {
        app(UserService::class)->create($id, $name, $role, null, null, 'Passw0rd!test-' . $id, $this->user('owner'));
    }

    private function project(string $id, string $name): void
    {
        DB::table('project')->insert(['id' => $id, 'name' => $name, 'priority' => 1, 'status' => 'ACTIVE', 'created_at' => now()]);
    }

    private function chat(string $message, ?int $conversation = null, ?string $requestId = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/chat', array_filter(['message' => $message, 'conversationId' => $conversation, 'requestId' => $requestId]));
    }

    /** @return string[] the tools that read data, in order (a call refused or missing a parameter read nothing) */
    private function toolsRun(): array
    {
        return AiRunStep::query()->where('stage', 'tool')->whereNull('error')->orderBy('id')->pluck('input')
            ->map(fn (string $i) => json_decode($i, true)['tool'])->all();
    }

    private function state(int $conversation): DialogueState
    {
        return DialogueState::load(ChatConversation::query()->findOrFail($conversation)->dialogue_state);
    }

    // 1
    public function test_a_greeting_needs_no_business_data(): void
    {
        $ai = $this->fakeAi([F::reply('سلام! از کدوم بخش خبر می‌خوای؟')]);
        $this->as('owner');

        $this->chat('سلام')->assertOk()->assertJsonPath('reply', 'سلام! از کدوم بخش خبر می‌خوای؟');

        $this->assertCount(1, $ai->calls);
        $this->assertSame([], $this->toolsRun());
    }

    // 2
    public function test_a_production_question_reads_only_the_production_summary_with_a_small_context(): void
    {
        $this->operation('OP-1', 'READY');
        $ai = $this->fakeAi([F::call(['production.status_summary']), F::reply('یک عملیات آماده است.')]);
        $this->as('owner');

        $this->chat('تولید چه خبره و دارن چیکار می‌کنن؟')->assertOk()->assertJsonPath('reply', 'یک عملیات آماده است.');

        $this->assertSame(['production.status_summary'], $this->toolsRun());
        $first = $ai->input(0);
        foreach (['briefing', 'departments', 'guide', 'hints', 'history'] as $gone) {
            $this->assertArrayNotHasKey($gone, $first, "{$gone} is not sent any more");
        }
        $this->assertContains('production.status_summary(): ' . 'خلاصه‌ی وضعیت تولید: تعداد عملیات‌ها در هر وضعیت و تعداد پروژه‌ها', $first['tools']);
        $this->assertLessThan(9000, mb_strlen($ai->calls[0][1]['content']), 'the first context stays small');
        $second = $ai->input(1);
        $this->assertSame('production.status_summary', $second['results'][0]['tool']);
        $this->assertTrue($second['results'][0]['ok']);
        $this->assertSame(1, $second['results'][0]['data']['operations_by_status']['READY']);
    }

    // 3
    public function test_a_follow_up_question_reuses_the_conversation(): void
    {
        $ai = $this->fakeAi([F::call(['production.status_summary']), F::reply('همه چیز روال است.')]);
        $this->as('owner');
        $id = $this->chat('تولید چه خبره؟')->assertOk()->json('conversationId');

        $ai->push(F::call(['production.late_operations']), F::reply('کاری عقب نیفتاده.'));
        $this->chat('کدوم کارها عقب افتادن؟', $id)->assertOk();

        $ctx = $ai->input(2);
        $this->assertSame('تولید چه خبره؟', $ctx['state']['summary'][0]['u']);
        $this->assertSame('production.status_summary', $ctx['state']['recent'][0]['tool']);
        $this->assertSame(['production.status_summary', 'production.late_operations'], $this->toolsRun());
    }

    // 4
    public function test_a_missing_user_becomes_a_question_not_an_invalid_call(): void
    {
        $ai = $this->fakeAi([F::call(['production.list_by_user'])]);
        $this->as('owner');

        $r = $this->chat('فلانی الان چه کارهایی داره؟')->assertOk();

        $r->assertJsonPath('reply', config('aiso.agent.dialogue.questions.user_id'));
        $this->assertCount(1, $ai->calls);
        $this->assertSame(0, AiRunStep::query()->where('stage', 'tool')->whereNull('error')->count(), 'nothing was read');
        $pending = $this->state($r->json('conversationId'))->pending();
        $this->assertSame('production.list_by_user', $pending['tool']);
        $this->assertSame(['user_id'], $pending['missing']);
    }

    // 5
    public function test_a_short_reply_fills_the_pending_question_and_the_original_request_runs(): void
    {
        $this->person('user4', 'نگار صادقی');
        $ai = $this->fakeAi([F::call(['production.list_by_user'])]);
        $this->as('owner');
        $id = $this->chat('فلانی الان چه کارهایی داره؟')->json('conversationId');

        $ai->push(F::reply('نگار صادقی الان کار بازی ندارد.'));
        $this->chat('نگار صادقی', $id)->assertOk()->assertJsonPath('reply', 'نگار صادقی الان کار بازی ندارد.');

        $this->assertCount(2, $ai->calls, 'the reply was resolved without asking the model');
        $step = AiRunStep::query()->where('stage', 'tool')->whereNull('error')->firstOrFail();
        $this->assertSame(['tool' => 'production.list_by_user', 'args' => ['user_id' => 'user4']], json_decode($step->input, true));
        $ctx = $ai->input(1);
        $this->assertSame('production.list_by_user', $ctx['results'][0]['tool']);
        $this->assertStringContainsString('فلانی الان چه کارهایی داره؟', $ctx['request']);
        $this->assertNull($this->state($id)->pending());
    }

    // 5 (a project named in words)
    public function test_a_project_name_is_resolved_to_its_id(): void
    {
        $this->project('LINE-A', 'خط تولید شمال');
        $this->project('LINE-B', 'خط تولید جنوب');
        $ai = $this->fakeAi([F::ask('وضعیت کدوم پروژه رو می‌خوای؟', 'production.project_detail', [], ['project_id'])]);
        $this->as('owner');
        $id = $this->chat('وضعیت پروژه رو بگو.')->assertJsonPath('reply', 'وضعیت کدوم پروژه رو می‌خوای؟')->json('conversationId');

        $ai->push(F::reply('خط تولید شمال هنوز عملیاتی ندارد.'));
        $this->chat('خط تولید شمال', $id)->assertOk();

        $this->assertSame(['production.project_detail'], $this->toolsRun());
        $this->assertSame('LINE-A', $ai->input(1)['results'][0]['data']['id']);
    }

    // 6
    public function test_an_ambiguous_name_offers_a_choice_and_the_choice_is_used(): void
    {
        $this->person('user4', 'نگار صادقی');
        $this->person('user8', 'نگار رضایی', 'USER_2');
        $ai = $this->fakeAi([F::call(['production.list_by_user'])]);
        $this->as('owner');
        $id = $this->chat('فلانی چه کارهایی داره؟')->json('conversationId');

        $r = $this->chat('نگار', $id)->assertOk();
        $this->assertSame(['نگار صادقی (user4)', 'نگار رضایی (user8)'], $r->json('choices'));
        $this->assertCount(1, $ai->calls, 'choosing needs no model call');
        $this->assertSame([], $this->toolsRun());

        $ai->push(F::reply('نگار صادقی کاری ندارد.'));
        $this->chat('همون اولی', $id)->assertOk();
        $step = AiRunStep::query()->where('stage', 'tool')->whereNull('error')->firstOrFail();
        $this->assertSame('user4', json_decode($step->input, true)['args']['user_id']);
    }

    // 7
    public function test_never_mind_drops_the_pending_question(): void
    {
        $ai = $this->fakeAi([F::call(['production.list_by_user'])]);
        $this->as('owner');
        $id = $this->chat('فلانی چه کارهایی داره؟')->json('conversationId');

        $this->chat('بی‌خیال', $id)->assertOk();

        $this->assertCount(1, $ai->calls);
        $this->assertNull($this->state($id)->pending());
        $this->assertTrue(DialogueEngine::isCancel('ولش کن'));
        $this->assertFalse(DialogueEngine::isCancel('عملیات OP-12 را لغو کن'), 'a cancel request of its own is not "never mind"');
    }

    // 7 (a new request replaces the pending one)
    public function test_a_new_request_replaces_the_pending_question(): void
    {
        $ai = $this->fakeAi([F::call(['production.list_by_user'])]);
        $this->as('owner');
        $id = $this->chat('فلانی چه کارهایی داره؟')->json('conversationId');

        $ai->push(F::call(['production.project_summary']), F::reply('سه پروژه داریم.'));
        $this->chat('پروژه‌ها چی؟', $id)->assertOk();

        $this->assertSame('production.list_by_user', $ai->input(1)['state']['pending']['tool'], 'the model saw what was pending');
        $this->assertSame(['production.project_summary'], $this->toolsRun());
        $this->assertNull($this->state($id)->pending());
    }

    // 8
    public function test_an_expired_question_never_runs_later(): void
    {
        $this->person('user4', 'نگار صادقی');
        $ai = $this->fakeAi([F::call(['production.list_by_user'])]);
        $this->as('owner');
        $id = $this->chat('فلانی چه کارهایی داره؟')->json('conversationId');

        $this->travel((int) config('aiso.agent.dialogue.pending_ttl_minutes') + 1)->minutes();
        $ai->push(F::reply('منظورت از نگار صادقی چیه؟'));
        $this->chat('نگار صادقی', $id)->assertOk();

        $this->assertSame([], $this->toolsRun(), 'the stale request did not run');
        $ctx = $ai->input(1);
        $this->assertArrayNotHasKey('pending', $ctx['state'] ?? []);
        $this->assertStringContainsString('expired', $ctx['state']['note']);
    }

    // 9
    public function test_a_role_without_the_right_cannot_read_restricted_data(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $ai = $this->fakeAi([F::call(['production.list_by_user', ['user_id' => 'user1']], ['hr.users_summary']), F::reply('به این داده دسترسی ندارم.')]);
        $this->as('manager');

        $this->chat('user1 چه کارهایی داره و چند کاربر داریم؟')->assertOk();

        $tools = implode("\n", $ai->input(0)['tools']);
        $this->assertStringNotContainsString('production.list_by_user', $tools, 'performance data is not offered to a manager');
        $this->assertStringNotContainsString('hr.', $tools, 'HR is not offered to a manager');
        foreach ($ai->input(1)['results'] as $r) {
            $this->assertFalse($r['ok']);
            $this->assertSame('unknown_tool', $r['status']);
            $this->assertArrayNotHasKey('data', $r);
        }
    }

    // 10
    public function test_an_agent_cannot_read_beyond_its_scope_or_propose_changes(): void
    {
        $this->operation('OP-1', 'BLOCKED');
        $agentId = app(AgentService::class)->create($this->user('owner'), 'گزارشگر', 'گزارش تولید بده', ['production'])['id'];
        $agent = AppUser::query()->findOrFail($agentId);
        $ai = $this->fakeAi([F::call(['hr.users_summary']),
            F::reply('گزارش آماده است.', [F::act('production.unblock_operation', ['operation_id' => 'OP-1'])])]);

        $answer = app(AgentOrchestrator::class)->handle('گزارش بده', $this->user('owner'), [], $agent->agentScope(), true, $agent);

        $this->assertSame('unknown_tool', $ai->input(1)['results'][0]['status']);
        $this->assertSame('گزارش تولید بده', $ai->input(0)['agent_job']);
        $this->assertTrue($ai->input(0)['read_only']);
        $this->assertSame([], $answer->pendingActions);
        $this->assertSame(0, AiPendingAction::query()->count());
        $this->assertSame('BLOCKED', DB::table('operation')->where('id', 'OP-1')->value('status'));
    }

    // 11
    public function test_a_tool_error_gives_an_honest_reply_not_a_made_up_one(): void
    {
        $this->app->bind(ProductionHandler::class, fn () => new class implements DepartmentHandler {
            public function fetch(string $intent, array $params, AppUser $user, Department $department): array
            {
                throw new \RuntimeException('database is down at 10.0.0.5');
            }

            public function execute(string $operation, array $params, AppUser $user, Department $department): array
            {
                throw new \RuntimeException('no');
            }
        });
        $ai = $this->fakeAi([F::call(['production.status_summary']), F::reply('همه چیز عالی است و ۱۰ عملیات تمام شد.')]);
        $this->as('owner');

        $r = $this->chat('تولید چه خبره؟')->assertOk();

        $this->assertStringContainsString('نتوانستم', $r->json('reply'));
        $this->assertCount(1, $ai->calls, 'the model was not asked to write an answer without data');
        $this->assertStringNotContainsString('10.0.0.5', $r->getContent());
    }

    // 12
    public function test_large_results_are_cut_and_say_so(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->operation(sprintf('OP-%03d', $i), 'READY');
        }
        config(['aiso.agent.dialogue.max_result_chars' => 1500]);
        $ai = $this->fakeAi([F::call(['production.list_by_status', ['status' => 'READY', 'limit' => 50]], ['production.list_by_status', ['status' => 'READY', 'limit' => 500]]),
            F::reply('بخشی از فهرست.')]);
        $this->as('owner');

        $this->chat('کارهای آماده رو بگو')->assertOk();

        [$cut, $refused] = $ai->input(1)['results'];
        $this->assertTrue($cut['truncated']);
        $this->assertSame(count($cut['data']), $cut['shown']);
        $this->assertLessThan(50, $cut['shown']);
        $this->assertLessThanOrEqual(1500, mb_strlen(json_encode($cut['data'], JSON_UNESCAPED_UNICODE)));
        $this->assertSame('invalid_parameters', $refused['status'], 'a limit above the maximum is refused, not run');
    }

    // 13
    public function test_a_retried_message_runs_once_and_proposes_once(): void
    {
        $this->operation('OP-1', 'READY');
        $ai = $this->fakeAi([F::reply('لغو OP-1 منتظر تأیید است.', [F::act('production.cancel_operation', ['operation_id' => 'OP-1', 'reason' => 'تکراری'])])]);
        $this->as('owner');

        $first = $this->chat('OP-1 را لغو کن', null, 'req-123')->assertOk();
        $again = $this->chat('OP-1 را لغو کن', null, 'req-123')->assertOk();

        $this->assertTrue($again->json('replayed'));
        $this->assertSame($first->json('conversationId'), $again->json('conversationId'));
        $this->assertSame($first->json('reply'), $again->json('reply'));
        $this->assertCount(1, $ai->calls);
        $this->assertSame(1, AiRun::query()->count());
        $this->assertSame(1, AiPendingAction::query()->count());
        $this->assertSame(2, ChatMessage::query()->count());
        $this->assertCount(1, $again->json('pending_actions'));
    }

    // 14
    public function test_a_change_that_needs_approval_does_not_run_before_it(): void
    {
        $this->operation('OP-1', 'READY');
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $ai = $this->fakeAi([F::reply('درخواست لغو ثبت شد و منتظر تأیید مالک است.', [F::act('production.cancel_operation', ['operation_id' => 'OP-1', 'reason' => 'تکراری'])])]);
        $this->as('manager');

        $r = $this->chat('OP-1 را لغو کن')->assertOk();

        $this->assertSame('AWAITING_OWNER', $r->json('pending_actions.0.status'));
        $this->assertSame('READY', DB::table('operation')->where('id', 'OP-1')->value('status'));
        $this->postJson('/api/chat/actions/' . $r->json('pending_actions.0.id') . '/confirm')->assertStatus(409);
        $this->assertSame('READY', DB::table('operation')->where('id', 'OP-1')->value('status'));
    }

    // 14 (a change written as a tool call is only a proposal too)
    public function test_a_change_written_as_a_tool_call_becomes_a_proposal_not_an_execution(): void
    {
        $this->operation('OP-1', 'READY');
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $ai = $this->fakeAi([F::call(['production.cancel_operation', ['operation_id' => 'OP-1', 'reason' => 'x']])]);
        $this->as('manager');

        $r = $this->chat('OP-1 را لغو کن')->assertOk();

        $this->assertSame('AWAITING_OWNER', $r->json('pending_actions.0.status'));
        $this->assertSame('READY', DB::table('operation')->where('id', 'OP-1')->value('status'));
        $this->assertSame([], $this->toolsRun(), 'nothing ran as a tool');
        $this->assertCount(1, $ai->calls);
    }

    // 15
    public function test_one_turn_at_a_time_per_conversation_and_a_stale_state_is_not_written(): void
    {
        $ai = $this->fakeAi([F::reply('سلام!')]);
        $this->as('owner');
        $id = $this->chat('سلام')->json('conversationId');

        $lock = Cache::lock("aiso:chat:owner:{$id}", 60);
        $this->assertTrue($lock->get());
        $this->chat('دوباره سلام', $id)->assertStatus(409);
        $lock->release();
        $this->assertCount(1, $ai->calls, 'the refused message reached no model');

        $store = app(ConversationStateStore::class);
        $conversation = ChatConversation::query()->findOrFail($id);
        [$state, $version] = $store->load($conversation);
        $store->save($conversation, $state, $version);
        $this->expectException(ApiException::class);
        $store->save($conversation, $state, $version);
    }

    // 16
    public function test_the_api_answer_keeps_its_shape_and_the_legacy_engine_still_answers(): void
    {
        $ai = $this->fakeAi([F::reply('سلام!')]);
        $this->as('owner');
        $keys = array_keys($this->chat('سلام')->assertOk()->json());
        foreach (['conversationId', 'reply', 'model', 'pending_actions', 'sources', 'executed', 'limited', 'trace', 'choices'] as $k) {
            $this->assertContains($k, $keys);
        }

        config(['aiso.agent.engine' => 'legacy']);
        $ai->push(F::route('answer'), F::answer('سلام از موتور قبلی'));
        $this->chat('سلام')->assertOk()->assertJsonPath('reply', 'سلام از موتور قبلی');
    }

    public function test_a_call_written_without_args_key_is_repaired_and_still_checked(): void
    {
        $ai = $this->fakeAi(['{"protocol":"aiso.v2","type":"call","calls":[{"tool":"production.status_summary":{}}]}', F::reply('خلاصه آماده است.')]);
        $this->as('owner');

        $this->chat('تولید چه خبره؟')->assertOk()->assertJsonPath('reply', 'خلاصه آماده است.');

        $this->assertSame(['production.status_summary'], $this->toolsRun());
        $this->assertCount(2, $ai->calls, 'no correction round was needed');
        $this->assertSame('{"tool":"x","args":{"a":1}}', \App\AI\Dialogue\DialogueProtocol::repair('{"tool":"x":{"a":1}}'));
        // a stray backslash before a Persian letter (seen live) is dropped; valid escapes are kept
        $this->assertSame("الف\n\nهمچنین \\ پایان", json_decode(\App\AI\Dialogue\DialogueProtocol::repair('{"t":"الف\n\n\همچنین \\\\ پایان"}'), true)['t']);
        $valid = '{"t":"a\\"b"}';
        $this->assertSame($valid, \App\AI\Dialogue\DialogueProtocol::repair($valid), 'valid JSON is never touched');
        $this->assertSame(['بله', 'جزئیات'], \App\AI\Dialogue\DialogueProtocol::choices([['text' => 'بله'], 'جزئیات', 5]));
        // a call with a broken actions tail (seen live) keeps the call
        $call = '{"protocol":"aiso.v2","type":"call","calls":[{"tool":"production.status_summary","args":{}}],"actions":[{"action":"x","args":{}}],"reason":"y"}]}';
        $this->assertSame('call', json_decode(\App\AI\Dialogue\DialogueProtocol::repair($call), true)['type']);
        // broken quick replies at the end (seen live) lose only the quick replies
        $broken = '{"protocol":"aiso.v2","type":"reply","text":"پروژه فعال است.","choices":[{"text":"جزئیات","}, {"text":"خلاصه","}]}';
        $this->assertSame(['protocol' => 'aiso.v2', 'type' => 'reply', 'text' => 'پروژه فعال است.'], json_decode(\App\AI\Dialogue\DialogueProtocol::repair($broken), true));
    }

    public function test_a_connection_timeout_is_explained_as_one(): void
    {
        $e = new \RuntimeException('cURL error 28: Connection timed out after 10002 milliseconds (see https://curl.se)');
        $this->assertSame('Could not connect to the remote service within 10 seconds. Check the network or try again shortly.',
            \App\Support\NetworkDiagnosis::connection($e, 300));
        $slow = new \RuntimeException('cURL error 28: Operation timed out after 300001 milliseconds with 0 bytes received');
        $this->assertSame('The remote service did not answer within 300 seconds.', \App\Support\NetworkDiagnosis::connection($slow, 300));
    }

    public function test_the_context_is_much_smaller_than_the_legacy_payload(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->operation(sprintf('OP-%03d', $i), 'READY');
        }
        $ai = $this->fakeAi([F::route('answer'), F::answer('x')]);
        $this->as('owner');
        config(['aiso.agent.engine' => 'legacy']);
        $this->chat('تولید چه خبره؟')->assertOk();
        $legacySize = mb_strlen($ai->calls[0][1]['content']);

        config(['aiso.agent.engine' => 'dialogue']);
        $ai->push(F::reply('x'));
        $this->chat('تولید چه خبره؟')->assertOk();
        $newSize = mb_strlen($ai->calls[2][1]['content']);

        $this->assertLessThan($legacySize / 2, $newSize, "dialogue {$newSize} chars vs legacy {$legacySize} chars");
    }
}

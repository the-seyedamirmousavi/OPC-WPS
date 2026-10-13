<?php

namespace Tests\Feature\AI;

use App\AI\IntentRouter;
use App\Models\AiRunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The gate: one of four kinds for each question, before any data is read. */
class IntentRouterTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
        config(['aiso.agent.gate.enabled' => true]);
    }

    public function test_the_answer_is_one_identifier_and_anything_else_is_refused(): void
    {
        $this->assertSame('chat', IntentRouter::parse('chat'));
        $this->assertSame('read', IntentRouter::parse(' "Read". '));
        $this->assertSame('action', IntentRouter::parse("`action`\n"));
        $this->assertNull(IntentRouter::parse('chatbot'));
        $this->assertNull(IntentRouter::parse('read the list of users'));
        $this->assertNull(IntentRouter::parse('{"choice":"read"}'));
        $this->assertNull(IntentRouter::parse(null));
    }

    public function test_the_prompt_is_the_owners_template_with_the_question_the_role_and_the_four_options(): void
    {
        $prompt = IntentRouter::prompt('سلام، وضعیت تولید چطور است؟', 'MANAGER');

        $this->assertStringContainsString('تو یک مسیریاب تصمیم‌گیری برای سامانه‌ی AISO هستی', $prompt);
        $this->assertStringContainsString('سلام، وضعیت تولید چطور است؟', $prompt);
        $this->assertStringContainsString('نقش کاربر: MANAGER', $prompt);
        foreach (['read', 'action', 'chat', 'clarify'] as $id) {
            $this->assertStringContainsString("- {$id}:", $prompt);
        }
        $this->assertStringNotContainsString('{question}', $prompt);
    }

    public function test_a_greeting_the_gate_calls_chat_is_answered_without_reading_any_data(): void
    {
        $this->production();
        // the gate says chat; the route wrongly asks for data; the gate's decision wins and nothing is read
        $ai = $this->fakeAi(['chat', F::route('fetch', [F::req('production', 'status_summary')]), F::answer('سلام!')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'سلام'])->assertOk()->assertJsonPath('reply', 'سلام!');

        $this->assertSame(['gate', 'route', 'answer'], AiRunStep::query()->orderBy('id')->pluck('stage')->all());
        $this->assertSame(0, AiRunStep::query()->where('stage', 'fetch')->count());
        $this->assertCount(3, $ai->calls);
    }

    public function test_a_question_the_gate_calls_unclear_is_asked_back(): void
    {
        $this->as('owner');
        $this->fakeAi(['clarify', F::route('answer')]);

        $this->postJson('/api/chat', ['message' => 'این چیه؟'])->assertOk()
            ->assertJsonPath('reply', 'منظورتان را دقیق‌تر بگویید؟');
    }

    public function test_a_read_question_goes_on_to_read_the_data(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->fakeAi(['read', F::route('fetch', [F::req('production', 'status_summary')]), F::answer('یک خلاصه')]);
        $this->as('manager');

        $this->postJson('/api/chat', ['message' => 'وضعیت تولید']);

        $this->assertSame(['gate', 'route', 'fetch', 'answer'], AiRunStep::query()->orderBy('id')->pluck('stage')->all());
    }

    public function test_an_answer_that_is_not_one_of_the_four_is_ignored_and_the_route_decides(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->fakeAi(['پیام دلخواه بدون شناسه', F::route('fetch', [F::req('production', 'status_summary')]), F::answer('ok')]);
        $this->as('manager');

        $this->postJson('/api/chat', ['message' => 'وضعیت تولید'])->assertOk();

        $gate = AiRunStep::query()->where('stage', 'gate')->firstOrFail();
        $this->assertStringContainsString('unusable', (string) $gate->error);
        $this->assertSame(['gate', 'route', 'fetch', 'answer'], AiRunStep::query()->orderBy('id')->pluck('stage')->all());
    }

    public function test_with_the_gate_off_the_route_decides_alone(): void
    {
        config(['aiso.agent.gate.enabled' => false]);
        $ai = $this->fakeAi([F::route('answer'), F::answer('سلام')]);
        $this->as('owner');

        $this->postJson('/api/chat', ['message' => 'سلام'])->assertOk();

        $this->assertCount(2, $ai->calls);
        $this->assertSame(0, AiRunStep::query()->where('stage', 'gate')->count());
    }

    public function test_the_gate_answer_is_logged_and_shown_in_the_trace(): void
    {
        $this->fakeAi(['chat', F::route('answer'), F::answer('سلام!')]);
        $this->as('owner');

        $reply = $this->postJson('/api/chat', ['message' => 'سلام'])->assertOk()->json('trace');

        $gate = collect($reply)->firstWhere('step', 'gate');
        $this->assertSame('chat', $gate['decision']);
        $this->assertSame('chat', AiRunStep::query()->where('stage', 'gate')->firstOrFail()->output);
    }
}

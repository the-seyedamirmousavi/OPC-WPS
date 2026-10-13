<?php

namespace Tests\Feature\AI;

use App\AI\IntentCatalog;
use App\AI\ProtocolValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** Quick replies: the assistant offers short answers to its own question, and a click sends one of them. */
class ChoicesTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    private function clarify(array $choices): string
    {
        return json_encode([
            'protocol' => 'aiso.v1', 'stage' => 'route_result', 'decision' => 'clarify', 'requests' => [],
            'clarify_question' => 'Shall I continue?', 'choices' => $choices,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_only_short_unique_text_choices_are_kept_and_at_most_four(): void
    {
        $v = new ProtocolValidator(app(IntentCatalog::class));
        $out = $v->route([
            'protocol' => 'aiso.v1', 'stage' => 'route_result', 'decision' => 'clarify', 'clarify_question' => 'Continue?',
            'choices' => ['  بله  ', 'بله', '', 12, str_repeat('x', 41), 'نه', 'شاید', 'بعداً', 'کنسل'],
        ], []);

        $this->assertSame(['بله', 'نه', 'شاید', 'بعداً'], $out['choices']);
    }

    public function test_a_clarify_without_choices_or_with_junk_still_works(): void
    {
        $v = new ProtocolValidator(app(IntentCatalog::class));

        $this->assertSame([], $v->route(['protocol' => 'aiso.v1', 'stage' => 'route_result', 'decision' => 'clarify',
            'clarify_question' => 'Which department?'], [])['choices']);
        $this->assertSame([], $v->route(['protocol' => 'aiso.v1', 'stage' => 'route_result', 'decision' => 'clarify',
            'clarify_question' => 'Which department?', 'choices' => 'بله'], [])['choices']);
    }

    public function test_the_choices_are_sent_with_the_answer_and_come_back_when_the_conversation_is_reopened(): void
    {
        $this->fakeAi([$this->clarify(['بله', 'نه'])]);
        $this->actingAs($this->user('owner'));

        $res = $this->postJson('/api/chat', ['message' => 'Clean up the old operations'])->assertOk()
            ->assertJsonPath('choices', ['بله', 'نه']);

        $conversation = $this->getJson('/api/chat/conversations/' . $res->json('conversationId'))->assertOk();
        $this->assertSame(['بله', 'نه'], $conversation->json('messages.1.choices'));
        $this->assertNull($conversation->json('messages.0.choices'));
    }

    public function test_an_answer_with_choices_offers_them(): void
    {
        $answer = json_encode(['protocol' => 'aiso.v1', 'stage' => 'answer_result', 'decision' => 'answer',
            'text' => 'Should I send the reminder?', 'fetch_more' => [], 'actions' => [], 'choices' => ['Yes', 'No']]);
        $this->fakeAi([F::route('answer'), $answer]);
        $this->actingAs($this->user('owner'));

        $this->postJson('/api/chat', ['message' => 'remind them'])->assertOk()->assertJsonPath('choices', ['Yes', 'No']);
    }

    public function test_no_choices_are_offered_when_the_answer_proposes_an_action(): void
    {
        $answer = json_encode(['protocol' => 'aiso.v1', 'stage' => 'answer_result', 'decision' => 'answer',
            'text' => 'I can cancel it.', 'fetch_more' => [], 'choices' => ['Yes'], 'actions' => [[
                'department' => 'production', 'operation' => 'comment_on_operation', 'params' => ['operation_id' => 'OP-1', 'note' => 'hi'],
                'reason' => 'note',
            ]]]);
        $this->production();
        $this->operation('OP-1', 'READY');
        $this->fakeAi([F::route('answer'), $answer]);
        $this->actingAs($this->user('owner'));

        $this->postJson('/api/chat', ['message' => 'note on OP-1'])->assertOk()->assertJsonPath('choices', []);
    }
}

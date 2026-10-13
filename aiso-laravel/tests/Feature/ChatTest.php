<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** Saved conversations with the assistant: per user, private, and only saved when the AI answered. */
class ChatTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    private function say(string $reply = 'Hi there'): void
    {
        $this->fakeAi([F::route('answer'), F::answer($reply)]);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/chat', ['message' => 'hello'])->assertUnauthorized();
        $this->getJson('/api/chat/conversations')->assertUnauthorized();
        $this->postJson('/api/chat/actions/1/confirm')->assertUnauthorized();
    }

    public function test_the_first_message_starts_a_saved_conversation(): void
    {
        $this->say();
        $this->as('user1');

        $id = $this->postJson('/api/chat', ['message' => 'what are my tasks?'])
            ->assertOk()->assertJsonPath('reply', 'Hi there')->assertJsonPath('model', 'fake-model')->json('conversationId');

        $this->getJson("/api/chat/conversations/{$id}")->assertOk()->assertJsonPath('title', 'what are my tasks?')
            ->assertJsonPath('messages', [
                ['role' => 'user', 'content' => 'what are my tasks?', 'choices' => null, 'attachment' => null],
                ['role' => 'assistant', 'content' => 'Hi there', 'choices' => null, 'attachment' => null],
            ]);
        $this->getJson('/api/chat/conversations')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);
    }

    public function test_a_follow_up_adds_to_the_same_conversation(): void
    {
        // one fake for both turns: the router keeps the controller, and so the AI service it was built with
        $this->fakeAi([F::route('answer'), F::answer('first'), F::route('answer'), F::answer('second')]);
        $this->as('manager');
        $id = $this->postJson('/api/chat', ['message' => 'first'])->json('conversationId');

        $this->postJson('/api/chat', ['conversationId' => $id, 'message' => 'second'])
            ->assertOk()->assertJsonPath('conversationId', $id);

        $this->assertSame(4, ChatMessage::query()->count());
        $this->getJson('/api/chat/conversations')->assertJsonCount(1);
    }

    public function test_nobody_reads_continues_or_deletes_someone_elses_conversation(): void
    {
        $this->say();
        $this->as('user1');
        $id = $this->postJson('/api/chat', ['message' => 'private'])->json('conversationId');

        foreach (['manager', 'owner'] as $who) {
            $this->as($who);
            $this->getJson('/api/chat/conversations')->assertOk()->assertJsonCount(0);
            $this->getJson("/api/chat/conversations/{$id}")->assertNotFound();
            $this->postJson('/api/chat', ['conversationId' => $id, 'message' => 'peek'])->assertNotFound();
            $this->deleteJson("/api/chat/conversations/{$id}")->assertNotFound();
        }

        $this->as('user1');
        $this->getJson("/api/chat/conversations/{$id}")->assertOk();
    }

    public function test_a_conversation_can_be_deleted_with_its_messages(): void
    {
        $this->say();
        $this->as('user1');
        $id = $this->postJson('/api/chat', ['message' => 'bye'])->json('conversationId');

        $this->deleteJson("/api/chat/conversations/{$id}")->assertNoContent();

        $this->getJson('/api/chat/conversations')->assertJsonCount(0);
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_it_says_so_when_the_ai_service_is_not_set_up(): void
    {
        config(['aiso.llm.api_key' => '']);
        $this->as('manager');

        $this->postJson('/api/chat', ['message' => 'hello'])->assertStatus(503);

        $this->getJson('/api/chat/conversations')->assertJsonCount(0);
    }

    public function test_the_message_is_required_and_limited(): void
    {
        $this->as('manager');
        $this->postJson('/api/chat', [])->assertStatus(400);
        $this->postJson('/api/chat', ['message' => str_repeat('x', 2001)])->assertStatus(400);
    }
}

<?php

namespace Tests\Feature\AI;

use App\AI\AgentOrchestrator;
use App\Models\AiRun;
use App\Models\AiRunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AgentFake as F;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The owner sees what the assistant was given and what it answered, step by step. Read only; nobody else sees it. */
class AiLogTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    public function test_the_owner_sees_each_question_with_its_steps_input_and_output(): void
    {
        $this->production();
        $this->fakeAi([F::route('answer'), F::answer('سلام به شما')]);
        $this->as('owner');
        $this->postJson('/api/chat', ['message' => 'سلام'])->assertOk();

        $runId = AiRun::query()->latest('id')->value('id');
        $list = $this->getJson('/api/ai/runs')->assertOk()->json();
        $this->assertSame($runId, $list[0]['id']);
        $this->assertSame('سلام', $list[0]['question']);

        $run = $this->getJson("/api/ai/runs/{$runId}")->assertOk()->json();
        $this->assertSame(['route', 'answer'], array_column($run['steps'], 'stage'));
        // the input is the exact message: the question, the departments and the briefing it was given
        $input = json_decode($run['steps'][0]['input'], true);
        $this->assertSame('سلام', $input['question']);
        $this->assertArrayHasKey('briefing', $input);
        $this->assertSame('سلام به شما', json_decode($run['steps'][1]['output'], true)['text']);
    }

    public function test_the_fixed_instructions_are_shown_to_the_owner(): void
    {
        $this->as('owner');
        $text = $this->getJson('/api/ai/prompt')->assertOk()->json('text');
        $this->assertSame(AgentOrchestrator::systemPrompt(), $text);
        $this->assertStringContainsString('دستیار سامانه‌ی AISO', $text);
    }

    public function test_a_manager_cannot_read_the_assistant_log(): void
    {
        $this->as('manager');
        $this->getJson('/api/ai/runs')->assertStatus(403);
        $this->getJson('/api/ai/prompt')->assertStatus(403);
        $this->getJson('/api/ai/runs/1')->assertStatus(403);
    }

    public function test_an_unknown_run_is_not_found(): void
    {
        $this->as('owner');
        $this->getJson('/api/ai/runs/999999')->assertStatus(404);
    }

    public function test_a_card_number_in_a_logged_answer_is_masked_before_it_is_shown(): void
    {
        $run = AiRun::query()->create([
            'user_id' => 'owner', 'question' => 'test', 'status' => 'DONE', 'rounds' => 1, 'tokens_in' => 0, 'tokens_out' => 0,
            'duration_ms' => 1, 'created_at' => now(),
        ]);
        AiRunStep::query()->create([
            'run_id' => $run->id, 'stage' => 'answer', 'input' => '{"question":"x"}',
            'output' => '{"text":"کارت 6037 9911 2233 4455 را ببین"}', 'tokens_in' => 0, 'tokens_out' => 0, 'duration_ms' => 1, 'created_at' => now(),
        ]);
        $this->as('owner');

        $response = $this->getJson("/api/ai/runs/{$run->id}")->assertOk();
        $this->assertStringNotContainsString('6037 9911 2233 4455', $response->getContent());
        $this->assertStringContainsString('[شماره کارت]', $response->json('steps.0.output'));
    }
}

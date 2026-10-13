<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\AuditEvent;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The AI assignment method talks to GapGPT (OpenAI-compatible); the owner enters the token in the console. */
class LlmGapGptTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'gp-test-token-123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en',
            'aiso.llm.api_key' => '', 'aiso.llm.model' => 'gapgpt-gemma-26b-a4b']);
        $this->seed(AisoSeeder::class);
    }

    private function as(string $id): void
    {
        $this->actingAs(AppUser::query()->find($id));
    }

    public function test_the_token_is_stored_encrypted_and_never_returned(): void
    {
        $this->as('owner');
        $r = $this->putJson('/api/settings/llm', ['apiKey' => self::TOKEN, 'model' => 'gapgpt-gemma-26b-a4b'])
            ->assertOk()
            ->assertJsonPath('llmKeySet', true)
            ->assertJsonPath('llmKeySource', 'settings')
            ->assertJsonPath('llmKeyHint', '…6789')
            ->assertJsonPath('llmAvailable', true)
            ->assertJsonPath('llmModel', 'gapgpt-gemma-26b-a4b');
        $this->assertStringNotContainsString(self::TOKEN, $r->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $this->getJson('/api/settings')->assertOk()->getContent());

        // in the database only the encrypted value is found
        $stored = DB::table('system_setting')->value('llm_api_key');
        $this->assertNotSame(self::TOKEN, $stored);
        $this->assertStringNotContainsString(self::TOKEN, (string) $stored);
        $this->assertSame(self::TOKEN, Crypt::decryptString($stored));

        // the audit trail says a token was set, not which
        $event = AuditEvent::query()->where('action_name', 'LLM_SETTINGS_CHANGED')->firstOrFail();
        $this->assertStringContainsString('token set', (string) $event->new_value);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($event->toArray()));
    }

    public function test_the_token_can_be_removed_and_the_environment_applies_again(): void
    {
        $this->as('owner');
        $this->putJson('/api/settings/llm', ['apiKey' => self::TOKEN])->assertOk();
        $this->putJson('/api/settings/llm', ['clearKey' => true])->assertOk()
            ->assertJsonPath('llmKeySet', false)->assertJsonPath('llmAvailable', false);

        config(['aiso.llm.api_key' => 'environment-token-9999']);
        $this->getJson('/api/settings')->assertJsonPath('llmKeySource', 'environment')->assertJsonPath('llmKeyHint', '…9999');
        // a token entered in the console wins over the environment
        $this->putJson('/api/settings/llm', ['apiKey' => self::TOKEN])->assertJsonPath('llmKeySource', 'settings');
    }

    public function test_bad_values_are_refused_and_only_the_owner_may_save(): void
    {
        $this->as('owner');
        $this->putJson('/api/settings/llm', ['apiKey' => 'short'])->assertStatus(400);
        $this->putJson('/api/settings/llm', ['apiKey' => 'has a space in it 12345'])->assertStatus(400);
        $this->putJson('/api/settings/llm', ['model' => 'bad model!'])->assertStatus(400);
        $this->getJson('/api/settings')->assertJsonPath('llmKeySet', false);

        $this->as('manager');
        $this->putJson('/api/settings/llm', ['apiKey' => self::TOKEN])->assertStatus(403);
    }

    public function test_a_blank_model_goes_back_to_the_default(): void
    {
        $this->as('owner');
        $this->putJson('/api/settings/llm', ['model' => 'my-other-model'])->assertOk()->assertJsonPath('llmModel', 'my-other-model');
        $this->putJson('/api/settings/llm', ['model' => ''])->assertOk()->assertJsonPath('llmModel', 'gapgpt-gemma-26b-a4b');
    }

    /** A fake GapGPT that assigns the first READY operation to the first user with room. */
    private function fakeGapGpt(): void
    {
        Http::fake(function (Request $request) {
            $snapshot = json_decode(substr($request['messages'][1]['content'], strlen("Snapshot:\n")), true);
            $op = $snapshot['readyOperations'][0];
            $user = collect($snapshot['users'])->firstWhere('freeTaskSlots', '>', 0);

            return Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'assignments' => [['operationId' => $op['id'], 'userId' => $user['id'], 'reason' => 'least loaded']],
                        'unassigned' => [], 'summary' => 'one proposal',
                    ])],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 321, 'completion_tokens' => 45],
            ]);
        });
    }

    public function test_the_ai_method_calls_gapgpt_with_the_saved_token(): void
    {
        $this->as('owner');
        $this->postJson('/api/admin/demo-data')->assertOk();
        $this->putJson('/api/settings/llm', ['apiKey' => self::TOKEN])->assertOk();
        $this->fakeGapGpt();

        $this->as('manager');
        $run = $this->postJson('/api/assignments/suggest', ['mode' => 'LLM'])->assertOk()
            ->assertJsonPath('effectiveMode', 'LLM')
            ->assertJsonPath('fallback', false)
            ->json();
        $this->assertNotEmpty($run['proposals']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($run));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
                && $request['model'] === 'gapgpt-gemma-26b-a4b'
                && $request['messages'][0]['role'] === 'system'
                && $request['messages'][1]['role'] === 'user'
                && !isset($request['system']);
        });
    }

    public function test_a_failed_call_falls_back_to_the_algorithm_without_leaking_the_token(): void
    {
        $this->as('owner');
        $this->postJson('/api/admin/demo-data')->assertOk();
        $this->putJson('/api/settings/llm', ['apiKey' => self::TOKEN])->assertOk();
        Http::fake(['api.gapgpt.app/*' => Http::response(['error' => 'invalid token'], 401)]);

        $this->as('manager');
        $r = $this->postJson('/api/assignments/suggest', ['mode' => 'LLM'])->assertOk()
            ->assertJsonPath('effectiveMode', 'ALGORITHM')->assertJsonPath('fallback', true);
        $this->assertStringContainsString('HTTP 401', json_encode($r->json('warnings')));
        $this->assertStringNotContainsString(self::TOKEN, $r->getContent());
    }
}

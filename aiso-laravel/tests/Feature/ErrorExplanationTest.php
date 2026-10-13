<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\SystemSetting;
use App\Services\AdminService;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A failed AI call or an unexpected error says what went wrong instead of "server error". */
class ErrorExplanationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'gp-test-token-123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en',
            'aiso.llm.api_key' => '', 'aiso.llm.model' => 'gapgpt-gemma-26b-a4b']);
        $this->seed(AisoSeeder::class);
        $this->actingAs(AppUser::query()->find('owner'));
        $this->postJson('/api/admin/demo-data')->assertOk();
        $this->putJson('/api/settings/llm', ['apiKey' => self::TOKEN])->assertOk();
        $this->actingAs(AppUser::query()->find('manager'));
    }

    /** Http::fake() adds stubs to earlier ones (the first match wins), so start from a clean factory each time. */
    private function fakeHttp(mixed $stub): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($stub);
    }

    /** @return string[] the warnings of an AI request that had to fall back to the algorithm */
    private function warningsOfFailedAiRun(): array
    {
        $r = $this->postJson('/api/assignments/suggest', ['mode' => 'LLM'])->assertOk()
            ->assertJsonPath('effectiveMode', 'ALGORITHM')->assertJsonPath('fallback', true);
        $this->assertStringNotContainsString(self::TOKEN, $r->getContent());

        return $r->json('warnings');
    }

    public function test_a_missing_certificate_bundle_is_explained_and_does_not_end_in_a_server_error(): void
    {
        // the exact failure seen on a Windows machine whose PHP has no CA bundle
        $this->fakeHttp(fn () => throw new ConnectionException('cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20) for https://api.gapgpt.app/v1/chat/completions'));

        $text = implode(' ', $this->warningsOfFailedAiRun());
        $this->assertStringContainsString('cannot verify the SSL certificate', $text);
        $this->assertStringContainsString('AISO_CA_BUNDLE', $text);
        $this->assertStringNotContainsString('https://', $text); // no URL, so no token can ride along
    }

    public function test_other_connection_problems_are_told_apart(): void
    {
        $seconds = (int) config('aiso.llm.timeout_seconds');
        $cases = [
            ["cURL error 28: Operation timed out after {$seconds}001 milliseconds", "did not answer within {$seconds} seconds"],
            ['cURL error 6: Could not resolve host: api.gapgpt.app', 'cannot find the address'],
            ['cURL error 7: Failed to connect to api.gapgpt.app port 443', 'firewall or proxy'],
        ];
        foreach ($cases as [$curl, $expected]) {
            $this->fakeHttp(fn () => throw new ConnectionException($curl));
            $this->assertStringContainsString($expected, implode(' ', $this->warningsOfFailedAiRun()), $curl);
        }
    }

    public function test_error_answers_of_the_service_are_explained_with_its_own_message(): void
    {
        $cases = [
            [401, ['error' => ['message' => 'Invalid API key ' . self::TOKEN]], 'rejected the token (HTTP 401)'],
            [404, ['error' => ['message' => 'model not found']], 'model was not found (HTTP 404)'],
            [429, ['message' => 'rate limit'], 'limiting requests (HTTP 429)'],
            [503, [], 'internal error (HTTP 503)'],
        ];
        foreach ($cases as [$status, $body, $expected]) {
            $this->fakeHttp(['api.gapgpt.app/*' => Http::response($body, $status)]);
            $text = implode(' ', $this->warningsOfFailedAiRun());
            $this->assertStringContainsString($expected, $text);
        }

        $this->fakeHttp(['api.gapgpt.app/*' => Http::response(['error' => ['message' => 'model not found']], 404)]);
        $this->assertStringContainsString('Service says: model not found', implode(' ', $this->warningsOfFailedAiRun()));
    }

    public function test_persian_users_get_the_explanation_in_persian(): void
    {
        // assignment texts are stored in the system language, which the owner chooses
        SystemSetting::current()->update(['language' => 'fa']);
        $this->fakeHttp(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem'));
        $r = $this->postJson('/api/assignments/suggest', ['mode' => 'LLM'])->assertOk();
        $text = implode(' ', $r->json('warnings'));
        $this->assertStringContainsString('گواهی SSL', $text);
        $this->assertStringContainsString('AISO_CA_BUNDLE', $text);
    }

    public function test_an_answer_of_the_wrong_shape_falls_back_instead_of_failing(): void
    {
        // assignments that are strings, not objects: this used to end in an unexpected server error
        $this->fakeHttp(['api.gapgpt.app/*' => Http::response(['choices' => [[
            'message' => ['content' => json_encode(['assignments' => ['OP-1', 'OP-2'], 'unassigned' => 'none', 'summary' => 5])],
            'finish_reason' => 'stop',
        ]]])]);
        $r = $this->postJson('/api/assignments/suggest', ['mode' => 'LLM'])->assertOk();
        $this->assertIsArray($r->json('warnings'));
    }

    public function test_an_answer_cut_off_by_the_length_limit_is_explained(): void
    {
        $this->fakeHttp(['api.gapgpt.app/*' => Http::response(['choices' => [[
            'message' => ['content' => '{"assignments":[{"operationId":"OP-'], 'finish_reason' => 'length',
        ]]])]);
        $this->assertStringContainsString('AISO_LLM_MAX_TOKENS', implode(' ', $this->warningsOfFailedAiRun()));
    }

    public function test_a_configured_certificate_bundle_is_passed_to_the_http_client(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'ca');
        config(['aiso.http.ca_bundle' => $bundle]);
        $seen = null;
        $this->fakeHttp(function (Request $request, array $options) use (&$seen) {
            $seen = $options['verify'] ?? null;

            return Http::response(['error' => 'x'], 500);
        });
        $this->postJson('/api/assignments/suggest', ['mode' => 'LLM'])->assertOk();
        @unlink($bundle);
        $this->assertSame($bundle, $seen);
    }

    public function test_an_unexpected_error_carries_a_reference_and_only_the_owner_sees_the_cause(): void
    {
        $this->mock(AdminService::class, fn ($m) => $m->shouldReceive('view')->andThrow(new \RuntimeException('database exploded')));

        $r = $this->getJson('/api/settings')->assertStatus(500)->assertJsonPath('message', 'Unexpected server error');
        $this->assertMatchesRegularExpression('/\AE-[A-Z0-9]{6}\z/', $r->json('reference'));
        $this->assertArrayNotHasKey('detail', $r->json());

        $this->actingAs(AppUser::query()->find('owner'));
        $own = $this->getJson('/api/settings')->assertStatus(500);
        $this->assertSame('RuntimeException: database exploded', $own->json('detail'));
    }
}

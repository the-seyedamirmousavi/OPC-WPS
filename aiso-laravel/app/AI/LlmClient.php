<?php

namespace App\AI;

use App\Exceptions\ApiException;
use App\Services\LicenseService;
use App\Services\LlmConfig;
use App\Support\HttpTls;
use App\Support\NetworkDiagnosis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One call to the AI service (GapGPT, an OpenAI-compatible API; settings from the owner console). It sends messages and
 * returns the text; it knows nothing about the protocol. Tests replace it with a fake that returns prepared messages.
 */
class LlmClient
{
    public function __construct(private readonly LicenseService $license)
    {
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{content: string, tokens_in: ?int, tokens_out: ?int, model: string}
     */
    public function complete(array $messages): array
    {
        $key = LlmConfig::apiKey();
        $model = LlmConfig::model();
        if ($key === null || $model === '' || !$this->license->llmAllowed()) {
            throw new ApiException(503, 'The AI assistant is not available. The owner can set it up in System administration.');
        }

        $timeout = (int) config('aiso.llm.timeout_seconds');
        HttpTls::allowWait($timeout);
        try {
            $response = Http::withToken($key)
                ->withOptions(HttpTls::options())
                ->acceptJson()
                ->timeout($timeout)
                ->post(LlmConfig::baseUrl() . '/chat/completions', [
                    'model' => $model,
                    'max_tokens' => (int) config('aiso.llm.max_tokens'),
                    'temperature' => 0.1,
                    'messages' => $messages,
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI agent call failed: ' . $e->getMessage());
            throw new ApiException(502, 'AI API call failed: ' . NetworkDiagnosis::connection($e, $timeout));
        }
        if (!$response->successful()) {
            Log::warning('AI agent call failed: HTTP ' . $response->status() . ' ' . mb_substr($response->body(), 0, 500));
            throw new ApiException(502, 'AI API call failed: ' . NetworkDiagnosis::status($response, $key));
        }

        $text = trim((string) ($response->json('choices.0.message.content') ?? ''));
        if ($text === '') {
            throw new ApiException(502, 'The AI service returned an empty answer');
        }

        return [
            'content' => $text,
            'tokens_in' => $response->json('usage.prompt_tokens'),
            'tokens_out' => $response->json('usage.completion_tokens'),
            'model' => $model,
        ];
    }
}

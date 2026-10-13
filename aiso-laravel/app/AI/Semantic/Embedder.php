<?php

namespace App\AI\Semantic;

use App\Services\LicenseService;
use App\Services\LlmConfig;
use App\Support\HttpTls;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Text embeddings (the OpenAI-compatible /embeddings endpoint). They come from the chat service by default, with its token,
 * or from their own endpoint (AISO_EMBEDDING_BASE_URL, e.g. a local Ollama, which needs no token). The embedding model is
 * optional: with no model name, no endpoint or token, or a model the service does not have, `embed` answers null and the
 * caller carries on with the lexical engine. A model that failed is not asked again for a while. Vectors are cached by
 * text, so a text is embedded once.
 */
class Embedder
{
    private const DOWN_KEY = 'aiso.embeddings.down';
    private const BATCH = 64;

    public function __construct(private readonly LicenseService $license)
    {
    }

    public function model(): string
    {
        return trim((string) config('aiso.llm.embedding_model'));
    }

    /** Whether an embedding call is worth trying now. It does not call the service. */
    public function available(): bool
    {
        return $this->model() !== '' && $this->hasEndpoint() && $this->license->llmAllowed() && !Cache::has(self::DOWN_KEY);
    }

    /** A short phrase for logs and the trace. */
    public function mode(): string
    {
        return $this->available() ? 'embedding ' . $this->model() : 'lexical (no embedding model)';
    }

    /**
     * @param string[] $texts
     * @return array<int, float[]>|null a vector for each text, in order; null when embeddings cannot be used
     */
    public function embed(array $texts): ?array
    {
        if (!$this->available() || $texts === []) {
            return null;
        }
        $model = $this->model();
        $vectors = [];
        $missing = [];
        foreach ($texts as $i => $text) {
            $cached = Cache::get($this->key($model, $text));
            if (is_array($cached)) {
                $vectors[$i] = $cached;
            } else {
                $missing[$i] = $text;
            }
        }

        foreach (array_chunk($missing, self::BATCH, true) as $chunk) {
            $got = $this->request($model, array_values($chunk));
            if ($got === null) {
                return null;
            }
            foreach (array_keys($chunk) as $n => $i) {
                $vectors[$i] = $got[$n];
                Cache::put($this->key($model, $texts[$i]), $got[$n], now()->addDays(30));
            }
        }
        ksort($vectors);

        return array_values($vectors);
    }

    public static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0.0;
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na == 0.0 || $nb == 0.0 ? 0.0 : max(0.0, $dot / (sqrt($na) * sqrt($nb)));
    }

    /** The own endpoint when one is set (a local model needs no token), else the chat service's, which needs its token. */
    private function hasEndpoint(): bool
    {
        return (string) config('aiso.llm.embedding_base_url') !== '' || LlmConfig::apiKey() !== null;
    }

    private function baseUrl(): string
    {
        $own = (string) config('aiso.llm.embedding_base_url');

        return $own !== '' ? $own : LlmConfig::baseUrl();
    }

    /** One call. Any failure marks the model as down for a while and answers null. @return array<int, float[]>|null */
    private function request(string $model, array $inputs): ?array
    {
        $seconds = (int) config('aiso.llm.embedding_timeout_seconds');
        HttpTls::allowWait($seconds);
        $http = Http::withOptions(HttpTls::options())->acceptJson()->timeout($seconds);
        // the chat service's token goes only to the chat service's own endpoint, never to another host
        if ((string) config('aiso.llm.embedding_base_url') === '' && LlmConfig::apiKey() !== null) {
            $http = $http->withToken((string) LlmConfig::apiKey());
        }
        try {
            $response = $http->post($this->baseUrl() . '/embeddings', ['model' => $model, 'input' => $inputs]);
        } catch (\Throwable $e) {
            Log::warning('Embedding call failed: ' . $e->getMessage());
            Cache::put(self::DOWN_KEY, true, now()->addMinutes(5));

            return null;
        }
        if (!$response->successful()) {
            Log::warning("Embedding model {$model} is not usable: HTTP {$response->status()} " . mb_substr($response->body(), 0, 200));
            // the model does not exist or the token may not use it: do not ask again for an hour; a hiccup: five minutes
            Cache::put(self::DOWN_KEY, true, now()->addMinutes(in_array($response->status(), [400, 401, 403, 404], true) ? 60 : 5));

            return null;
        }

        $rows = $response->json('data');
        if (!is_array($rows) || count($rows) !== count($inputs)) {
            Cache::put(self::DOWN_KEY, true, now()->addMinutes(60));

            return null;
        }
        usort($rows, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        $out = [];
        foreach ($rows as $row) {
            $v = $row['embedding'] ?? null;
            if (!is_array($v) || $v === []) {
                Cache::put(self::DOWN_KEY, true, now()->addMinutes(60));

                return null;
            }
            $out[] = array_map('floatval', $v);
        }

        return $out;
    }

    private function key(string $model, string $text): string
    {
        return 'aiso.emb.' . md5($model . '|' . $text);
    }
}

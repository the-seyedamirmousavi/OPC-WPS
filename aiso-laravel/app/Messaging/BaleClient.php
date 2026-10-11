<?php

namespace App\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for the Bale bot API (Telegram-like: https://tapi.bale.ai/bot{token}/{method}). Settings come from
 * config('aiso.bale'); the token is read from BALE_BOT_TOKEN. Failures are raised as BaleException without the token.
 */
class BaleClient
{
    /** Seconds the server may keep a getUpdates long poll open. */
    private const LONG_POLL_SECONDS = 25;

    /** @return array<string, mixed> the bot's User object */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * Long-polls for new updates after $offset (the update_id of the last one handled, plus one).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout = self::LONG_POLL_SECONDS): array
    {
        return $this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout], $timeout + 10);
    }

    /** Sends plain text (no parse_mode) to a chat. */
    public function sendMessage(string $chatId, string $text): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }

    /** @return array<mixed> the "result" of a successful call */
    private function call(string $method, array $params = [], int $timeout = 15): array
    {
        $token = (string) config('aiso.bale.token');
        if ($token === '') {
            throw new BaleException('BALE_BOT_TOKEN is not set');
        }
        $url = rtrim((string) config('aiso.bale.api_url'), '/') . "/bot{$token}/{$method}";

        try {
            $response = Http::timeout($timeout)->acceptJson()->post($url, $params);
        } catch (ConnectionException) {
            // the message of a connection error contains the URL, which contains the token: say nothing about it
            throw new BaleException("Bale API {$method} is unreachable");
        }

        $body = $response->json();
        if (!is_array($body) || ($body['ok'] ?? false) !== true) {
            $description = is_array($body) ? (string) ($body['description'] ?? '') : '';
            $code = is_array($body) ? (int) ($body['error_code'] ?? $response->status()) : $response->status();
            throw new BaleException("Bale API {$method} failed with code {$code}" . ($description !== '' ? ": {$description}" : ''));
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }
}

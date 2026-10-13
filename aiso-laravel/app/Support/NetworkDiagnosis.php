<?php

namespace App\Support;

use Illuminate\Http\Client\Response;

/**
 * Turns a failed call to a remote service (the AI service, Bale) into one sentence that says what is wrong and what to
 * do, instead of a raw cURL message. Messages contain no URL, so they never carry a token.
 */
final class NetworkDiagnosis
{
    private function __construct()
    {
    }

    /** What went wrong before any answer arrived (certificate, timeout, address, connection). */
    public static function connection(\Throwable $e, int $timeoutSeconds): string
    {
        $m = $e->getMessage();
        if (preg_match('/cURL error (60|77|58|35)|SSL certificate|certificate verify|self.signed/i', $m) === 1) {
            return 'The server cannot verify the SSL certificate of the remote service (the server has no CA certificate bundle). '
                . 'The administrator should set curl.cainfo in php.ini or AISO_CA_BUNDLE in the environment.';
        }
        // connecting is limited separately (about 10 seconds): failing to connect is a network problem, not a slow answer
        if (preg_match('/Connection timed out after (\d+) ?ms|Connection timed out after (\d+) milliseconds/i', $m, $c) === 1) {
            $seconds = (int) round(((int) ($c[1] !== '' ? $c[1] : $c[2])) / 1000);

            return "Could not connect to the remote service within {$seconds} seconds. Check the network or try again shortly.";
        }
        if (preg_match('/cURL error 28|timed out|timeout/i', $m) === 1) {
            return "The remote service did not answer within {$timeoutSeconds} seconds.";
        }
        if (preg_match('/cURL error 6\b|resolve host|name or service not known|getaddrinfo/i', $m) === 1) {
            return 'The server cannot find the address of the remote service (no internet connection or a DNS problem).';
        }
        if (preg_match('/cURL error 7\b|failed to connect|connection refused|couldn.t connect/i', $m) === 1) {
            return 'The server cannot connect to the remote service (no internet connection, or a firewall or proxy blocks it).';
        }

        return 'Network error: ' . self::clean($m, 160);
    }

    /**
     * What an error answer means. $secret (the token) is removed from the service's own message if it appears there.
     */
    public static function status(Response $response, ?string $secret = null): string
    {
        $s = $response->status();
        $text = match (true) {
            $s === 401 || $s === 403 => "The service rejected the token (HTTP {$s}). Check the token in the owner console.",
            $s === 402 => 'The service says the account has no credit or the plan does not allow this (HTTP 402).',
            $s === 404 => 'The service address or the model was not found (HTTP 404). Check the model name in the owner console.',
            $s === 429 => 'The service is limiting requests (HTTP 429): too many requests, or the quota is used up. Try again later.',
            $s === 400 || $s === 422 => "The service rejected the request (HTTP {$s}). Check the model name in the owner console.",
            $s >= 500 => "The service had an internal error (HTTP {$s}). Try again later.",
            default => "The service answered with HTTP {$s}.",
        };
        $says = self::serviceMessage($response);
        if ($says !== null) {
            if ($secret !== null && $secret !== '') {
                $says = str_replace($secret, '[token]', $says);
            }
            $text .= ' Service says: ' . $says;
        }

        return $text;
    }

    /** The service's own explanation (error.message, error or message in the JSON body), shortened; null if none. */
    private static function serviceMessage(Response $response): ?string
    {
        $body = $response->json();
        $msg = null;
        if (is_array($body)) {
            $err = $body['error'] ?? null;
            $msg = is_string($err) ? $err : (is_array($err) ? ($err['message'] ?? null) : ($body['message'] ?? $body['detail'] ?? null));
        }
        if (!is_string($msg) || trim($msg) === '') {
            return null;
        }

        return self::clean($msg, 200);
    }

    /** One line, no URLs, at most $max characters. */
    private static function clean(string $s, int $max): string
    {
        $s = preg_replace('~https?://\S+~i', '[url]', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}

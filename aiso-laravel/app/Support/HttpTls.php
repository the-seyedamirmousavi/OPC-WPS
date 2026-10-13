<?php

namespace App\Support;

/**
 * Certificate bundle for outgoing HTTPS calls. PHP on Windows often ships without one, so every HTTPS call fails with
 * "cURL error 60". AISO_CA_BUNDLE can point to a bundle file (a cacert.pem, or the ca-bundle.crt of Git). When it is not set,
 * PHP's own settings (curl.cainfo, openssl.cafile) apply. Certificate checking is never switched off.
 */
final class HttpTls
{
    private function __construct()
    {
    }

    /**
     * A web request is stopped by PHP after max_execution_time (30 s in php.ini here), even while it waits for a remote
     * answer. A slow model answers after that, and the stop is a fatal error that the error handler cannot turn into a
     * readable message. Before a call that may wait $seconds, the limit is raised to cover the wait plus a margin.
     */
    public static function allowWait(int $seconds): void
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < $seconds + 15) {
            @set_time_limit($seconds + 15);
        }
    }

    /** @return array<string, string> Guzzle options for Http::withOptions() */
    public static function options(): array
    {
        $path = (string) config('aiso.http.ca_bundle');

        return $path !== '' && is_file($path) ? ['verify' => $path] : [];
    }
}

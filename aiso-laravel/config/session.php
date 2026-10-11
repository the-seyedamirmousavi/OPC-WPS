<?php

return [
    'driver' => env('SESSION_DRIVER', 'database'),
    // Same lifetime as the former JWT (480 minutes).
    'lifetime' => (int) env('SESSION_LIFETIME', 480),
    'expire_on_close' => false,
    'encrypt' => false,
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => 'sessions',
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', 'aiso_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    // Over plain HTTP on a non-localhost host set SESSION_SECURE_COOKIE=false, otherwise the browser will not keep it.
    'secure' => (bool) env('SESSION_SECURE_COOKIE', true),
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
];

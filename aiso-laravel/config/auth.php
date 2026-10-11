<?php

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        // The application user table is app_user (business id as primary key).
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\AppUser::class,
        ],
    ],
    'passwords' => [],
    'password_timeout' => 10800,
];

<?php

/*
 * AISO application settings. Everything here is read from the environment; nothing secret is stored in the database.
 */
return [

    'seed' => [
        // Seeds OWNER, MANAGER, USER_1..3 on first start when the user table is empty.
        'enabled' => (bool) env('AISO_SEED_ENABLED', true),
        'password' => env('AISO_SEED_PASSWORD', 'ChangeMe!123'),
        // Language of system-generated texts on a fresh install ("fa" or "en"); the owner can change it in Settings.
        'language' => env('AISO_LANGUAGE', 'fa'),
        // Loads the sample workbook (resources/samples/simple-sample.xlsx) with the seed, so the screens are not empty.
        'demo_data' => (bool) env('AISO_SEED_DEMO_DATA', true),
    ],

    'llm' => [
        // The key is read from the environment only; it is never stored in the database or sent to the browser.
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        // Model id. Required for the LLM method; there is deliberately no built-in default.
        'model' => env('AISO_LLM_MODEL', ''),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'timeout_seconds' => (int) env('AISO_LLM_TIMEOUT', 120),
        'max_tokens' => (int) env('AISO_LLM_MAX_TOKENS', 16000),
        'fallback_to_algorithm' => (bool) env('AISO_LLM_FALLBACK', true),
    ],

    'assignment' => [
        // Soft preference: the resource's responsible user is treated as if they had this many fewer load-hours.
        'responsible_bonus_hours' => (float) env('AISO_RESPONSIBLE_BONUS_HOURS', 8),
    ],

    'bale' => [
        // Token of the bot created with @BotFather. Read from the environment only; never stored or returned by the API.
        'token' => env('BALE_BOT_TOKEN', ''),
        // Bot API base URL. Methods are called as {base}/bot{token}/{method}.
        'api_url' => env('BALE_API_URL', 'https://tapi.bale.ai'),
    ],
];

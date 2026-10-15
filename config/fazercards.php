<?php

return [

    'base_url' => env('FAZERCARDS_API_URL', 'https://api.fzr.cards/api/v2'),

    'api_key' => env('FAZERCARDS_API_KEY'),

    // Used by the webhook receiver (Phase 8).
    'webhook_secret' => env('FAZERCARDS_WEBHOOK_SECRET'),

        // Local/testing only: use a built-in fake provider so you can test orders without spending money.
    'fake' => (bool) env('FAZERCARDS_FAKE', false),

    'connect_timeout' => (int) env('FAZERCARDS_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('FAZERCARDS_TIMEOUT', 15),
    'validate_timeout' => (int) env('FAZERCARDS_VALIDATE_TIMEOUT', 10),
    'order_timeout' => (int) env('FAZERCARDS_ORDER_TIMEOUT', 30),

    // Safe (GET) requests only: attempts and wait between them. 0 ms = do not wait (tests).
    'retries' => 3,
    'retry_sleep_ms' => (int) env('FAZERCARDS_RETRY_SLEEP_MS', 400),

    // Warn when the provider wallet falls below this many USD.
    'low_balance' => env('FAZERCARDS_LOW_BALANCE', '20.00'),

    'sync' => [
        // Pause between category requests, to stay well inside the provider's rate limits.
        'delay_ms' => (int) env('FAZERCARDS_SYNC_DELAY_MS', 700),
    ],
];
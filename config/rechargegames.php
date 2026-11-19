<?php

return [

    // RechargeGames reseller API. HTTPS only. Never put a trailing slash on paths.
    'base_url' => env('RECHARGEGAMES_API_URL', 'https://api.rechargegame.games'),

    // Created in the RechargeGames dashboard: Profile > API access (starts with gv_live_).
    'api_key' => env('RECHARGEGAMES_API_KEY'),

    // Created in the dashboard: Profile > Webhook (starts with whsec_).
    'webhook_secret' => env('RECHARGEGAMES_WEBHOOK_SECRET'),

    // Local/testing only: use a built-in fake provider so you can test orders without spending money.
    'fake' => (bool) env('RECHARGEGAMES_FAKE', false),

    // true = every order is sent with test:true (free, completes after ~10 seconds). For the first live check only.
    'test_orders' => (bool) env('RECHARGEGAMES_TEST_ORDERS', false),

    'connect_timeout' => (int) env('RECHARGEGAMES_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('RECHARGEGAMES_TIMEOUT', 15),
    'catalog_timeout' => (int) env('RECHARGEGAMES_CATALOG_TIMEOUT', 60),
    'validate_timeout' => (int) env('RECHARGEGAMES_VALIDATE_TIMEOUT', 10),
    'order_timeout' => (int) env('RECHARGEGAMES_ORDER_TIMEOUT', 20),

    // Safe (GET) requests only: attempts and wait between them. 0 ms = do not wait (tests).
    'retries' => 3,
    'retry_sleep_ms' => (int) env('RECHARGEGAMES_RETRY_SLEEP_MS', 400),

    // Warn when the provider balance falls below this many USDT (treated as USD).
    'low_balance' => env('RECHARGEGAMES_LOW_BALANCE', '20.00'),

    // Webhook timestamps older than this many seconds are rejected.
    'webhook_tolerance' => (int) env('RECHARGEGAMES_WEBHOOK_TOLERANCE', 300),

    'sync' => [
        'delay_ms' => (int) env('RECHARGEGAMES_SYNC_DELAY_MS', 700),
    ],
];

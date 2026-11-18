<?php

return [
    'base_url' => env('HARIKI_API_URL', 'https://api.harikitopup.com'),

    // Panel > API key (starts with hrk_live_). Put it ONLY in the server .env.
    'api_key' => env('HARIKI_API_KEY'),

    // Panel > webhook secret (starts with whsec_).
    'webhook_secret' => env('HARIKI_WEBHOOK_SECRET'),

    // Sent with every order (Hariki requires one unless the key has a default).
    'callback_url' => env('HARIKI_CALLBACK_URL', rtrim((string) env('APP_URL', ''), '/').'/webhooks/hariki'),

    // Games whose orders need a Server/Zone ID besides the Player ID.
    'server_games' => array_filter(array_map('trim', explode(',', (string) env('HARIKI_SERVER_GAMES', 'mlbb')))),

    // Local/testing only: use the built-in fake provider so you can test orders without spending money.
    'fake' => (bool) env('HARIKI_FAKE', false),

    // Hariki charges this extra amount (USD) per order; it is added to every product cost we record.
    'fee_per_order' => env('HARIKI_FEE_PER_ORDER', '0.02'),

    // Hariki forbids polling. When true, an order is only looked up at Hariki after its webhook arrives,
    // plus at most once per poll_interval seconds as a safety net.
    'poll_guard' => (bool) env('HARIKI_POLL_GUARD', true),
    'poll_interval' => (int) env('HARIKI_POLL_INTERVAL', 600),

    'sync' => ['delay_ms' => (int) env('HARIKI_SYNC_DELAY_MS', 700)],

    'connect_timeout' => (int) env('HARIKI_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('HARIKI_TIMEOUT', 15),
    'catalog_timeout' => (int) env('HARIKI_CATALOG_TIMEOUT', 60),
    'order_timeout' => (int) env('HARIKI_ORDER_TIMEOUT', 20),
    'retries' => 3,
    'retry_sleep_ms' => (int) env('HARIKI_RETRY_SLEEP_MS', 400),
    'low_balance' => env('HARIKI_LOW_BALANCE', '20.00'),
    'webhook_tolerance' => (int) env('HARIKI_WEBHOOK_TOLERANCE', 300),
];

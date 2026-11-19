<?php

// FazerCards is used ONLY for the Player ID check on the top-up pages.
// Orders go to RechargeGames.
return [
    'base_url' => env('FAZERCARDS_API_URL', 'https://api.fzr.cards/api/v2'),

    'api_key' => env('FAZERCARDS_API_KEY'),

    'connect_timeout' => (int) env('FAZERCARDS_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('FAZERCARDS_TIMEOUT', 15),
    'validate_timeout' => (int) env('FAZERCARDS_VALIDATE_TIMEOUT', 10),
    'retries' => 3,
    'retry_sleep_ms' => (int) env('FAZERCARDS_RETRY_SLEEP_MS', 400),
];

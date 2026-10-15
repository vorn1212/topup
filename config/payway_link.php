<?php

return [

    // The ABA PayWay payment link (opened by the server-side worker only).
    'url' => env('PAYWAY_LINK_URL'),

    'node_binary' => env('PAYWAY_NODE_BINARY', 'node'),

    'worker_path' => base_path('tools/payway-worker/worker.js'),
    
        // Default number of parallel workers for "php artisan payway:workers".
    'workers' => (int) env('PAYWAY_WORKERS', 3),

    // Max time the worker keeps the page open after the QR appears (ABA's QR lasts about 3 minutes).
    'session_seconds' => (int) env('PAYWAY_SESSION_SECONDS', 200),

    // How long the QR is shown to the customer.
    'qr_seconds' => (int) env('PAYWAY_QR_SECONDS', 175),

    // false = show the browser window (useful while testing).
    'headless' => filter_var(env('PAYWAY_HEADLESS', true), FILTER_VALIDATE_BOOLEAN),

    'queue' => 'payway',
];
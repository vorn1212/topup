<?php

return [

    /*
    | Every permission the system knows about. Each one is registered as a Gate
    | in AppServiceProvider, so you can use @can('orders.view') or
    | ->middleware('can:orders.view').
    */
    'all' => [
        'dashboard.view',
        'users.view',
        'users.manage',        // suspend / activate
        'games.manage',
        'products.manage',
        'pricing.manage',
        'pricing.allow_loss',  // save a selling price below provider cost
        'orders.view',
        'orders.manage',       // notes, manual recheck, manual resolve
        'deposits.view',
        'transactions.view',
        'wallet.adjust',       // manual credit / debit
        'reports.view',
        'audit.view',
        'settings.manage',     // payment config, API credentials
        'roles.manage',
        'provider.manage',
    ],

    'roles' => [

        'super_admin' => ['*'],

        'admin' => [
            'dashboard.view',
            'users.view',
            'users.manage',
            'games.manage',
            'products.manage',
            'pricing.manage',
            'orders.view',
            'orders.manage',
            'deposits.view',
            'transactions.view',
            'reports.view',
            'audit.view',
            'provider.manage',
        ],

        'support' => [
            'dashboard.view',
            'users.view',
            'orders.view',
            'deposits.view',
            'transactions.view',
        ],

        'user' => [],
    ],
];
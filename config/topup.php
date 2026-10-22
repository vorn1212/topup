<?php

return [

    'currency' => 'USD',

    'deposit' => [
        'min' => env('DEPOSIT_MIN', '1.00'),
        'max' => env('DEPOSIT_MAX', '500.00'),
        'expire_minutes' => (int) env('DEPOSIT_EXPIRE_MINUTES', 30),
    ],

    /*
    | Used only by StaffSeeder. Never put real production passwords in code.
    */
    'seed' => [
        'password' => env('SEED_STAFF_PASSWORD'),
        'super_admin_email' => env('SEED_SUPER_ADMIN_EMAIL'),
        'admin_email' => env('SEED_ADMIN_EMAIL'),
        'support_email' => env('SEED_SUPPORT_EMAIL'),
    ],
    /*
    | E-mail notifications. Notifications always appear on the website; when this is true
    | they are also sent by e-mail (needs a working MAIL_* setup). Set NOTIFY_BY_MAIL in .env.
    */
    'notifications' => [
        'mail' => (bool) env('NOTIFY_BY_MAIL', false),
    ],

];
<?php

declare(strict_types=1);

/*
| The super-admin panel at /admin (spec §22, D85). `php artisan coffer:platform-admin`
| reads these when no options are given; leave them empty in production .env files after
| the first admin exists.
*/

return [
    'admin' => [
        'email' => env('PLATFORM_ADMIN_EMAIL'),
        'name' => env('PLATFORM_ADMIN_NAME'),
        'password' => env('PLATFORM_ADMIN_PASSWORD'),
    ],

    // D94: every platform admin sets up an authenticator app before the panel opens.
    'mfa_required' => (bool) env('PLATFORM_MFA_REQUIRED', true),

    // D97: the bearer token for GET /health (monitors); platform admins need none.
    'health_token' => env('HEALTH_CHECK_TOKEN'),
];

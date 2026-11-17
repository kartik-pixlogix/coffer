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
];

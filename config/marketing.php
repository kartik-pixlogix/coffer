<?php

declare(strict_types=1);

/*
| The public marketing site (spec §21, D83, D84).
*/

return [
    // Where Contact / Book a demo requests are emailed (queued). Leads are also stored
    // in `contact_requests` and listed in the super-admin panel.
    'contact_email' => env('MARKETING_CONTACT_EMAIL', 'hello@example.com'),

    // Contact form submissions allowed per IP address per hour.
    'contact_per_hour' => (int) env('MARKETING_CONTACT_PER_HOUR', 5),

    // Organization details for the JSON-LD block and the footer.
    'organization' => [
        'name' => env('APP_NAME', 'Coffer'),
        'email' => env('MARKETING_CONTACT_EMAIL', 'hello@example.com'),
    ],
];

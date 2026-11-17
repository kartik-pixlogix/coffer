<?php

declare(strict_types=1);

/*
| Response security headers (spec §23, D93). App\Http\Middleware\SecurityHeaders sends them
| on every response: a nonce-based CSP for the Inertia app, a relaxed one for Filament's
| /admin (Alpine and Livewire need inline and eval), HSTS in production only.
*/
return [

    // Off only to debug a CSP problem; the headers ship on in every environment.
    'csp_enabled' => (bool) env('SECURITY_CSP_ENABLED', true),

    // Report-only sends the policy without enforcing it (for trying a change in production).
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

    // Optional URL that receives CSP violation reports (report-uri).
    'csp_report_uri' => env('SECURITY_CSP_REPORT_URI'),

    // HSTS in production: a year, subdomains included; preload only once you've submitted the domain.
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    'hsts_preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),

];

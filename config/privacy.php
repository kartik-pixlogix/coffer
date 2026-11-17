<?php

declare(strict_types=1);

/*
| DPDPA rights (spec §23 "Privacy", D98): personal and workspace data exports, account and
| workspace deletion after a grace period. `coffer:privacy-purge` runs nightly.
*/

return [

    // Days between asking to delete (an account or a workspace) and the erasure.
    'grace_days' => (int) env('PRIVACY_GRACE_DAYS', 30),

    // Days a finished export's download link (and file) lasts.
    'export_ttl_days' => (int) env('PRIVACY_EXPORT_TTL_DAYS', 7),

    // Where export ZIPs are written (private).
    'disk' => env('PRIVACY_EXPORT_DISK', 'local'),

];

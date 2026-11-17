<?php

declare(strict_types=1);

/* In-app and email notifications (spec §19, D78). */

return [
    // "Kept for 30 days" (Notifications.dc.html): older rows are pruned nightly.
    'retention_days' => 30,

    // "Settlement due" fires when one JV side owes another at least this much (paise; ₹10,000).
    'settlement_threshold_paise' => (int) env('SETTLEMENT_DUE_THRESHOLD_PAISE', 1_000_000),

    // Rows per page on /notifications, and how many the bell's panel loads.
    'per_page' => 20,
    'panel_limit' => 30,
];

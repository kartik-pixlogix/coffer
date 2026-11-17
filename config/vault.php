<?php

declare(strict_types=1);

return [
    /*
    | Envelope encryption (spec §14.3, D66). The master key wraps each company's data key;
    | it never encrypts secrets itself. Both keys are 32 random bytes, base64-encoded with
    | a `base64:` prefix: `php artisan coffer:vault-key` prints fresh ones.
    |
    | To rotate the master key, move the current value into VAULT_PREVIOUS_MASTER_KEYS
    | (comma-separated), set a new VAULT_MASTER_KEY and run `coffer:vault-rotate --master`.
    */
    'master_key' => env('VAULT_MASTER_KEY'),
    'previous_master_keys' => env('VAULT_PREVIOUS_MASTER_KEYS', ''),

    /* Separate HMAC key for password-reuse fingerprints (spec §14.3). */
    'fingerprint_key' => env('VAULT_FINGERPRINT_KEY'),

    'hibp_enabled' => (bool) env('VAULT_HIBP_ENABLED', false),

    /*
    | Default vault policies (spec §14.4 "Policies", VaultLockPolicies.dc.html, D76). The
    | Policies page saves per-company values; a company without them uses these.
    */
    'policy' => [
        // "Ask again before revealing a password": every reveal or copy needs a new code.
        'fresh_code_each_reveal' => false,
        // Lock the vault when the tab closes or reloads.
        'lock_on_close' => false,
        // "Who can export": admins | admins_partners | none (spec "Admin only").
        'export_who' => 'admins',
        // Ask for a reason on every export (it goes in the audit log).
        'export_reason' => true,
        // The password generator's shortest length (spec: 12–64).
        'generator_min_length' => 12,
        // "Ask for a 2FA code again after": a reveal needs a 2FA check newer than this.
        'reauth_minutes' => 15,
        // The vault locks after this long without activity.
        'idle_lock_minutes' => 15,
        // Copied secrets are cleared from the clipboard after this (best effort).
        'clipboard_clear_seconds' => 30,
        // A revealed value hides itself after this.
        'reveal_seconds' => 30,
        // Have I Been Pwned checks (D74): on per company, but only if VAULT_HIBP_ENABLED is true.
        'breach_checks' => true,
    ],

    // Spec §14.3: reveals (and copies, codes and downloads) are rate-limited per user.
    'reveals_per_minute' => 30,
    // Wrong unlock codes or passwords before a pause (VaultLockPolicies: "before a 5-minute pause").
    'unlock_attempts' => 5,
    'unlock_lockout_seconds' => 300,

    // Attachments: keys, certificates and recovery files up to 5 MB each (spec §14.1).
    'attachment_max_kb' => 5120,
    'attachments_disk' => 'local',

    // Data-key rotation re-encrypts this many rows per queued job.
    'rotation_chunk' => 200,
];

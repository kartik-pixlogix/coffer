<?php

use App\Ledger\LedgerGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Database triggers that refuse changes to posted journal rows (D148). A host that doesn't
     * let the app's database user create triggers (some managed MySQL with binary logging)
     * gets a warning instead of a failed deploy; /health and `coffer:ledger-guard` report it,
     * and `coffer:ledger-guard --install` adds it once the privilege is granted.
     */
    public function up(): void
    {
        try {
            LedgerGuard::install();
        } catch (QueryException $e) {
            Log::warning('Ledger guard triggers could not be created; run `php artisan coffer:ledger-guard --install` with a user that may create triggers.', ['code' => $e->getCode()]);
            defined('STDERR') && fwrite(STDERR, "  ! Ledger guard triggers not created (database refused). See docs/DEPLOY.md, \"Ledger guard\".\n");
        }
    }

    public function down(): void
    {
        LedgerGuard::uninstall();
    }
};

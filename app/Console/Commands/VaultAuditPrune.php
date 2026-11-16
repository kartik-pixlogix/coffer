<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention for the vault audit log (VaultAudit.dc.html "Events are kept for 12 months",
 * D75): events older than 12 months are deleted, oldest first. Nothing else ever removes
 * or changes an event (the model refuses updates and deletes), so this goes through the
 * query builder on purpose. Runs monthly.
 */
final class VaultAuditPrune extends Command
{
    public const int KEEP_MONTHS = 12;

    protected $signature = 'coffer:vault-audit-prune';

    protected $description = 'Delete vault audit events older than 12 months';

    public function handle(): int
    {
        $before = now()->subMonths(self::KEEP_MONTHS)->startOfDay();
        $deleted = 0;

        do {
            $ids = DB::table('vault_audit_events')->where('created_at', '<', $before)->orderBy('id')->limit(1000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table('vault_audit_events')->whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());

        $this->info("Deleted {$deleted} vault audit ".($deleted === 1 ? 'event' : 'events').' older than '.self::KEEP_MONTHS.' months.');

        return self::SUCCESS;
    }
}

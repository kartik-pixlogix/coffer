<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\BackupDrill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The restore drill (Phase 10, D150): opens the newest backup (or `--file`) with
 * BACKUP_PASSWORD, checks its manifest and dump, and, when BACKUP_DRILL_DATABASE names a
 * scratch database, loads the dump into it and compares every table's row count with the
 * manifest, the books' debits with their credits and the ledger guard. Monthly from the
 * scheduler; run it by hand after changing anything about backups.
 */
final class BackupDrillCommand extends Command
{
    protected $signature = 'coffer:backup-drill {--file= : A backup path on the backup disk (default: the newest)}';

    protected $description = 'Restore the newest encrypted backup into a scratch database and check it';

    public function handle(BackupDrill $drill): int
    {
        try {
            $report = $drill->run($this->option('file') !== null ? (string) $this->option('file') : null);
        } catch (Throwable $e) {
            Log::critical('Backup restore drill failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $this->error('Restore drill failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup {$report['path']} (taken {$report['taken']}) opened with the backup password.");

        foreach ($report['checks'] as $check) {
            $this->line("  ✓ {$check}");
        }

        if (! $report['restored']) {
            $this->warn('  ! Not restored: set BACKUP_DRILL_DATABASE to a scratch database to load the dump and compare it.');
        }

        return self::SUCCESS;
    }
}

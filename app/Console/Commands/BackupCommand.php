<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\Backup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

/** Spec §23 / D96: the nightly encrypted backup, then retention. */
final class BackupCommand extends Command
{
    protected $signature = 'coffer:backup
        {--no-files : Database only, skip the private and public storage}
        {--prune-only : Only delete backups past the retention}';

    protected $description = 'Write an encrypted backup of the database and file storage, then prune old backups';

    public function handle(Backup $backup): int
    {
        try {
            if (! $this->option('prune-only')) {
                $result = $backup->run(withFiles: ! $this->option('no-files'));
                $this->info("Backup written to {$result['path']} (".Number::fileSize($result['size']).", {$result['files']} files).");
            }

            foreach ($backup->prune() as $path) {
                $this->line("Deleted {$path} (past the retention).");
            }
        } catch (Throwable $e) {
            // The failed-backup alert: critical reaches whichever log channel is set up (D97).
            Log::critical('Backup failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

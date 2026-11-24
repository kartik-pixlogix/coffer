<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ledger\LedgerGuard;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Reports, installs or reinstalls the database triggers that keep posted journal rows
 * unchangeable (D148). `--install` is for a host where the migration couldn't create them.
 */
final class LedgerGuardCommand extends Command
{
    protected $signature = 'coffer:ledger-guard {--install : Create or recreate the triggers}';

    protected $description = 'Check (or install) the database guard on posted journal rows';

    public function handle(): int
    {
        if (! LedgerGuard::supported()) {
            $this->warn('This database driver has no ledger guard (MySQL, MariaDB and PostgreSQL do).');

            return self::SUCCESS;
        }

        if ($this->option('install')) {
            try {
                LedgerGuard::install();
            } catch (QueryException $e) {
                $this->error('The database refused to create the triggers. The database user needs the TRIGGER privilege'
                    .' (on MySQL with binary logging, also log_bin_trust_function_creators = 1).');

                return self::FAILURE;
            }
        }

        if (! LedgerGuard::installed()) {
            $this->error('Ledger guard: not installed. Run `php artisan coffer:ledger-guard --install`.');

            return self::FAILURE;
        }

        $this->info('Ledger guard: installed. Posted journal rows cannot be changed or deleted in the database.');

        return self::SUCCESS;
    }
}

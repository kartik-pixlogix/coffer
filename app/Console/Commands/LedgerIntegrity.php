<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ledger\IntegrityCheck;
use App\Models\Company;
use App\Notifications\LedgerIntegrityFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Nightly check of the ledger invariants (spec §4.5) for every company. A failure means
 * a bug: it's logged and the company's Admins get an email.
 */
final class LedgerIntegrity extends Command
{
    protected $signature = 'coffer:ledger-integrity {--company= : Only this company id}';

    protected $description = 'Check every company’s books against the ledger invariants';

    public function handle(IntegrityCheck $check): int
    {
        $failed = 0;

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $failures = $check->run($company);

            if ($failures === []) {
                continue;
            }

            $failed++;
            Log::error('Ledger integrity check failed', ['company_id' => $company->id, 'failures' => $failures]);
            Notification::send($company->admins(), new LedgerIntegrityFailed($company, $failures));

            $this->error("{$company->name}: ".count($failures).' problem(s)');

            foreach ($failures as $failure) {
                $this->line("  - {$failure}");
            }
        }

        $this->info($failed === 0 ? "All {$companies->count()} companies balance." : "{$failed} of {$companies->count()} companies need attention.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

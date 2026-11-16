<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ledger\ChartOfAccounts;
use App\Ledger\OpeningBalance;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Sets up the books for companies created before the ledger existed (Phase 2): categories,
 * money accounts, the chart of accounts and the opening-balance entry. Safe to run again.
 */
final class LedgerSync extends Command
{
    protected $signature = 'coffer:ledger-sync {--company= : Only this company id}';

    protected $description = 'Set up categories, money accounts, the chart and opening balances for existing companies';

    public function handle(ChartOfAccounts $chart, OpeningBalance $opening): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $chart->setUp($company);

            $posted = $opening->current($company) === null ? $opening->post($company) : null;

            $this->line("{$company->name}: books ready".($posted !== null ? ', opening balance posted' : ''));
        }

        return self::SUCCESS;
    }
}

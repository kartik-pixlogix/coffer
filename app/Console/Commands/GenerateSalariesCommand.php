<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Team\SalaryGenerator;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Monthly salary run (spec §8.3): on the 1st, for the previous month, every company.
 * Safe to run again: unchanged salaries stay as they are.
 */
final class GenerateSalariesCommand extends Command
{
    protected $signature = 'coffer:salaries {--month= : YYYY-MM, default last month} {--company= : Only this company id}';

    protected $description = 'Post salary expenses for a month (idempotent per member-month)';

    public function handle(SalaryGenerator $salaries, CurrentCompany $current): int
    {
        $option = $this->option('month');
        $month = is_string($option) && preg_match('/^\d{4}-\d{2}$/', $option) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $option)
            : CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        if ($month === null) {
            $this->error('Use --month=YYYY-MM.');

            return self::INVALID;
        }

        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $summary = $current->run($company, fn () => $salaries->runMonth($company, $month));
            $this->line("{$company->name}, {$month->format('M Y')}: {$summary->describe()}");
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Notifications\Events\SalaryRunDone;
use App\Services\Notifications\Audience;
use App\Services\Team\SalaryGenerator;
use App\Support\CurrentCompany;
use App\Support\Permissions\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Monthly salary run (spec §8.3): on the 1st, for the previous month, every company.
 * Safe to run again: unchanged salaries stay as they are.
 */
final class GenerateSalariesCommand extends Command
{
    protected $signature = 'coffer:salaries {--month= : YYYY-MM, default last month} {--company= : Only this company id}';

    protected $description = 'Post salary expenses for a month (idempotent per member-month)';

    public function handle(SalaryGenerator $salaries, CurrentCompany $current, Audience $audience): int
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

            // Spec §19 "salary run done": the people who manage the team hear when something was posted.
            if ($summary->counts['posted'] + $summary->counts['updated'] + $summary->counts['removed'] > 0) {
                Notification::send(
                    $audience->withPermission($company, Catalog::actionPermission('team.manage')),
                    SalaryRunDone::for($company, $month, $summary),
                );
            }
        }

        return self::SUCCESS;
    }
}

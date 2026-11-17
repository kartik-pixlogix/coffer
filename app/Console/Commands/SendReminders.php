<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CompanyType;
use App\Ledger\FiscalYears;
use App\Ledger\Period;
use App\Ledger\SettlementCalculator;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CofferNotification;
use App\Notifications\Events\FiscalYearReminder;
use App\Notifications\Events\SettlementDue;
use App\Notifications\Events\TrialEnding;
use App\Services\Billing\WorkspaceBilling;
use App\Services\Notifications\Audience;
use App\Support\CurrentCompany;
use App\Support\Permissions\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Daily reminders (spec §19, D78, D80): a settlement due when one JV side owes another
 * more than the threshold (at most weekly), a fiscal year that ended and is still open
 * (at most monthly), and a free trial ending in 3 days or tomorrow.
 */
final class SendReminders extends Command
{
    protected $signature = 'coffer:reminders';

    protected $description = 'Send settlement-due, fiscal-year-close and trial-ending reminders';

    private int $sent = 0;

    public function handle(SettlementCalculator $settlements, FiscalYears $years, CurrentCompany $current, Audience $audience, WorkspaceBilling $billing): int
    {
        $today = CarbonImmutable::today();

        Company::query()->orderBy('id')->each(function (Company $company) use ($settlements, $years, $current, $audience, $today): void {
            $current->run($company, function () use ($company, $settlements, $years, $audience, $today): void {
                if ($company->type === CompanyType::Jv) {
                    $this->settlement($company, $settlements, $years, $audience, $today);
                }

                $this->yearEnd($company, $years, $audience, $today);
            });
        });

        Workspace::query()->with('owner')->orderBy('id')->each(function (Workspace $workspace) use ($billing, $audience, $today): void {
            $ends = $workspace->trialEndsAt();

            if ($ends === null || $billing->for($workspace)->hasSubscription()) {
                return;
            }

            $days = (int) $today->diffInDays($ends->startOfDay(), false);

            if (in_array($days, [3, 1], true)) {
                $this->send($audience->billing($workspace), TrialEnding::for($workspace, $days), null, 1);
            }
        });

        $this->info("Sent {$this->sent} reminders.");

        return self::SUCCESS;
    }

    private function settlement(Company $company, SettlementCalculator $settlements, FiscalYears $years, Audience $audience, CarbonImmutable $today): void
    {
        $year = $years->forDate($company, $today);
        $result = $settlements->calculate($company, Period::between($year->starts_on, $today));
        $threshold = (int) config('notifications.settlement_threshold_paise');

        foreach ($result->transfers as $transfer) {
            if ($transfer['amount'] < $threshold) {
                continue;
            }

            $period = $year->starts_on->format('M').' – '.$today->format('M');
            $this->send(
                $audience->withPermission($company, Catalog::pagePermission('settlements')),
                SettlementDue::for($company, $transfer['from']->name, $transfer['to']->name, $transfer['amount'], $period),
                $company->id,
                7,
            );
        }
    }

    private function yearEnd(Company $company, FiscalYears $years, Audience $audience, CarbonImmutable $today): void
    {
        $lastDay = $years->forDate($company, $today)->starts_on->subDay();

        if ($company->created_at === null || $company->created_at->greaterThan($lastDay->endOfDay())) {
            return;
        }

        $previous = $years->forDate($company, $lastDay);

        if ($previous->isClosed()) {
            return;
        }

        $this->send(
            $audience->withPermission($company, Catalog::actionPermission('fy.close')),
            FiscalYearReminder::for($company, $previous->label, $previous->ends_on->format('j M Y')),
            $company->id,
            30,
        );
    }

    /**
     * Sends to each person who hasn't had this kind of reminder for the company in `$days` days.
     *
     * @param  Collection<int, User>  $people
     */
    private function send(Collection $people, CofferNotification $notification, ?int $companyId, int $days): void
    {
        foreach ($people as $user) {
            // Remembered in the cache, so it holds whichever channels the user has on.
            $key = 'reminder:'.class_basename($notification).':'.($companyId ?? 'workspace').':'.$user->id;

            if (Cache::add($key, true, now()->addDays($days)->subMinute())) {
                $user->notify($notification);
                $this->sent++;
            }
        }
    }
}

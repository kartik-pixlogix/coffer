<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Jobs\GenerateSalaries;
use App\Models\SalaryRun;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hours changed in a month whose salary is already posted: queue a re-run for that
 * member-month (spec §8.3 "reverse and re-post"). Scoped per request, so a month batch
 * of 21 entries saved in one transaction queues one re-run, not 21.
 */
final class SalaryRecalc
{
    /** @var array<string, true> */
    private array $queued = [];

    /** @var array<string, string> "2026-11" => "November 2026": months re-run in this request (M2). */
    private array $months = [];

    /**
     * The months whose salary this request queued for a re-run, for the toast (M2, D116).
     *
     * @return list<string>
     */
    public function months(): array
    {
        return array_values($this->months);
    }

    public function hoursChanged(int $companyId, int $memberId, DateTimeInterface $date): void
    {
        $month = Carbon::instance($date)->format('Y-m');
        $key = "{$memberId}:{$month}";

        if (isset($this->queued[$key])) {
            return;
        }

        $posted = SalaryRun::query()->withoutGlobalScope('company')
            ->where('member_id', $memberId)
            ->whereDate('month', "{$month}-01")
            ->whereNotNull('journal_entry_id')
            ->exists();

        if (! $posted) {
            return;
        }

        // Inside a transaction the job waits for the commit, so later changes in it are
        // covered by this one; outside, each change re-runs (the job is unique per month).
        if (DB::transactionLevel() > 0) {
            $this->queued[$key] = true;
            DB::afterCommit(function () use ($key): void {
                unset($this->queued[$key]);
            });
        }

        $this->months[$month] = Carbon::createFromFormat('!Y-m', $month)?->format('F Y') ?? $month;

        GenerateSalaries::dispatch($companyId, $month, $memberId);
    }
}

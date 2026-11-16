<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Ledger\FiscalYears;
use App\Ledger\LedgerPoster;
use App\Ledger\Postings;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\SalaryRun;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Salary generation (spec §8.3), monthly and on "Run now". For each member and month:
 * billable hours × the rate in effect (Hourly) or the monthly cost (Fixed), posted as a
 * Salary expense on the last day of the month from the member's partner's account.
 *
 * Idempotent per member-month: an unchanged amount does nothing; a changed one reverses
 * the posted entry and posts the new amount; zero leaves nothing posted. Months in a
 * closed fiscal year are left alone.
 */
final class SalaryGenerator
{
    public function __construct(
        private readonly Earnings $earnings,
        private readonly Postings $postings,
        private readonly LedgerPoster $poster,
        private readonly FiscalYears $years,
        private readonly SalaryExpense $expenses,
    ) {}

    /** Run every member who earned, logged hours or already has a run that month. */
    public function runMonth(Company $company, CarbonImmutable $month, ?User $by = null): SalarySummary
    {
        $start = $month->startOfMonth();
        $summary = new SalarySummary;

        foreach ($this->membersFor($company, $start) as $member) {
            $summary->add($this->runMember($company, $member, $start, $by));
        }

        return $summary;
    }

    /** @return 'posted'|'updated'|'unchanged'|'removed'|'none'|'locked' */
    public function runMember(Company $company, Member $member, CarbonImmutable $month, ?User $by = null): string
    {
        $start = $month->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();

        if ($this->years->isLocked($company, $end)) {
            return 'locked';
        }

        return DB::transaction(function () use ($company, $member, $start, $end, $by): string {
            $figure = $this->earnings->forMember($member, $start);

            /** @var SalaryRun $run */
            $run = SalaryRun::query()->withoutGlobalScope('company')->lockForUpdate()->firstOrNew(
                ['member_id' => $member->id, 'month' => $start->toDateString()],
                ['company_id' => $company->id],
            );

            $posted = $run->journal_entry_id !== null;
            $same = $posted && $run->amount_paise === $figure->amountPaise;
            $run->billable_centi = $figure->billableCenti;

            if ($same || (! $posted && $figure->amountPaise === 0)) {
                if ($run->exists || $figure->amountPaise > 0) {
                    $run->save();
                    $this->expenses->sync($company, $member, $run);
                }

                return $same ? 'unchanged' : 'none';
            }

            if ($posted) {
                /** @var JournalEntry $old */
                $old = JournalEntry::query()->withoutGlobalScope('company')->findOrFail($run->journal_entry_id);
                $this->poster->reverse($old, memo: 'Salary re-run — '.$member->name.' — '.$start->format('Y-m'), by: $by);
            }

            $run->fill(['amount_paise' => $figure->amountPaise, 'run_by' => $by?->id, 'posted_at' => now(), 'journal_entry_id' => null])->save();

            if ($figure->amountPaise > 0) {
                $entry = $this->postings->salary($company, $member, $figure->amountPaise, $end, $run, $by);
                $run->update(['journal_entry_id' => $entry->id]);
            }

            // The run's read-only Auto row in Expenses (D43).
            $this->expenses->sync($company, $member, $run);

            return match (true) {
                ! $posted => 'posted',
                $figure->amountPaise === 0 => 'removed',
                default => 'updated',
            };
        });
    }

    /**
     * What a run would post, member by member (the "Run salaries" preview).
     *
     * @return list<array{member: Member, billableCenti: int, amountPaise: int, postedPaise: int|null}>
     */
    public function preview(Company $company, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $members = $this->membersFor($company, $start);
        $figures = $this->earnings->forMonth($members, $start);
        $runs = SalaryRun::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->whereDate('month', $start->toDateString())
            ->whereNotNull('journal_entry_id')
            ->pluck('amount_paise', 'member_id');

        $rows = [];

        foreach ($members as $member) {
            $rows[] = [
                'member' => $member,
                'billableCenti' => $figures[$member->id]->billableCenti,
                'amountPaise' => $figures[$member->id]->amountPaise,
                'postedPaise' => isset($runs[$member->id]) ? (int) $runs[$member->id] : null,
            ];
        }

        return $rows;
    }

    /** @return list<Member> */
    private function membersFor(Company $company, CarbonImmutable $start): array
    {
        $end = $start->endOfMonth();
        $withHours = TimeEntry::query()->withoutGlobalScope('company')->where('company_id', $company->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->distinct()->pluck('member_id');
        $withRuns = SalaryRun::query()->withoutGlobalScope('company')->where('company_id', $company->id)
            ->whereDate('month', $start->toDateString())->pluck('member_id');

        return array_values(Member::query()->withoutGlobalScope('company')->withTrashed()
            ->where('company_id', $company->id)
            ->with('rates')
            ->where(fn ($q) => $q->whereNull('deactivated_on')
                ->orWhere('deactivated_on', '>=', $start->toDateString())
                ->orWhereIn('id', $withHours->merge($withRuns)))
            ->orderBy('name')
            ->get()
            ->filter(fn (Member $m) => ! $m->trashed() || $withRuns->contains($m->id))
            ->all());
    }
}

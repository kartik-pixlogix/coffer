<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Exceptions\PeriodLockedException;
use App\Ledger\Exceptions\UnbalancedEntryException;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of journal entries (spec §4, CLAUDE.md "Ledger"). Every money event
 * posts a balanced entry here, inside a transaction; posted lines never change, and a
 * correction is a reversal plus a new entry.
 */
final class LedgerPoster
{
    public function __construct(private readonly FiscalYears $fiscalYears) {}

    public function post(Company $company, EntryData $entry): JournalEntry
    {
        // Zero lines (a 0% partner's share, an empty payer line) carry nothing: leave them out.
        $lines = array_values(array_filter($entry->lines, fn (LineData $line) => ! $line->isEmpty()));

        $this->check($company, $entry->date, $lines);

        return DB::transaction(function () use ($company, $entry, $lines): JournalEntry {
            $this->fiscalYears->forDate($company, $entry->date);

            $journal = new JournalEntry([
                'company_id' => $company->id,
                'date' => $entry->date->toDateString(),
                'type' => $entry->type,
                'memo' => $entry->memo,
                'posted_by' => $entry->postedBy?->id,
            ]);

            if ($entry->source !== null) {
                $journal->source()->associate($entry->source);
            }

            $journal->save();

            JournalLine::query()->insert(array_map(fn (LineData $line) => [
                'company_id' => $company->id,
                'journal_entry_id' => $journal->id,
                'account_id' => $line->account->id,
                'date' => $entry->date->toDateString(),
                'debit_paise' => $line->debit,
                'credit_paise' => $line->credit,
                'partner_id' => $line->partnerId,
                'side_id' => $line->sideId,
                'member_id' => $line->memberId,
                'project_id' => $line->projectId,
            ], $lines));

            return $journal;
        });
    }

    /**
     * Posts the mirror image of `$entry` and links the two. The reversal is dated like the
     * original unless a date is given; either way the date must be in an open year.
     */
    public function reverse(JournalEntry $entry, ?CarbonImmutable $date = null, ?string $memo = null, ?User $by = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $date, $memo, $by): JournalEntry {
            /** @var JournalEntry $locked */
            $locked = JournalEntry::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($entry->id);

            if ($locked->isReversed()) {
                throw new LedgerException('This entry has already been reversed.');
            }

            if ($locked->reverses_id !== null) {
                throw new LedgerException('A reversal can’t be reversed. Post a new entry instead.');
            }

            /** @var Company $company */
            $company = Company::query()->findOrFail($locked->company_id);
            $when = $date ?? $locked->date;

            // The original's own period must be open too: reversing it changes that year's figures.
            if ($closed = $this->fiscalYears->closedYearFor($company, $locked->date)) {
                throw PeriodLockedException::for($closed->label);
            }

            $lines = JournalLine::query()->withoutGlobalScope('company')
                ->with('account')
                ->where('journal_entry_id', $locked->id)
                ->get()
                ->map(fn (JournalLine $line) => new LineData(
                    $line->account,
                    $line->credit_paise,
                    $line->debit_paise,
                    $line->partner_id,
                    $line->side_id,
                    $line->member_id,
                    $line->project_id,
                ))
                ->all();
            $lines = array_values($lines);

            $reversal = $this->post($company, new EntryData(
                date: $when,
                // A reversal keeps the original's type, so it nets out of the same figures.
                type: $locked->type,
                lines: $lines,
                memo: $memo ?? 'Reversal of entry #'.$locked->id.($locked->memo ? ': '.$locked->memo : ''),
                source: $locked->source_type !== null ? $locked->source : null,
                postedBy: $by,
            ));

            $reversal->forceFill(['reverses_id' => $locked->id])->save();
            $locked->forceFill(['reversed_by_id' => $reversal->id])->save();

            return $reversal;
        });
    }

    /** @param list<LineData> $lines */
    private function check(Company $company, CarbonImmutable $date, array $lines): void
    {
        if (count($lines) < 2) {
            throw new UnbalancedEntryException('An entry needs at least two lines.');
        }

        $debits = 0;
        $credits = 0;

        foreach ($lines as $line) {
            if ($line->debit < 0 || $line->credit < 0) {
                throw new UnbalancedEntryException('Amounts can’t be negative.');
            }

            if ($line->debit > 0 && $line->credit > 0) {
                throw new UnbalancedEntryException('A line is either a debit or a credit, not both.');
            }

            if ($line->account->company_id !== $company->id) {
                throw new LedgerException('An account from another company can’t be used.');
            }

            $debits += $line->debit;
            $credits += $line->credit;
        }

        if ($debits !== $credits) {
            throw new UnbalancedEntryException("The entry doesn’t balance: debits {$debits} paise, credits {$credits} paise.");
        }

        if ($closed = $this->fiscalYears->closedYearFor($company, $date)) {
            throw PeriodLockedException::for($closed->label);
        }
    }
}

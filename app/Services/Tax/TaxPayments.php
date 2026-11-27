<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Enums\AccountRole;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Exceptions\PeriodLockedException;
use App\Ledger\LedgerPoster;
use App\Ledger\Lines;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Models\Company;
use App\Models\GstPayment;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\TdsChallan;
use App\Models\User;
use App\Services\Finance\PeriodGuard;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying the government (D163): GST for a period after set-off, and TDS by challan. Both
 * work from the tax accounts' balances at the period's end, so a payment clears everything
 * owed up to then; both post a journal entry and can be deleted (the entry is reversed).
 */
final class TaxPayments
{
    public function __construct(
        private readonly Postings $postings,
        private readonly LedgerPoster $poster,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * Balances of the tax accounts at a date, owed or available as positive amounts. Payments
     * recorded for a period up to that date count even when made after it (GST for October is
     * paid in November), so a period is never shown as owed, or paid, twice.
     *
     * @return array{output: array<string, int>, rcm: array<string, int>, credit: array<string, int>, tdsPayable: array<string, int>, tdsReceivable: int}
     */
    public function balances(Company $company, CarbonImmutable $asOf): array
    {
        $roles = array_map(fn (AccountRole $r) => $r->value, AccountRole::TAX);
        $select = 'a.role, a.tax_key, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as balance';

        $rows = Lines::in($company, Period::asOf($asOf))
            ->whereIn('a.role', $roles)
            ->selectRaw($select)
            ->groupBy('a.role', 'a.tax_key')
            ->get();

        $later = collect();

        foreach ([[GstPayment::class, 'gst_payments'], [TdsChallan::class, 'tds_challans']] as [$model, $table]) {
            $later = $later->merge(DB::table('journal_lines as l')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->join("{$table} as p", fn ($j) => $j->on('p.id', '=', 'e.source_id')->where('e.source_type', (new $model)->getMorphClass()))
                ->where('l.company_id', $company->id)
                ->whereIn('a.role', $roles)
                ->where('l.date', '>', $asOf->toDateString())
                ->where('p.period', '<=', $asOf->toDateString())
                ->selectRaw($select)
                ->groupBy('a.role', 'a.tax_key')
                ->get());
        }

        $heads = array_fill_keys(GstSetOff::HEADS, 0);
        $out = ['output' => $heads, 'rcm' => $heads, 'credit' => $heads, 'tdsPayable' => [], 'tdsReceivable' => 0];

        foreach ($rows->merge($later) as $row) {
            $balance = (int) $row->balance;
            $key = (string) $row->tax_key;

            match (AccountRole::from((string) $row->role)) {
                AccountRole::GstOutput => $out['output'][$key] = ($out['output'][$key] ?? 0) - $balance,
                AccountRole::RcmPayable => $out['rcm'][$key] = ($out['rcm'][$key] ?? 0) - $balance,
                AccountRole::GstInput => $out['credit'][$key] = ($out['credit'][$key] ?? 0) + $balance,
                AccountRole::TdsPayable => $out['tdsPayable'][$key] = ($out['tdsPayable'][$key] ?? 0) - $balance,
                AccountRole::TdsReceivable => $out['tdsReceivable'] += $balance,
                default => null,
            };
        }

        // A section paid off in full drops out.
        $out['tdsPayable'] = array_filter($out['tdsPayable'], fn (int $v) => $v !== 0);
        ksort($out['tdsPayable']);

        return $out;
    }

    /**
     * What paying GST up to a month's end would do.
     *
     * @return array{output: array<string, int>, rcm: array<string, int>, credit: array<string, int>, settle: array{used: array<string, array<string, int>>, usedByCredit: array<string, int>, cash: array<string, int>, creditLeft: array<string, int>, cashTotal: int}}
     */
    public function gstPreview(Company $company, CarbonImmutable $monthEnd): array
    {
        $b = $this->balances($company, $monthEnd);

        return ['output' => $b['output'], 'rcm' => $b['rcm'], 'credit' => $b['credit'], 'settle' => GstSetOff::settle($b['output'], $b['rcm'], $b['credit'])];
    }

    public function payGst(Company $company, CarbonImmutable $period, CarbonImmutable $paidOn, MoneyAccount $from, ?string $reference, User $by): GstPayment
    {
        $this->periods->assertOpen($company, [$paidOn], 'paid_on');

        return $this->periods->run(fn () => DB::transaction(function () use ($company, $period, $paidOn, $from, $reference, $by): GstPayment {
            $preview = $this->gstPreview($company, $period->endOfMonth()->startOfDay());
            $settle = $preview['settle'];

            if ($settle['cashTotal'] === 0 && array_sum($settle['usedByCredit']) === 0) {
                throw ValidationException::withMessages(['period' => 'No GST is owed up to the end of '.$period->format('F Y').'.']);
            }

            $payment = GstPayment::query()->create([
                'company_id' => $company->id,
                'period' => $period->startOfMonth()->toDateString(),
                'paid_on' => $paidOn->toDateString(),
                'money_account_id' => $from->id,
                'reference' => $reference,
                'breakdown' => (string) json_encode($preview),
                'cash_paise' => $settle['cashTotal'],
                'created_by' => $by->id,
            ]);

            // Output tax is cleared in full (by credit or cash); reverse-charge GST in cash.
            $entry = $this->postings->gstPayment($company, $paidOn, $preview['output'], $preview['rcm'], $settle['usedByCredit'], $settle['cashTotal'], $from,
                'GST for '.$period->format('F Y').($reference ? " ({$reference})" : ''), $payment, $by);
            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment;
        }), 'paid_on');
    }

    public function payTds(Company $company, string $section, CarbonImmutable $period, CarbonImmutable $paidOn, int $amount, MoneyAccount $from, ?string $bsr, ?string $challan, User $by): TdsChallan
    {
        $this->periods->assertOpen($company, [$paidOn], 'paid_on');

        return $this->periods->run(fn () => DB::transaction(function () use ($company, $section, $period, $paidOn, $amount, $from, $bsr, $challan, $by): TdsChallan {
            $owed = $this->balances($company, $period->endOfMonth()->startOfDay())['tdsPayable'][$section] ?? 0;

            if ($amount <= 0 || $amount > $owed) {
                throw ValidationException::withMessages(['amount_paise' => $owed > 0
                    ? 'Deposit up to the '.Money::format($owed)." of {$section} TDS owed at the end of ".$period->format('F Y').'.'
                    : "No {$section} TDS is owed up to the end of ".$period->format('F Y').'.']);
            }

            $row = TdsChallan::query()->create([
                'company_id' => $company->id,
                'section' => $section,
                'period' => $period->startOfMonth()->toDateString(),
                'amount_paise' => $amount,
                'paid_on' => $paidOn->toDateString(),
                'bsr_code' => $bsr,
                'challan_no' => $challan,
                'money_account_id' => $from->id,
                'created_by' => $by->id,
            ]);

            $entry = $this->postings->tdsChallan($company, $paidOn, $section, $amount, $from,
                "TDS {$section} for ".$period->format('F Y').($challan ? " (challan {$challan})" : ''), $row, $by);
            $row->update(['journal_entry_id' => $entry->id]);

            return $row;
        }), 'paid_on');
    }

    /** Deleting a payment reverses its entry (the books keep both). */
    public function delete(Company $company, GstPayment|TdsChallan $payment, User $by): void
    {
        $this->periods->assertOpen($company, [$payment->paid_on], 'paid_on');

        $this->periods->run(fn () => DB::transaction(function () use ($payment, $by): void {
            $entry = $payment->journal_entry_id === null ? null : JournalEntry::query()->find($payment->journal_entry_id);

            try {
                if ($entry !== null && ! $entry->isReversed()) {
                    $this->poster->reverse($entry, memo: 'Deleted — '.$payment->auditLabel(), by: $by);
                }
            } catch (PeriodLockedException $e) {
                throw $e;
            } catch (LedgerException $e) {
                throw ValidationException::withMessages(['payment' => $e->getMessage()]);
            }

            $payment->delete();
        }), 'paid_on');
    }
}

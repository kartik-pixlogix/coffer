<?php

declare(strict_types=1);

namespace App\Actions\Loans;

use App\Ledger\ChartOfAccounts;
use App\Ledger\Postings;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\User;
use App\Services\Finance\EntryReverser;
use App\Services\Finance\PeriodGuard;
use App\Services\Finance\PersonalCardBalances;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Partner loans (spec §12, §4.2):
 * - Record loan: money from partner P into M posts Dr M, Cr Loans payable P.
 * - Convert: what the company owes P for personal-card spending becomes a loan,
 *   Dr Partner current P, Cr Loans payable P (never more than is owed).
 * - Repayment to P from M posts Dr Loans payable P, Cr M; it can't exceed what is
 *   outstanding, and the loan closes on its own when that reaches ₹0.
 * Deleting a repayment reverses it and reopens the loan; a loan can be deleted
 * (reversed) only while nothing has been repaid. Editing (A6, D138) changes what it's
 * for and its terms at any time, and its amount and date only while nothing has been
 * repaid (the old entry is reversed and a new one posted). Closed fiscal years are locked.
 */
final class RecordLoan
{
    public function __construct(
        private readonly Postings $postings,
        private readonly ChartOfAccounts $chart,
        private readonly EntryReverser $reverser,
        private readonly PeriodGuard $periods,
        private readonly PersonalCardBalances $balances,
    ) {}

    public function lend(Company $company, LoanData $data, User $by): Loan
    {
        $this->periods->assertOpen($company, [$data->lentOn], 'date');

        return $this->periods->run(fn () => DB::transaction(function () use ($company, $data, $by): Loan {
            if ($data->into === null) {
                // Converting: serialise on the partner's current account, then check what is owed.
                Account::query()->whereKey($this->chart->partnerCurrent($data->partner)->id)->lockForUpdate()->first();
                $owed = $this->balances->owedTo($data->partner);

                if ($data->amountPaise > $owed) {
                    throw ValidationException::withMessages(['amount_paise' => $owed === 0
                        ? "The company doesn’t owe {$data->partner->name} anything for personal-card spending."
                        : 'That’s more than the '.Money::format($owed).' owed to '.$data->partner->name.'.']);
                }
            }

            $loan = Loan::query()->create([
                'company_id' => $company->id,
                'number' => $this->nextNumber($company),
                'partner_id' => $data->partner->id,
                'amount_paise' => $data->amountPaise,
                'lent_on' => $data->lentOn->toDateString(),
                'money_account_id' => $data->into?->id,
                'converted' => $data->into === null,
                'purpose' => $data->purpose,
                'instalment_paise' => $data->instalmentPaise,
                'due_on' => $data->dueOn?->toDateString(),
                'created_by' => $by->id,
            ]);

            $loan->update(['journal_entry_id' => $this->post($company, $loan, $data->partner, $data->into, $by)->id]);

            return $loan;
        }), 'date');
    }

    /**
     * Edit loan (A6, D138). Returns what changed as field => [old, new] for the activity log.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function edit(Company $company, Loan $loan, LoanEdit $data, User $by): array
    {
        $this->periods->assertOpen($company, [$loan->lent_on, $data->lentOn], 'date');

        return $this->periods->run(fn () => DB::transaction(function () use ($company, $loan, $data, $by): array {
            /** @var Loan $locked */
            $locked = Loan::query()->with(['partner', 'moneyAccount'])->lockForUpdate()->findOrFail($loan->id);

            $locked->fill([
                'amount_paise' => $data->amountPaise,
                'lent_on' => $data->lentOn->toDateString(),
                'purpose' => $data->purpose,
                'instalment_paise' => $data->instalmentPaise,
                'due_on' => $data->dueOn?->toDateString(),
            ]);

            $changes = [];

            foreach (array_keys($locked->getDirty()) as $field) {
                $changes[$field] = [$this->plain($locked->getOriginal($field)), $this->plain($locked->getAttribute($field))];
            }

            $repost = $locked->isDirty(['amount_paise', 'lent_on']);

            if ($repost && $locked->repayments()->exists()) {
                throw ValidationException::withMessages([$locked->isDirty('amount_paise') ? 'amount_paise' : 'date' => "{$locked->code()} has repayments, so its amount and date can’t change. Delete the repayments first."]);
            }

            if ($changes === []) {
                return [];
            }

            $locked->save();

            if ($repost) {
                $this->reverser->reverse($locked->journal_entry_id, 'Loan edited — '.$locked->code(), $by);

                if ($locked->converted) {
                    // The old conversion is undone above, so what is owed includes it again.
                    Account::query()->whereKey($this->chart->partnerCurrent($locked->partner)->id)->lockForUpdate()->first();
                    $owed = $this->balances->owedTo($locked->partner);

                    if ($locked->amount_paise > $owed) {
                        throw ValidationException::withMessages(['amount_paise' => 'That’s more than the '.Money::format($owed).' owed to '.$locked->partner->name.' for personal-card spending.']);
                    }
                }

                $locked->update(['journal_entry_id' => $this->post($company, $locked, $locked->partner, $locked->moneyAccount, $by)->id]);
            }

            $loan->setRawAttributes($locked->getAttributes(), true);

            return $changes;
        }), 'date');
    }

    public function repay(Company $company, Loan $loan, int $amount, CarbonImmutable $date, MoneyAccount $from, ?string $note, User $by): LoanRepayment
    {
        $this->periods->assertOpen($company, [$date], 'date');

        return $this->periods->run(fn () => DB::transaction(function () use ($company, $loan, $amount, $date, $from, $note, $by): LoanRepayment {
            /** @var Loan $locked */
            $locked = Loan::query()->with('partner')->lockForUpdate()->findOrFail($loan->id);
            $outstanding = $locked->amount_paise - $this->repaid($locked);

            if ($outstanding <= 0) {
                throw ValidationException::withMessages(['loan_id' => "{$locked->code()} is already repaid."]);
            }

            if ($amount > $outstanding) {
                throw ValidationException::withMessages(['amount_paise' => 'That’s more than the '.Money::format($outstanding).' outstanding.']);
            }

            if ($date->lessThan($locked->lent_on)) {
                throw ValidationException::withMessages(['date' => 'A repayment can’t be dated before the loan ('.$locked->lent_on->format('j M Y').').']);
            }

            $repayment = LoanRepayment::query()->create([
                'company_id' => $company->id,
                'loan_id' => $locked->id,
                'amount_paise' => $amount,
                'paid_on' => $date->toDateString(),
                'money_account_id' => $from->id,
                'note' => $note,
                'created_by' => $by->id,
            ]);

            $memo = 'Loan repayment — '.$locked->code().' — '.$locked->partner->name.($note !== null ? ' — '.$note : '');
            $entry = $this->postings->repayment($company, $date, $locked->partner, $amount, $from, $memo, $repayment, $by);
            $repayment->update(['journal_entry_id' => $entry->id]);

            $this->syncClosed($locked);
            $loan->setRawAttributes($locked->getAttributes(), true);

            return $repayment;
        }), 'date');
    }

    public function deleteRepayment(Company $company, LoanRepayment $repayment, User $by): void
    {
        $this->periods->assertOpen($company, [$repayment->paid_on], 'date');

        $this->periods->run(fn () => DB::transaction(function () use ($repayment, $by): void {
            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($repayment->loan_id);
            $this->reverser->reverse($repayment->journal_entry_id, 'Loan repayment deleted — '.$loan->code().' — '.Money::format($repayment->amount_paise), $by);
            $repayment->delete();
            $this->syncClosed($loan);
        }), 'date');
    }

    public function delete(Company $company, Loan $loan, User $by): void
    {
        $this->periods->assertOpen($company, [$loan->lent_on], 'date');

        $this->periods->run(fn () => DB::transaction(function () use ($loan, $by): void {
            /** @var Loan $locked */
            $locked = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            if ($locked->repayments()->exists()) {
                throw ValidationException::withMessages(['loan' => "{$locked->code()} has repayments. Delete those first."]);
            }

            $this->reverser->reverse($locked->journal_entry_id, 'Loan deleted — '.$locked->code(), $by);
            $locked->delete();
        }), 'date');
    }

    /** Live repayments so far, in paise. */
    public function repaid(Loan $loan): int
    {
        return (int) LoanRepayment::query()->where('loan_id', $loan->id)->sum('amount_paise');
    }

    /** The loan's entry: money received into an account, or a personal-card balance converted. */
    private function post(Company $company, Loan $loan, Partner $partner, ?MoneyAccount $into, User $by): JournalEntry
    {
        $memo = $loan->code().' — '.$partner->name.($loan->purpose !== null ? ' — '.$loan->purpose : '');

        return $into === null
            ? $this->postings->convertToLoan($company, $loan->lent_on, $partner, $loan->amount_paise, 'Personal-card balance converted to a loan — '.$memo, $loan, $by)
            : $this->postings->loan($company, $loan->lent_on, $partner, $loan->amount_paise, $into, 'Loan '.$memo, $loan, $by);
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : $value;
    }

    /** Closed on the last repayment's date once fully repaid; open otherwise. */
    private function syncClosed(Loan $loan): void
    {
        $closed = $loan->amount_paise - $this->repaid($loan) <= 0;
        $last = LoanRepayment::query()->where('loan_id', $loan->id)->max('paid_on');

        $loan->update(['closed_on' => $closed && $last !== null ? CarbonImmutable::parse((string) $last)->toDateString() : null]);
    }

    private function nextNumber(Company $company): int
    {
        // Two loans recorded at once can't take the same number (PostgreSQL can't lock an aggregate, so lock the company row).
        Company::query()->whereKey($company->id)->lockForUpdate()->first();
        $max = Loan::query()->withoutGlobalScope('company')->withTrashed()->where('company_id', $company->id)->max('number');

        return (int) $max + 1;
    }
}

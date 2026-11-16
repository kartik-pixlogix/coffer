<?php

declare(strict_types=1);

namespace App\Actions\Reimbursements;

use App\Ledger\ChartOfAccounts;
use App\Ledger\Postings;
use App\Models\Account;
use App\Models\Company;
use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\Finance\EntryReverser;
use App\Services\Finance\PeriodGuard;
use App\Services\Finance\PersonalCardBalances;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying back personal-card spending (spec §4.2 "Reimbursement paid out from M"):
 * Dr Partner current P (a partner) or Reimbursements payable m (a member), Cr M. Never
 * more than the company owes them. A delete reverses the payout. Closed years are locked.
 */
final class PayReimbursement
{
    public function __construct(
        private readonly Postings $postings,
        private readonly ChartOfAccounts $chart,
        private readonly EntryReverser $reverser,
        private readonly PeriodGuard $periods,
        private readonly PersonalCardBalances $balances,
    ) {}

    public function pay(Company $company, Partner|Member $payee, int $amount, CarbonImmutable $date, MoneyAccount $from, ?string $note, User $by): Reimbursement
    {
        $this->periods->assertOpen($company, [$date], 'date');

        return $this->periods->run(fn () => DB::transaction(function () use ($company, $payee, $amount, $date, $from, $note, $by): Reimbursement {
            // Serialise payouts to the same person, then check what is owed.
            $account = $payee instanceof Partner ? $this->chart->partnerCurrent($payee) : $this->chart->reimbursementsPayable($payee);
            Account::query()->whereKey($account->id)->lockForUpdate()->first();
            $owed = $this->balances->owedTo($payee);

            if ($amount > $owed) {
                throw ValidationException::withMessages(['amount_paise' => $owed === 0
                    ? "Nothing is owed to {$payee->name} for personal-card spending."
                    : 'That’s more than the '.Money::format($owed).' owed to '.$payee->name.'.']);
            }

            $payout = Reimbursement::query()->create([
                'company_id' => $company->id,
                'partner_id' => $payee instanceof Partner ? $payee->id : null,
                'member_id' => $payee instanceof Member ? $payee->id : null,
                'amount_paise' => $amount,
                'paid_on' => $date->toDateString(),
                'money_account_id' => $from->id,
                'note' => $note,
                'created_by' => $by->id,
            ]);

            $memo = 'Reimbursement — '.$payee->name.($note !== null ? ' — '.$note : '');
            $entry = $this->postings->reimbursement($company, $date, $amount, $payee, $from, $memo, $payout, $by);
            $payout->update(['journal_entry_id' => $entry->id]);

            return $payout;
        }), 'date');
    }

    public function delete(Company $company, Reimbursement $payout, User $by): void
    {
        $this->periods->assertOpen($company, [$payout->paid_on], 'date');

        $this->periods->run(fn () => DB::transaction(function () use ($payout, $by): void {
            $this->reverser->reverse($payout->journal_entry_id, 'Reimbursement deleted — '.$payout->payeeName().' — '.Money::format($payout->amount_paise), $by);
            $payout->delete();
        }), 'date');
    }
}

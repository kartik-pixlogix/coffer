<?php

declare(strict_types=1);

namespace App\Actions\Settlements;

use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Exceptions\PeriodLockedException;
use App\Ledger\Postings;
use App\Models\Company;
use App\Models\SettlementTransfer;
use App\Models\Side;
use App\Models\User;
use App\Services\Finance\EntryReverser;
use App\Services\Finance\PeriodGuard;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * JV settlement transfers (spec §13, §4.2): side A → side B posts Dr B's account, Cr A's
 * account. An edit reverses the old entry and posts a new one; a delete reverses it.
 * From and to must differ. Closed fiscal years are locked.
 */
final class RecordSettlementTransfer
{
    public function __construct(
        private readonly Postings $postings,
        private readonly EntryReverser $reverser,
        private readonly PeriodGuard $periods,
    ) {}

    public function record(Company $company, Side $from, Side $to, int $amount, CarbonImmutable $date, ?string $reference, User $by): SettlementTransfer
    {
        $this->periods->assertOpen($company, [$date], 'date');

        return $this->periods->run(fn () => DB::transaction(function () use ($company, $from, $to, $amount, $date, $reference, $by): SettlementTransfer {
            $transfer = SettlementTransfer::query()->create([
                'company_id' => $company->id,
                'from_side_id' => $from->id,
                'to_side_id' => $to->id,
                'amount_paise' => $amount,
                'transferred_on' => $date->toDateString(),
                'reference' => $reference,
                'created_by' => $by->id,
            ]);

            $transfer->update(['journal_entry_id' => $this->post($company, $transfer, $from, $to, $by)]);

            return $transfer;
        }), 'date');
    }

    public function update(Company $company, SettlementTransfer $transfer, Side $from, Side $to, int $amount, CarbonImmutable $date, ?string $reference, User $by): void
    {
        $this->periods->assertOpen($company, [$transfer->transferred_on, $date], 'date');

        $this->periods->run(fn () => DB::transaction(function () use ($company, $transfer, $from, $to, $amount, $date, $reference, $by): void {
            $transfer->fill([
                'from_side_id' => $from->id,
                'to_side_id' => $to->id,
                'amount_paise' => $amount,
                'transferred_on' => $date->toDateString(),
                'reference' => $reference,
            ]);

            $repost = $transfer->isDirty(['from_side_id', 'to_side_id', 'amount_paise', 'transferred_on']) || $transfer->journal_entry_id === null;
            $transfer->save();

            if ($repost) {
                $this->reverser->reverse($transfer->journal_entry_id, 'Settlement transfer edited — '.$from->name.' → '.$to->name, $by);
                $transfer->update(['journal_entry_id' => $this->post($company, $transfer, $from, $to, $by)]);
            }
        }), 'date');
    }

    public function delete(Company $company, SettlementTransfer $transfer, User $by): void
    {
        $this->periods->assertOpen($company, [$transfer->transferred_on], 'date');

        $this->periods->run(fn () => DB::transaction(function () use ($transfer, $by): void {
            $this->reverser->reverse($transfer->journal_entry_id, 'Settlement transfer deleted — '.Money::format($transfer->amount_paise), $by);
            $transfer->delete();
        }), 'date');
    }

    private function post(Company $company, SettlementTransfer $transfer, Side $from, Side $to, User $by): int
    {
        try {
            return $this->postings->settlementTransfer($company, $transfer->transferred_on, $from, $to, $transfer->amount_paise, $transfer->reference, $transfer, $by)->id;
        } catch (PeriodLockedException $e) {
            throw $e;
        } catch (LedgerException $e) {
            throw ValidationException::withMessages(['to_side_id' => $e->getMessage()]);
        }
    }
}

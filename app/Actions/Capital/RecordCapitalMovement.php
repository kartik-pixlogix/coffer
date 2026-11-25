<?php

declare(strict_types=1);

namespace App\Actions\Capital;

use App\Enums\CapitalMovementKind;
use App\Ledger\Postings;
use App\Models\CapitalMovement;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\User;
use App\Services\Finance\EntryReverser;
use App\Services\Finance\PeriodGuard;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Capital contributions and drawings (spec §11, §4.2). A contribution by P into M posts
 * Dr M, Cr Capital P; a drawing by P from M posts Dr Capital P, Cr M. A delete reverses
 * the entry and soft-deletes the record. Closed fiscal years are locked.
 */
final class RecordCapitalMovement
{
    public function __construct(
        private readonly Postings $postings,
        private readonly EntryReverser $reverser,
        private readonly PeriodGuard $periods,
    ) {}

    public function record(Company $company, CapitalMovementKind $kind, Partner $partner, int $amount, CarbonImmutable $date, MoneyAccount $account, ?string $note, User $by): CapitalMovement
    {
        $this->periods->assertOpen($company, [$date], 'date');

        return $this->periods->run(fn () => DB::transaction(function () use ($company, $kind, $partner, $amount, $date, $account, $note, $by): CapitalMovement {
            /** @var CapitalMovement $movement */
            $movement = CapitalMovement::query()->create([
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'kind' => $kind,
                'amount_paise' => $amount,
                'moved_on' => $date->toDateString(),
                'money_account_id' => $account->id,
                'note' => $note,
                'created_by' => $by->id,
            ]);

            $memo = ($kind === CapitalMovementKind::Contribution ? 'Capital contribution — ' : 'Drawing — ').$partner->name;
            $entry = $kind === CapitalMovementKind::Contribution
                ? $this->postings->contribution($company, $date, $partner, $amount, $account, $memo, $movement, $by)
                : $this->postings->drawing($company, $date, $partner, $amount, $account, $memo, $movement, $by);

            $movement->update(['journal_entry_id' => $entry->id]);

            return $movement;
        }), 'date');
    }

    public function delete(Company $company, CapitalMovement $movement, User $by): void
    {
        $this->periods->assertOpen($company, [$movement->moved_on], 'date');

        $this->periods->run(fn () => DB::transaction(function () use ($movement, $by): void {
            $what = $movement->kind === CapitalMovementKind::Contribution ? 'Contribution' : 'Drawing';
            $this->reverser->reverse($movement->journal_entry_id, "{$what} deleted — ".Money::format($movement->amount_paise), $by);
            $movement->delete();
        }), 'date');
    }
}

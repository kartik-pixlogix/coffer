<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\JournalType;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Voucher numbers (D154): `INC/2026-27/0001`, one gapless series per company, fiscal year
 * and entry type. A number is taken inside the posting's own transaction with the series
 * row locked, so two postings can't take the same number and a posting that rolls back
 * gives its number back. Series are keyed by the year's label, not its row, so moving the
 * fiscal year start (allowed while no year is closed) never collides with numbers issued.
 */
final class Vouchers
{
    /** @return array{no: string, seq: int} */
    public function next(Company $company, string $fyLabel, JournalType $type): array
    {
        $key = ['company_id' => $company->id, 'fy_label' => $fyLabel, 'type' => $type->value];

        $locked = fn (): mixed => DB::table('voucher_sequences')->where($key)->lockForUpdate()->value('last');

        // Lock first and insert only for a new series (see AuditTrail::lock for why).
        $last = $locked();

        if ($last === null) {
            DB::table('voucher_sequences')->insertOrIgnore([...$key, 'last' => 0]);
            $last = $locked();
        }

        $last = (int) $last;
        $seq = $last + 1;
        DB::table('voucher_sequences')->where($key)->update(['last' => $seq]);

        return ['no' => self::format($type, $fyLabel, $seq), 'seq' => $seq];
    }

    /**
     * Numbers a company's entries that have no voucher yet, in posting order, continuing each
     * series (the migration that added vouchers). Returns how many it numbered.
     */
    public function backfill(Company $company): int
    {
        $numbered = 0;

        foreach (DB::table('journal_entries')->where('company_id', $company->id)->whereNull('voucher_no')->lazyById(500, 'id') as $entry) {
            $label = FiscalYears::label($company, FiscalYears::startOf($company, CarbonImmutable::parse((string) $entry->date)));
            $voucher = $this->next($company, $label, JournalType::from((string) $entry->type));

            DB::table('journal_entries')->where('id', $entry->id)->update(['voucher_no' => $voucher['no'], 'voucher_seq' => $voucher['seq']]);
            $numbered++;
        }

        return $numbered;
    }

    /** "INC/2026-27/0001" from "FY 2026-27". */
    public static function format(JournalType $type, string $fyLabel, int $seq): string
    {
        return $type->voucherCode().'/'.trim(str_replace('FY', '', $fyLabel)).'/'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}

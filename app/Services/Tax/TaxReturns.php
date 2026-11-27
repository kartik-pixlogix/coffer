<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Enums\ExpenseStatus;
use App\Enums\GstSupply;
use App\Models\Company;
use App\Models\DocumentTax;
use App\Models\Expense;
use App\Models\GstPayment;
use App\Models\Income;
use App\Models\SalaryRun;
use App\Models\TdsChallan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The monthly tax summaries a CA files from (D164): GSTR-1 (outward supplies), GSTR-3B (the
 * summary return with input credit and payment) and TDS (deducted, deposited, owed; and TDS
 * clients deducted from us). Built from `document_taxes` beside each income and approved
 * expense, salary runs, GST payments and TDS challans, so they agree with the tax accounts:
 * the tie tests check it. Figures are for review and filing by hand or by the CA; they are
 * not a GSTN upload file. [VERIFY] table layout with the CA.
 */
final class TaxReturns
{
    /**
     * @return array{b2b: list<array<string, mixed>>, b2c: list<array<string, mixed>>, exports: list<array<string, mixed>>, hsn: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function gstr1(Company $company, CarbonImmutable $month): array
    {
        $rows = $this->outward($company, $month);
        $b2b = [];
        $b2c = [];
        $exports = [];
        $hsn = [];

        foreach ($rows as [$tax, $income]) {
            $line = [
                'date' => ($tax->invoice_date ?? $income->received_on)->toDateString(),
                'invoiceNo' => $tax->invoice_no,
                'party' => $income->source,
                'gstin' => $tax->party_gstin,
                'state' => $tax->party_state,
                'stateName' => GstStates::name($tax->party_state),
                'ratePct' => $tax->rate_bps / 100,
                'taxable' => $tax->taxable_paise,
                'igst' => $tax->igst_paise,
                'cgst' => $tax->cgst_paise,
                'sgst' => $tax->sgst_paise,
            ];

            if ($tax->supply === GstSupply::Export) {
                $exports[] = $line;
            } elseif ($tax->party_gstin !== null) {
                $b2b[] = $line;
            } else {
                $key = ($tax->party_state ?? '').'|'.$tax->rate_bps;
                $b2c[$key] ??= ['state' => $tax->party_state, 'stateName' => GstStates::name($tax->party_state), 'ratePct' => $tax->rate_bps / 100, 'taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0];
                foreach (['taxable', 'igst', 'cgst', 'sgst'] as $k) {
                    $b2c[$key][$k] += $line[$k];
                }
            }

            $code = $tax->hsn_sac ?? '—';
            $hsn[$code] ??= ['code' => $code, 'description' => TaxOptions::CODES[$code] ?? null, 'documents' => 0, 'taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0];
            $hsn[$code]['documents']++;
            foreach (['taxable', 'igst', 'cgst', 'sgst'] as $k) {
                $hsn[$code][$k] += $line[$k];
            }
        }

        ksort($b2c);
        ksort($hsn);

        return [
            'b2b' => $b2b,
            'b2c' => array_values($b2c),
            'exports' => $exports,
            'hsn' => array_values($hsn),
            'totals' => $this->sum(array_merge($b2b, array_values($b2c), $exports)),
        ];
    }

    /** @return array<string, mixed> */
    public function gstr3b(Company $company, CarbonImmutable $month): array
    {
        $outward = $this->outward($company, $month)->map(fn (array $pair) => $pair[0]);
        $inward = $this->inward($company, $month);

        $taxable = $outward->filter(fn (DocumentTax $t) => $t->supply !== GstSupply::Export);
        $zero = $outward->filter(fn (DocumentTax $t) => $t->supply === GstSupply::Export);
        $rcm = $inward->filter(fn (DocumentTax $t) => $t->reverse_charge);
        $import = $rcm->filter(fn (DocumentTax $t) => $t->itc && $t->supply === GstSupply::Import);
        $otherItc = $inward->filter(fn (DocumentTax $t) => $t->itc && ! $t->reverse_charge);
        $ineligible = $inward->filter(fn (DocumentTax $t) => ! $t->itc && $t->gstPaise() > 0);

        $payment = GstPayment::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('period', $month->startOfMonth()->toDateString())->latest('id')->first();

        $itcAvailable = $this->figures($import->merge($otherItc));

        return [
            'outwardTaxable' => $this->figures($taxable),
            'zeroRated' => $this->figures($zero),
            'inwardRcm' => $this->figures($rcm),
            'itcImport' => $this->figures($import),
            'itcRcmOther' => $this->figures($rcm->filter(fn (DocumentTax $t) => $t->itc && $t->supply !== GstSupply::Import)),
            'itcOther' => $this->figures($otherItc),
            'itcIneligible' => $this->figures($ineligible),
            'itcNet' => $itcAvailable,
            'payment' => $payment === null ? null : [
                'paidOn' => $payment->paid_on->toDateString(),
                'reference' => $payment->reference,
                'cashPaise' => $payment->cash_paise,
                'working' => $payment->working(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function tds(Company $company, CarbonImmutable $month, TaxPayments $payments): array
    {
        $start = $month->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $sections = [];
        $deductees = [];

        foreach ($this->inward($company, $month, withExpense: true) as [$tax, $expense]) {
            if ($tax->tds_section === null || $tax->tds_paise === 0) {
                continue;
            }

            $code = $tax->tds_section->code();
            $sections[$code] ??= ['section' => $code, 'deducted' => 0, 'deposited' => 0];
            $sections[$code]['deducted'] += $tax->tds_paise;
            $deductees[] = [
                'date' => $expense->spent_on->toDateString(),
                'party' => $expense->description,
                'pan' => self::maskPan($tax->deductee_pan),
                'section' => $tax->tds_section->label(),
                'taxable' => $tax->taxable_paise,
                'tds' => $tax->tds_paise,
            ];
        }

        $salaryTds = (int) SalaryRun::query()->withoutGlobalScopes()->where('company_id', $company->id)
            ->where('month', $start->toDateString())->whereNotNull('journal_entry_id')->sum('tds_paise');

        // Bills never use 192, so salary TDS starts its own row.
        if ($salaryTds > 0) {
            $sections['192'] = ['section' => '192', 'deducted' => $salaryTds, 'deposited' => 0];
        }

        foreach (TdsChallan::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('period', $start->toDateString())->get() as $challan) {
            $sections[$challan->section] ??= ['section' => $challan->section, 'deducted' => 0, 'deposited' => 0];
            $sections[$challan->section]['deposited'] += $challan->amount_paise;
        }

        $owed = $payments->balances($company, $end)['tdsPayable'];

        foreach (array_keys($owed) as $code) {
            $sections[$code] ??= ['section' => $code, 'deducted' => 0, 'deposited' => 0];
        }

        foreach ($sections as $code => $row) {
            $sections[$code]['owed'] = $owed[$code] ?? 0;
        }

        ksort($sections);

        $receivable = $this->outward($company, $month)
            ->filter(fn (array $pair) => $pair[0]->tds_paise > 0)
            ->map(fn (array $pair) => [
                'date' => $pair[1]->received_on->toDateString(),
                'party' => $pair[1]->source,
                'section' => $pair[0]->tds_section?->label(),
                'taxable' => $pair[0]->taxable_paise,
                'tds' => $pair[0]->tds_paise,
            ])->values()->all();

        return [
            'sections' => array_values(array_filter($sections, fn (array $r) => $r['deducted'] !== 0 || $r['deposited'] !== 0 || $r['owed'] !== 0)),
            'deductees' => $deductees,
            'receivable' => $receivable,
            'challans' => TdsChallan::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('period', $start->toDateString())
                ->orderBy('paid_on')->get()->map(fn (TdsChallan $c) => [
                    'id' => $c->id, 'section' => $c->section, 'amount' => $c->amount_paise, 'paidOn' => $c->paid_on->toDateString(),
                    'bsr' => $c->bsr_code, 'challan' => $c->challan_no,
                ])->all(),
        ];
    }

    public static function maskPan(?string $pan): ?string
    {
        return $pan === null ? null : substr($pan, 0, 5).'****'.substr($pan, -1);
    }

    /** @return Collection<int, array{0: DocumentTax, 1: Income}> outward supplies in the month, by invoice date (or date received) */
    private function outward(Company $company, CarbonImmutable $month): Collection
    {
        $start = $month->startOfMonth();
        $end = $start->endOfMonth();
        $incomes = Income::query()->withoutGlobalScope('company')->where('company_id', $company->id)
            ->with('tax')->whereHas('tax')
            ->whereBetween('received_on', [$start->subMonths(3)->toDateString(), $end->addMonths(3)->toDateString()])
            ->orderBy('received_on')->orderBy('id')->get();

        return $incomes
            ->filter(function (Income $i) use ($start, $end): bool {
                $date = $i->tax->invoice_date ?? $i->received_on;

                return $date->betweenIncluded($start, $end);
            })
            ->map(fn (Income $i) => [$i->tax ?? throw new LogicException('tax'), $i])
            ->values();
    }

    /**
     * Approved expenses with tax, booked in the month.
     *
     * @return ($withExpense is true ? Collection<int, array{0: DocumentTax, 1: Expense}> : Collection<int, DocumentTax>)
     */
    private function inward(Company $company, CarbonImmutable $month, bool $withExpense = false): Collection
    {
        $start = $month->startOfMonth();
        $expenses = Expense::query()->withoutGlobalScope('company')->where('company_id', $company->id)
            ->where('status', ExpenseStatus::Approved->value)->whereNotNull('journal_entry_id')
            ->whereBetween('spent_on', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->with('tax')->whereHas('tax')->orderBy('spent_on')->orderBy('id')->get();

        return $withExpense
            ? $expenses->map(fn (Expense $e) => [$e->tax ?? throw new LogicException('tax'), $e])->values()
            : $expenses->map(fn (Expense $e) => $e->tax ?? throw new LogicException('tax'))->values();
    }

    /**
     * @param  Collection<int, DocumentTax>  $taxes
     * @return array{taxable: int, igst: int, cgst: int, sgst: int}
     */
    private function figures(Collection $taxes): array
    {
        return [
            'taxable' => (int) $taxes->sum('taxable_paise'),
            'igst' => (int) $taxes->sum('igst_paise'),
            'cgst' => (int) $taxes->sum('cgst_paise'),
            'sgst' => (int) $taxes->sum('sgst_paise'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function sum(array $rows): array
    {
        $totals = ['taxable' => 0, 'igst' => 0, 'cgst' => 0, 'sgst' => 0];

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $k) {
                $totals[$k] += (int) $row[$k];
            }
        }

        return $totals;
    }
}

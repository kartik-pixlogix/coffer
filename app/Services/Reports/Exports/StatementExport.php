<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use App\Ledger\CachedReports;
use App\Ledger\FiscalYears;
use App\Models\Company;
use App\Services\Reports\StatementDate;

/**
 * Trial balance and Balance sheet exports (Statements.dc.html "Export", spec §15) as PDF
 * or Excel, from the same cached aggregates as the pages. Always small, so never queued.
 */
final class StatementExport
{
    public function __construct(
        private readonly CachedReports $reports,
        private readonly Pdf $pdf,
        private readonly Xlsx $xlsx,
    ) {}

    public function pdf(Company $company, StatementDate $date, string $statement): string
    {
        $meta = 'As of '.$date->asOf->format('j M Y').' · '.FiscalYears::label($company, $date->fyStart());

        if ($statement === 'trial-balance') {
            return $this->pdf->render('exports.trial-balance', [
                'trial' => $this->reports->trialBalance($company, $date->fyStart(), $date->asOf),
                'company' => $company->name,
                'logo' => Pdf::logo($company),
                'kicker' => 'Statements',
                'title' => 'Trial balance',
                'meta' => $meta,
            ]);
        }

        return $this->pdf->render('exports.balance-sheet', [
            'sheet' => $this->reports->balanceSheet($company, $date->asOf),
            'company' => $company->name,
            'logo' => Pdf::logo($company),
            'kicker' => 'Statements',
            'title' => 'Balance sheet',
            'meta' => $meta,
        ]);
    }

    /** @return string path of a temp .xlsx the caller removes */
    public function xlsx(Company $company, StatementDate $date, string $statement): string
    {
        $m = Xlsx::money(...);

        if ($statement === 'trial-balance') {
            $trial = $this->reports->trialBalance($company, $date->fyStart(), $date->asOf);

            return $this->xlsx->write([[
                'name' => 'Trial balance',
                'header' => ['Code', 'Account', 'Type', 'Debit', 'Credit'],
                'rows' => [
                    ...array_map(fn (array $a) => [$a['code'], $a['name'], ucfirst($a['type']), $m($a['debit']), $m($a['credit'])], $trial['accounts']),
                    [null, 'Total', null, $m($trial['debit']), $m($trial['credit'])],
                ],
                'totalLast' => true,
            ]]);
        }

        $sheet = $this->reports->balanceSheet($company, $date->asOf);
        $rows = [];

        foreach (['Liabilities' => $sheet['liabilities'], 'Equity' => $sheet['equity'], 'Assets' => $sheet['assets']] as $section => $lines) {
            foreach ($lines as $line) {
                $rows[] = [$section, $line['name'], $m($line['amount'])];
            }
        }

        $rows[] = ['Liabilities', 'Total liabilities', $m($sheet['totalLiabilities'])];
        $rows[] = ['Equity', 'Total equity', $m($sheet['totalEquity'])];
        $rows[] = ['Liabilities & equity', 'Total liabilities & equity', $m($sheet['totalLiabilities'] + $sheet['totalEquity'])];
        $rows[] = ['Assets', 'Total assets', $m($sheet['totalAssets'])];

        return $this->xlsx->write([[
            'name' => 'Balance sheet',
            'header' => ['Section', 'Line', 'Amount'],
            'rows' => $rows,
        ]]);
    }
}

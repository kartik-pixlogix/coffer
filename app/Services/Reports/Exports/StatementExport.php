<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use App\Ledger\CachedReports;
use App\Ledger\FiscalYears;
use App\Ledger\ScheduleThree;
use App\Models\Company;
use App\Services\Reports\StatementDate;

/**
 * Trial balance and Balance sheet exports (Statements.dc.html "Export", spec §15) as PDF
 * or Excel, from the same cached aggregates as the pages: the trial balance in four columns
 * over the period (D155), the balance sheet standard or in the Schedule III layout (D156).
 * Always small, so never queued.
 */
final class StatementExport
{
    public function __construct(
        private readonly CachedReports $reports,
        private readonly ScheduleThree $schedule,
        private readonly Pdf $pdf,
        private readonly Xlsx $xlsx,
    ) {}

    public function pdf(Company $company, StatementDate $date, string $statement, bool $scheduleThree = false): string
    {
        $year = FiscalYears::label($company, $date->fyStart());

        if ($statement === 'trial-balance') {
            return $this->pdf->render('exports.trial-balance', [
                'trial' => $this->reports->trialBalanceColumns($company, $date->fyStart(), $date->from, $date->asOf),
                'company' => $company->name,
                'logo' => Pdf::logo($company),
                'kicker' => 'Statements',
                'title' => 'Trial balance',
                'meta' => $date->from->format('j M Y').' – '.$date->asOf->format('j M Y').' · '.$year,
            ], 'landscape');
        }

        if ($scheduleThree) {
            return $this->pdf->render('exports.schedule-bs', [
                'sheet' => $this->schedule->balanceSheet($company, $date->asOf, $date->fyStart()),
                'company' => $company->name,
                'logo' => Pdf::logo($company),
                'kicker' => 'Statements · Schedule III',
                'title' => 'Balance sheet',
                'meta' => 'As at '.$date->asOf->format('j M Y').' · '.$year,
            ]);
        }

        return $this->pdf->render('exports.balance-sheet', [
            'sheet' => $this->reports->balanceSheet($company, $date->asOf),
            'company' => $company->name,
            'logo' => Pdf::logo($company),
            'kicker' => 'Statements',
            'title' => 'Balance sheet',
            'meta' => 'As of '.$date->asOf->format('j M Y').' · '.$year,
        ]);
    }

    /** @return string path of a temp .xlsx the caller removes */
    public function xlsx(Company $company, StatementDate $date, string $statement, bool $scheduleThree = false): string
    {
        $m = Xlsx::money(...);

        if ($statement === 'trial-balance') {
            $trial = $this->reports->trialBalanceColumns($company, $date->fyStart(), $date->from, $date->asOf);

            return $this->xlsx->write([[
                'name' => 'Trial balance',
                'header' => ['Code', 'Account', 'Type', 'Opening', 'Debit', 'Credit', 'Closing'],
                'rows' => [
                    ...array_map(fn (array $a) => [$a['code'], $a['name'], ucfirst($a['type']), $m($a['opening']), $m($a['debit']), $m($a['credit']), $m($a['closing'])], $trial['accounts']),
                    [null, 'Total debits', null, $m($trial['openingDebit']), $m($trial['debit']), null, $m($trial['closingDebit'])],
                    [null, 'Total credits', null, $m($trial['openingCredit']), null, $m($trial['credit']), $m($trial['closingCredit'])],
                ],
                'totalLast' => true,
            ]]);
        }

        if ($scheduleThree) {
            $sheet = $this->schedule->balanceSheet($company, $date->asOf, $date->fyStart());
            $withPrevious = $sheet['previousAsOf'] !== null;
            $rows = [];

            foreach ($sheet['sections'] as $section) {
                foreach ($section['groups'] as $group) {
                    foreach ($group['lines'] as $line) {
                        $rows[] = [$section['title'], $group['title'], $line['label'], $line['note'], $m($line['amount']), ...($withPrevious ? [$line['previous'] === null ? null : $m($line['previous'])] : [])];
                    }
                }

                $rows[] = [$section['title'], null, 'Total', null, $m($section['total']), ...($withPrevious ? [$section['previousTotal'] === null ? null : $m($section['previousTotal'])] : [])];
            }

            return $this->xlsx->write([
                [
                    'name' => 'Balance sheet',
                    'header' => ['Section', 'Group', 'Line', 'Note', 'As at '.$sheet['asOf'], ...($withPrevious ? ['As at '.$sheet['previousAsOf']] : [])],
                    'rows' => $rows,
                ],
                [
                    'name' => 'Notes',
                    'header' => ['Note', 'Code', 'Account', 'This year', ...($withPrevious ? ['Previous year'] : [])],
                    'rows' => Xlsx::noteRows($sheet['notes'], $withPrevious),
                ],
            ]);
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

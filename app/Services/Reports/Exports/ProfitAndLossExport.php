<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use App\Models\Company;
use App\Services\Reports\ProfitAndLossReport;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * The Reports export (spec §15): the P&L for a period as a PDF report or an Excel
 * workbook. Both carry the KPIs and ratios, the monthly table and partner P&L.
 */
final class ProfitAndLossExport
{
    public function __construct(
        private readonly ProfitAndLossReport $report,
        private readonly Pdf $pdf,
        private readonly Xlsx $xlsx,
    ) {}

    /** "vertex-labs-pvt-ltd-pnl-2026-04-01-to-2026-11-15.pdf" */
    public static function filename(Company $company, ReportPeriod $period, string $format): string
    {
        return str($company->name)->slug().'-pnl-'.($period->period->from ?? $period->fyStart())->toDateString().'-to-'.$period->period->to->toDateString().'.'.$format;
    }

    public function pdf(Company $company, ReportPeriod $period): string
    {
        $report = $this->report->build($company, $period);

        return $this->pdf->render('exports.profit-and-loss', [
            'report' => $report,
            'company' => $company->name,
            'logo' => Pdf::logo($company),
            'kicker' => 'Reports',
            'title' => 'Profit & loss',
            'meta' => $report['range'].' · '.$report['fyLabel'],
            'note' => $this->note($company),
        ], 'landscape');
    }

    /** @return string path of a temp .xlsx the caller removes */
    public function xlsx(Company $company, ReportPeriod $period): string
    {
        $r = $this->report->build($company, $period);
        $k = $r['kpis'];
        $m = Xlsx::money(...);
        $p = Xlsx::percent(...);

        return $this->xlsx->write([
            [
                'name' => 'Summary',
                'header' => ['Profit & loss', $company->name],
                'rows' => [
                    ['Period', $r['range']],
                    ['Fiscal year', $r['fyLabel']],
                    ['Months', $k['months']],
                    ['Revenue', $m($k['income'])],
                    ['Expenses', $m($k['expenses'])],
                    ['Salaries', $m($k['salary'])],
                    ['Other expenses', $m($k['other'])],
                    ['Net profit', $m($k['net'])],
                    ['Profit margin', $p($k['marginBps'])],
                    ['Expense ratio', $p($k['expenseRatioBps'])],
                    ['Avg monthly revenue', $m($k['avgIncome'])],
                    ['Avg monthly expense', $m($k['avgExpenses'])],
                ],
            ],
            [
                'name' => 'Monthly',
                'header' => ['Month', 'Revenue', 'Expenses', 'Other', 'Salaries', 'Profit', 'Margin'],
                'rows' => [
                    ...array_map(fn (array $row) => [
                        CarbonImmutable::createFromFormat('!Y-m', $row['month'])?->format('F Y') ?? $row['month'],
                        $m($row['income']), $m($row['expenses']), $m($row['other']), $m($row['salary']), $m($row['net']), $p($row['marginBps']),
                    ], $r['months']),
                    ['Total', $m($r['totals']['income']), $m($r['totals']['expenses']), $m($r['totals']['other']), $m($r['totals']['salary']), $m($r['totals']['net']), $p($r['totals']['marginBps'])],
                ],
                'totalLast' => true,
            ],
            [
                'name' => 'Partners',
                'header' => ['Partner', 'Side', 'Equity', 'Revenue generated', 'Expenses', 'Other', 'Net contribution', 'Profit due', 'Revenue share'],
                'rows' => [
                    ...array_map(fn (array $row) => [
                        $row['name'], $row['side'], $p($row['equityBps']), $m($row['revenue']), $m($row['expenses']), $m($row['other']), $m($row['net']), $m($row['profitDue']), $p($row['revenueShareBps']),
                    ], $r['partners']),
                    ['Total', null, $p($r['partnerTotals']['equityBps']), $m($r['partnerTotals']['revenue']), $m($r['partnerTotals']['expenses']), $m($r['partnerTotals']['other']), $m($r['partnerTotals']['net']), $m($r['partnerTotals']['profitDue']), $p($r['totals']['income'] === 0 ? null : 10000)],
                ],
                'totalLast' => true,
            ],
        ]);
    }

    private function note(Company $company): string
    {
        return ProfitAndLossReport::pooled($company)
            ? 'Net contribution is revenue a partner brought in, less the expenses and salaries booked to them and their share of shared costs. Profit due is net profit × equity (JV pooled).'
            : 'Net contribution is revenue a partner brought in, less the expenses and salaries booked to them and their share of shared costs. Profit due is their attributed result plus their equity share of everything unattributed (spec §4.4).';
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ledger\CachedReports;
use App\Ledger\DriftFinder;
use App\Ledger\Period;
use App\Ledger\ReportCache;
use App\Ledger\ScheduleThree;
use App\Models\User;
use App\Services\Reports\Exports\StatementExport;
use App\Services\Reports\StatementDate;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trial balance and Balance sheet (Statements.dc.html, spec §15): the trial balance over a
 * period in four columns (D155), the balance sheet as of a date, standard or in the Schedule
 * III layout (D156); with the status banner, "Find the drift" diagnostics (loaded on demand,
 * D60), Recheck (recomputes past the cache) and PDF / Excel export.
 */
final class StatementsController extends Controller
{
    public function trialBalance(Request $request, CurrentCompany $current, CachedReports $reports, DriftFinder $drift, ReportCache $cache): Response
    {
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());

        if ($request->boolean('recheck')) {
            $cache->flush($company);
        }

        $trial = $reports->trialBalanceColumns($company, $date->fyStart(), $date->from, $date->asOf);

        return Inertia::render('reports/trial-balance', [
            'company' => ['name' => $company->name],
            'date' => $date->props(),
            'query' => $date->query(),
            'trial' => [
                'accounts' => array_map(fn (array $a) => [
                    'id' => $a['id'],
                    'code' => $a['code'],
                    'name' => $a['name'],
                    'type' => $a['type'],
                    'openingPaise' => $a['opening'],
                    'debitPaise' => $a['debit'],
                    'creditPaise' => $a['credit'],
                    'closingPaise' => $a['closing'],
                ], $trial['accounts']),
                'openingDebitPaise' => $trial['openingDebit'],
                'openingCreditPaise' => $trial['openingCredit'],
                'debitPaise' => $trial['debit'],
                'creditPaise' => $trial['credit'],
                'closingDebitPaise' => $trial['closingDebit'],
                'closingCreditPaise' => $trial['closingCredit'],
                // The first pair that disagrees: closing, then the period, then opening.
                'driftPaise' => $trial['closingDrift'] ?: ($trial['movementDrift'] ?: $trial['openingDrift']),
            ],
            'checkedAt' => now()->toIso8601String(),
            'drift' => Inertia::optional(fn () => $drift->find($company, $date->asOf)),
        ]);
    }

    public function balanceSheet(Request $request, CurrentCompany $current, CachedReports $reports, DriftFinder $drift, ReportCache $cache, ScheduleThree $schedule): Response
    {
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());
        $layout = $request->query('layout') === 'schedule3' ? 'schedule3' : 'standard';

        if ($request->boolean('recheck')) {
            $cache->flush($company);
        }

        $sheet = $reports->balanceSheet($company, $date->asOf);
        $capital = $reports->capitalStatement($company, Period::between($date->fyStart(), $date->asOf));
        $capitalClosing = array_sum(array_column($capital, 'closing'));

        return Inertia::render('reports/balance-sheet', [
            'company' => ['name' => $company->name],
            'date' => $date->props(),
            'query' => $date->query(),
            'sheet' => [
                'assets' => $this->lines($sheet['assets']),
                'liabilities' => $this->lines($sheet['liabilities']),
                'equity' => $this->lines($sheet['equity']),
                'totalAssetsPaise' => $sheet['totalAssets'],
                'totalLiabilitiesPaise' => $sheet['totalLiabilities'],
                'totalEquityPaise' => $sheet['totalEquity'],
                'currentYearProfitPaise' => $sheet['currentYearProfit'],
                'driftPaise' => $sheet['drift'],
            ],
            'capitalClosingPaise' => $capitalClosing,
            'layout' => $layout,
            'schedule' => $layout === 'schedule3'
                ? $cache->remember($company, 'schedule3-bs', [$date->asOf->toDateString()], fn () => $schedule->balanceSheet($company, $date->asOf, $date->fyStart()))
                : null,
            'checkedAt' => now()->toIso8601String(),
            'drift' => Inertia::optional(fn () => $drift->find($company, $date->asOf)),
        ]);
    }

    public function exportTrialBalance(Request $request, CurrentCompany $current, StatementExport $exporter): StreamedResponse|BinaryFileResponse
    {
        return $this->export($request, $current, $exporter, 'trial-balance');
    }

    public function exportBalanceSheet(Request $request, CurrentCompany $current, StatementExport $exporter): StreamedResponse|BinaryFileResponse
    {
        return $this->export($request, $current, $exporter, 'balance-sheet');
    }

    private function export(Request $request, CurrentCompany $current, StatementExport $exporter, string $statement): StreamedResponse|BinaryFileResponse
    {
        $format = $request->validate(['format' => ['required', 'in:pdf,xlsx']])['format'];
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());
        /** @var User $user */
        $user = $request->user();
        $scheduleThree = $statement === 'balance-sheet' && $request->query('layout') === 'schedule3';
        $filename = str($company->name)->slug().'-'.$statement.($scheduleThree ? '-schedule-iii' : '').'-'.$date->asOf->toDateString().'.'.$format;
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private'];

        activity('reports')->causedBy($user)->withProperties(['format' => $format, 'statement' => $statement, 'as_of' => $date->asOf->toDateString()])->log('exported the '.str_replace('-', ' ', $statement));

        if ($format === 'pdf') {
            $bytes = $exporter->pdf($company, $date, $statement, $scheduleThree);

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $filename, ['Content-Type' => 'application/pdf', ...$headers]);
        }

        return response()->download($exporter->xlsx($company, $date, $statement, $scheduleThree), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ...$headers,
        ])->deleteFileAfterSend();
    }

    /**
     * @param  list<array{name: string, amount: int}>  $lines
     * @return list<array{name: string, amountPaise: int}>
     */
    private function lines(array $lines): array
    {
        return array_map(fn (array $l) => ['name' => $l['name'], 'amountPaise' => $l['amount']], $lines);
    }
}

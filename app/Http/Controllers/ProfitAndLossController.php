<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ledger\Period;
use App\Ledger\ReportCache;
use App\Ledger\Reports;
use App\Ledger\ScheduleThree;
use App\Services\Reports\Exports\Pdf;
use App\Services\Reports\Exports\Xlsx;
use App\Services\Reports\StatementDate;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Statement of profit and loss in the Schedule III format (D156), for a period inside a
 * fiscal year, with its notes, a previous-year column and a check that it ties to the
 * ledger's own profit and loss. PDF and Excel exports. No design board covers it (v2).
 */
final class ProfitAndLossController extends Controller
{
    public function index(Request $request, CurrentCompany $current, ReportCache $cache, ScheduleThree $schedule, Reports $reports): Response
    {
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());
        $pnl = $cache->remember($company, 'schedule3-pnl', [$date->from->toDateString(), $date->asOf->toDateString()], fn () => $schedule->profitAndLoss($company, $date->from, $date->asOf));
        $ledger = $reports->profitAndLoss($company, Period::between($date->from, $date->asOf));

        return Inertia::render('reports/profit-and-loss', [
            'company' => ['name' => $company->name],
            'date' => $date->props(),
            'query' => $date->query(),
            'pnl' => $pnl,
            'ties' => $pnl['totalIncome'] === $ledger['income'] && $pnl['profit'] === $ledger['net'],
        ]);
    }

    public function export(Request $request, CurrentCompany $current, ScheduleThree $schedule, Pdf $pdf, Xlsx $xlsx): StreamedResponse|BinaryFileResponse
    {
        $format = $request->validate(['format' => ['required', 'in:pdf,xlsx']])['format'];
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());
        $pnl = $schedule->profitAndLoss($company, $date->from, $date->asOf);
        $filename = str($company->name)->slug().'-profit-and-loss-'.$date->asOf->toDateString().'.'.$format;
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private'];

        activity('reports')->causedBy($request->user())->withProperties(['format' => $format, 'statement' => 'profit-and-loss', 'to' => $date->asOf->toDateString()])->log('exported the profit and loss');

        if ($format === 'pdf') {
            $bytes = $pdf->render('exports.schedule-pnl', [
                'pnl' => $pnl,
                'company' => $company->name,
                'logo' => Pdf::logo($company),
                'kicker' => 'Statements · Schedule III',
                'title' => 'Statement of profit and loss',
                'meta' => $date->from->format('j M Y').' – '.$date->asOf->format('j M Y').' · '.$date->fy->props()['label'],
            ]);

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $filename, ['Content-Type' => 'application/pdf', ...$headers]);
        }

        $m = Xlsx::money(...);
        $withPrevious = $pnl['previousFrom'] !== null;
        $rows = array_map(fn (array $l) => [
            $l['label'],
            $l['note'],
            $l['amount'] === null ? null : $m($l['amount']),
            ...($withPrevious ? [$l['amount'] === null || $l['previous'] === null ? null : $m($l['previous'])] : []),
        ], $pnl['lines']);

        return response()->download($xlsx->write([
            [
                'name' => 'Profit and loss',
                'header' => ['Particulars', 'Note', 'This period', ...($withPrevious ? ['Previous period'] : [])],
                'rows' => $rows,
            ],
            [
                'name' => 'Notes',
                'header' => ['Note', 'Code', 'Account', 'This period', ...($withPrevious ? ['Previous period'] : [])],
                'rows' => Xlsx::noteRows($pnl['notes'], $withPrevious),
            ],
        ]), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ...$headers,
        ])->deleteFileAfterSend();
    }
}

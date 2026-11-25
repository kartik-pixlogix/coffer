<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ledger\CashFlow;
use App\Ledger\ReportCache;
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
 * Cash flow statement, indirect method (D157), for a period inside a fiscal year, with the
 * reconciliation to the money accounts. PDF and Excel exports. No design board covers it (v2).
 */
final class CashFlowController extends Controller
{
    public function index(Request $request, CurrentCompany $current, ReportCache $cache, CashFlow $flow): Response
    {
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());

        return Inertia::render('reports/cash-flow', [
            'company' => ['name' => $company->name],
            'date' => $date->props(),
            'query' => $date->query(),
            'flow' => $cache->remember($company, 'cash-flow', [$date->from->toDateString(), $date->asOf->toDateString()], fn () => $flow->compute($company, $date->from, $date->asOf)),
        ]);
    }

    public function export(Request $request, CurrentCompany $current, CashFlow $flow, Pdf $pdf, Xlsx $xlsx): StreamedResponse|BinaryFileResponse
    {
        $format = $request->validate(['format' => ['required', 'in:pdf,xlsx']])['format'];
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());
        $statement = $flow->compute($company, $date->from, $date->asOf);
        $filename = str($company->name)->slug().'-cash-flow-'.$date->asOf->toDateString().'.'.$format;
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private'];

        activity('reports')->causedBy($request->user())->withProperties(['format' => $format, 'statement' => 'cash-flow', 'to' => $date->asOf->toDateString()])->log('exported the cash flow statement');

        if ($format === 'pdf') {
            $bytes = $pdf->render('exports.cash-flow', [
                'flow' => $statement,
                'company' => $company->name,
                'logo' => Pdf::logo($company),
                'kicker' => 'Statements',
                'title' => 'Cash flow statement',
                'meta' => 'Indirect method · '.$date->from->format('j M Y').' – '.$date->asOf->format('j M Y').' · '.$date->fy->props()['label'],
            ]);

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $filename, ['Content-Type' => 'application/pdf', ...$headers]);
        }

        $m = Xlsx::money(...);
        $rows = [];

        foreach ([
            ['A. Operating activities', $statement['operating'], 'Net cash from operating activities (A)', $statement['operatingTotal']],
            ['B. Investing activities', $statement['investing'], 'Net cash from investing activities (B)', $statement['investingTotal']],
            ['C. Financing activities', $statement['financing'], 'Net cash from financing activities (C)', $statement['financingTotal']],
        ] as [$section, $lines, $label, $total]) {
            foreach ($lines as $line) {
                $rows[] = [$section, $line['label'], $m($line['amount'])];
            }

            $rows[] = [$section, $label, $m($total)];
        }

        $rows[] = [null, 'Net increase / (decrease) in cash (A + B + C)', $m($statement['netChange'])];
        $rows[] = [null, 'Cash and cash equivalents at the start', $m($statement['openingCash'])];
        $rows[] = [null, 'Cash and cash equivalents at the end', $m($statement['closingCash'])];

        return response()->download($xlsx->write([[
            'name' => 'Cash flow',
            'header' => ['Section', 'Particulars', 'Amount'],
            'rows' => $rows,
        ]]), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ...$headers,
        ])->deleteFileAfterSend();
    }
}

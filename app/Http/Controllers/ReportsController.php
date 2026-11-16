<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportStatus;
use App\Jobs\GenerateReportExport;
use App\Ledger\Lines;
use App\Models\Company;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\Exports\ProfitAndLossExport;
use App\Services\Reports\ProfitAndLossReport;
use App\Services\Reports\ReportPeriod;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (Reports.dc.html, spec §15): the P&L for a period with KPIs, ratios, monthly
 * bars and table, and partner P&L; exported as PDF or Excel, straight away or through a
 * queued job when the period is large (D61).
 */
final class ReportsController extends Controller
{
    public function index(Request $request, CurrentCompany $current, ProfitAndLossReport $report): Response
    {
        $company = $current->require();
        $period = ReportPeriod::fromQuery($company, $request->query());
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('reports/index', [
            'company' => ['name' => $company->name, 'jv' => $company->isJv(), 'pooled' => ProfitAndLossReport::pooled($company)],
            'period' => $period->props(),
            'query' => $period->query(),
            'report' => $report->build($company, $period),
            'exportQueued' => $this->isLarge($company, $period),
            'exports' => $this->exports($user),
        ]);
    }

    public function export(Request $request, CurrentCompany $current, ProfitAndLossExport $exporter): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        $format = $request->validate(['format' => ['required', 'in:pdf,xlsx']])['format'];
        $company = $current->require();
        $period = ReportPeriod::fromQuery($company, $request->query());
        /** @var User $user */
        $user = $request->user();
        $filename = ProfitAndLossExport::filename($company, $period, $format);

        activity('reports')->causedBy($user)->withProperties(['format' => $format, 'period' => $period->query()])->log('exported the profit and loss');

        if ($this->isLarge($company, $period)) {
            $export = ReportExport::query()->create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'report' => 'pnl',
                'format' => $format,
                'params' => $period->query(),
                'label' => ($format === 'pdf' ? 'P&L PDF' : 'P&L workbook').' · '.ReportPeriod::rangeLabel($period->period),
                'filename' => $filename,
                'status' => ExportStatus::Queued,
            ]);
            GenerateReportExport::dispatch($export->id);

            Inertia::flash('toast', ['type' => 'info', 'message' => 'Preparing '.$export->label.'. It’ll be ready to download here in a moment.']);

            return redirect()->route('reports.index', $period->query());
        }

        if ($format === 'pdf') {
            $bytes = $exporter->pdf($company, $period);

            return response()->streamDownload(fn () => print ($bytes), $filename, [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        return response()->download($exporter->xlsx($company, $period), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend();
    }

    public function download(Request $request, ReportExport $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()?->id, 404);
        abort_unless($export->status === ExportStatus::Ready && $export->path !== null, 404);

        return Storage::disk(ReportExport::DISK)->download($export->path, $export->filename, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Whether the period has enough lines to export in the background. */
    private function isLarge(Company $company, ReportPeriod $period): bool
    {
        $limit = (int) config('reports.queue_exports_over_lines', 20000);

        return Lines::in($company, $period->period)->count() > $limit;
    }

    /** @return list<array{id: int, label: string, format: string, status: string, url: string|null, createdAt: string}> */
    private function exports(User $user): array
    {
        return array_values(ReportExport::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (ReportExport $e) => [
                'id' => $e->id,
                'label' => $e->label,
                'format' => $e->format,
                'status' => $e->status->value,
                'url' => $e->status === ExportStatus::Ready ? route('reports.exports.download', $e) : null,
                'createdAt' => $e->created_at->toIso8601String(),
            ])
            ->all());
    }
}

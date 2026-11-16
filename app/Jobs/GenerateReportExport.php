<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\Company;
use App\Models\ReportExport;
use App\Services\Reports\Exports\ProfitAndLossExport;
use App\Services\Reports\ReportPeriod;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds a large report export in the background and keeps it on the private disk
 * (spec §15 "queued if large", D61). The Reports page polls until it's ready.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $exportId) {}

    public function handle(CurrentCompany $current, ProfitAndLossExport $exporter): void
    {
        $export = $this->export();

        if ($export === null || $export->status !== ExportStatus::Queued) {
            return;
        }

        $company = Company::query()->findOrFail($export->company_id);

        $current->run($company, function () use ($company, $export, $exporter): void {
            // The period as it was asked for, even if the job runs after midnight.
            $period = ReportPeriod::fromQuery($company, $export->params, $export->created_at->startOfDay());
            $path = 'companies/'.$company->id.'/exports/'.Str::random(40).'.'.$export->format;

            if ($export->format === 'pdf') {
                Storage::disk(ReportExport::DISK)->put($path, $exporter->pdf($company, $period));
            } else {
                $file = $exporter->xlsx($company, $period);

                try {
                    Storage::disk(ReportExport::DISK)->put($path, (string) file_get_contents($file));
                } finally {
                    @unlink($file);
                }
            }

            $export->forceFill(['status' => ExportStatus::Ready, 'path' => $path, 'finished_at' => now()])->save();
        });
    }

    public function failed(Throwable $exception): void
    {
        $this->export()?->forceFill(['status' => ExportStatus::Failed, 'finished_at' => now()])->save();

        report($exception);
    }

    private function export(): ?ReportExport
    {
        return ReportExport::query()->withoutGlobalScope('company')->find($this->exportId);
    }
}

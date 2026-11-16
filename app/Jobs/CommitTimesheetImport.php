<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Company;
use App\Models\TimesheetImport;
use App\Services\Timesheets\Import\TimesheetImporter;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Commits a checked timesheet import in the background (spec §8.2: "a queued job"). */
final class CommitTimesheetImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $importId) {}

    public function handle(CurrentCompany $current, TimesheetImporter $importer): void
    {
        $import = $this->import();

        if ($import === null || $import->status !== ImportStatus::Queued) {
            return;
        }

        $company = Company::query()->findOrFail($import->company_id);

        $current->run($company, fn () => $importer->commit($company, $import));
    }

    public function failed(Throwable $exception): void
    {
        $this->import()?->update([
            'status' => ImportStatus::Failed,
            'failure' => 'The import stopped before it finished. Nothing was saved; try again.',
            'finished_at' => now(),
        ]);

        report($exception);
    }

    private function import(): ?TimesheetImport
    {
        return TimesheetImport::query()->withoutGlobalScope('company')->find($this->importId);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Company;
use App\Models\TimesheetImport;
use App\Notifications\Events\ImportFinished;
use App\Services\Timesheets\Hours;
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

        // Spec §19 "import finished": the person who started it hears how it went.
        $import->refresh();
        $hours = Hours::format($import->imported_centi);
        $entries = $import->imported_count === 1 ? '1 entry' : "{$import->imported_count} entries";
        $import->user->notify(ImportFinished::timesheets(
            $import->company_id,
            false,
            "{$entries} · {$hours} h from {$import->name}".($import->skipped_count > 0 ? ", {$import->skipped_count} rows skipped" : '').'.',
        ));
    }

    public function failed(Throwable $exception): void
    {
        $import = $this->import();
        $import?->update([
            'status' => ImportStatus::Failed,
            'failure' => 'The import stopped before it finished. Nothing was saved; try again.',
            'finished_at' => now(),
        ]);
        $import?->user->notify(ImportFinished::timesheets($import->company_id, true, "{$import->name}: the import stopped before it finished. Nothing was saved; try again."));

        report($exception);
    }

    private function import(): ?TimesheetImport
    {
        return TimesheetImport::query()->withoutGlobalScope('company')->find($this->importId);
    }
}

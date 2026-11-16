<?php

declare(strict_types=1);

namespace App\Services\Timesheets\Import;

use App\Actions\Timesheets\LogTime;
use App\Enums\ImportStatus;
use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimesheetImport;
use App\Services\Timesheets\Hours;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reads an uploaded import with its mapping, checks the rows, and commits the valid ones
 * (spec §8.2). Monthly totals (`2026-04`) are spread over the month's working days like
 * month mode. The skipped rows are written to an error CSV for fixing and re-importing.
 */
final class TimesheetImporter
{
    public function __construct(
        private readonly ImportChecker $checker,
        private readonly LogTime $log,
    ) {}

    public function file(TimesheetImport $import): CsvFile
    {
        return CsvFile::read(Storage::disk(TimesheetImport::DISK)->path($import->path));
    }

    /** @return list<ImportRow> */
    public function rows(Company $company, TimesheetImport $import): array
    {
        return $this->checker->check($company, $this->file($import)->rows, $import->mapping ?? []);
    }

    public function commit(Company $company, TimesheetImport $import): void
    {
        $rows = $this->rows($company, $import);
        $ok = array_values(array_filter($rows, fn (ImportRow $r) => $r->ok()));
        $skipped = array_values(array_filter($rows, fn (ImportRow $r) => ! $r->ok()));

        $errorsPath = $skipped === [] ? null : $this->writeErrors($import, $skipped);

        DB::transaction(function () use ($company, $import, $ok, $skipped, $errorsPath): void {
            $members = Member::query()->withoutGlobalScope('company')->whereKey(array_unique(array_map(fn (ImportRow $r) => $r->memberId, $ok)))->get()->keyBy('id');
            $projects = [];
            $count = 0;
            $centi = 0;

            foreach ($ok as $row) {
                $projectId = $row->projectId ?? ($projects[mb_strtolower($row->project)] ??= Project::query()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $row->project],
                    ['status' => ProjectStatus::Active],
                )->id);

                /** @var Member $member */
                $member = $members[$row->memberId];
                $details = ['project_id' => $projectId, 'partner_id' => null, 'billable' => $row->billable, 'notes' => $row->notes];

                if ($row->monthly && $row->date !== null) {
                    $days = Hours::workingDays($row->date, Hours::mask($company->working_days, false));
                    $count += $this->log->batch($member, $days, (int) $row->centi, $details, $import->user, $import->id)->count();
                } elseif ($row->date !== null) {
                    $this->log->imported($member, $row->date, (int) $row->centi, $details, $import->user, $import->id);
                    $count++;
                }

                $centi += (int) $row->centi;
            }

            $import->update([
                'status' => ImportStatus::Done,
                'imported_count' => $count,
                'imported_centi' => $centi,
                'skipped_count' => count($skipped),
                'errors_path' => $errorsPath,
                'finished_at' => now(),
            ]);
        });

        activity('timesheets')->performedOn($import)->causedBy($import->user)
            ->withProperties(['entries' => $import->imported_count, 'skipped' => $import->skipped_count])
            ->log('imported hours');
    }

    /**
     * The skipped rows as CSV: the file's own columns plus "Error", ready to fix and re-import.
     *
     * @param  list<ImportRow>  $rows
     */
    public static function errorCsv(TimesheetImport $import, array $rows): string
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            return '';
        }

        fputcsv($out, [...$import->header, 'Error'], escape: '');

        foreach ($rows as $row) {
            fputcsv($out, [...$row->cells, (string) $row->error], escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @param list<ImportRow> $rows */
    private function writeErrors(TimesheetImport $import, array $rows): string
    {
        $path = preg_replace('/\.csv$/', '', $import->path).'-errors.csv';
        Storage::disk(TimesheetImport::DISK)->put($path, self::errorCsv($import, $rows));

        return $path;
    }
}

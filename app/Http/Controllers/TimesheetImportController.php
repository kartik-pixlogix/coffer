<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Timesheets\LogTime;
use App\Enums\ImportStatus;
use App\Enums\MemberStatus;
use App\Enums\ProjectStatus;
use App\Jobs\CommitTimesheetImport;
use App\Models\Company;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimesheetImport;
use App\Models\User;
use App\Policies\TimeEntryPolicy;
use App\Services\Timesheets\Hours;
use App\Services\Timesheets\Import\ColumnMapper;
use App\Services\Timesheets\Import\CsvFile;
use App\Services\Timesheets\Import\ImportDates;
use App\Services\Timesheets\Import\ImportRow;
use App\Services\Timesheets\Import\TimesheetImporter;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Timesheets › Bulk import (BulkImport.dc.html, spec §8.2): Upload → Map columns →
 * Preview & import (queued), with an error CSV; and the "Monthly hours by hand" tab.
 * Members don't import: they log their own hours on Timesheets.
 */
final class TimesheetImportController extends Controller
{
    public const int PREVIEW_PER_PAGE = 50;

    public function __construct(private readonly TimeEntryPolicy $policy) {}

    public function index(Request $request, CurrentCompany $current, TimesheetImporter $importer): Response
    {
        $this->authorizeImport();
        $company = $current->require();
        $import = $this->current($request);
        $step = $this->stepFor($import, $request->query('step'));

        return Inertia::render('timesheets/import', [
            'company' => ['name' => $company->name, 'workingDays' => $company->working_days],
            'today' => CarbonImmutable::today()->toDateString(),
            'tab' => $request->query('tab') === 'manual' ? 'manual' : 'csv',
            'step' => $step,
            'import' => $import === null ? null : $this->present($company, $import, $step, $importer, $request),
            'options' => [
                'members' => Member::query()->where('status', MemberStatus::Active->value)->orderBy('name')->get()
                    ->map(fn (Member $m) => ['id' => $m->id, 'name' => $m->name, 'partnerId' => $m->partner_id])->values(),
                'partners' => [],
                'projects' => Project::query()->where('status', ProjectStatus::Active->value)->orderBy('name')->get()
                    ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'active' => true])->values(),
            ],
        ]);
    }

    /** The template from the Upload step: the expected columns and two sample rows. */
    public function template(): StreamedResponse
    {
        $this->authorizeImport();

        return response()->streamDownload(function (): void {
            echo "developer,date,hours,project,billable\n";
            echo "Rohan Desai,1-Apr-26,2:30,Saarthi portal,yes\n";
            echo "Sana Qureshi,1-Apr-26,8,Saarthi portal,yes\n";
        }, 'coffer-timesheet-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, CurrentCompany $current): RedirectResponse
    {
        $this->authorizeImport();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.CsvFile::MAX_KB],
        ], [
            'file.required' => 'Choose a CSV file.',
            'file.mimes' => 'Upload a .csv file.',
            'file.max' => 'This file is over 5 MB. Split it into smaller files.',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $csv = CsvFile::read((string) $file->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $path = $file->storeAs("imports/{$company->id}", Str::uuid().'.csv', TimesheetImport::DISK);
        $guess = ColumnMapper::guess($csv->header, $csv->rows, $this->memberKeys());

        $import = TimesheetImport::query()->create([
            'user_id' => $user->id,
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => (string) $path,
            'size' => (int) $file->getSize(),
            'rows' => count($csv->rows),
            'header' => $csv->header,
            'mapping' => $guess['mapping'],
            'status' => ImportStatus::Uploaded,
        ]);

        return to_route('timesheets.import', ['import' => $import->id]);
    }

    public function mapping(Request $request, TimesheetImport $import): RedirectResponse
    {
        $this->authorizeImport($import);
        abort_if($import->status->isRunning() || $import->status->isFinished(), 409);

        /** @var array{mapping: array<int|string, string|null>} $data */
        $data = $request->validate([
            'mapping' => ['required', 'array', 'size:'.count($import->header)],
            'mapping.*' => ['nullable', Rule::in(ColumnMapper::FIELDS)],
        ]);

        $mapping = [];

        foreach (array_keys($import->header) as $i) {
            $mapping[$i] = $data['mapping'][$i] ?? null;
        }

        $fields = array_filter($mapping);

        if (count($fields) !== count(array_unique($fields))) {
            throw ValidationException::withMessages(['mapping' => 'Each Coffer field can only come from one column.']);
        }

        if (ColumnMapper::missing($mapping) !== []) {
            throw ValidationException::withMessages(['mapping' => 'Match a column to Member, Date, Hours and Project.']);
        }

        $import->update(['mapping' => $mapping, 'status' => ImportStatus::Mapped]);

        return to_route('timesheets.import', ['import' => $import->id, 'step' => 'preview']);
    }

    public function commit(Request $request, TimesheetImport $import, CurrentCompany $current, TimesheetImporter $importer): RedirectResponse
    {
        $this->authorizeImport($import);
        abort_unless($import->status === ImportStatus::Mapped, 409);

        $ok = array_filter($importer->rows($current->require(), $import), fn (ImportRow $r) => $r->ok());

        if ($ok === []) {
            throw ValidationException::withMessages(['import' => 'No rows are ready to import. Fix the errors and upload the file again.']);
        }

        $import->update(['status' => ImportStatus::Queued]);
        CommitTimesheetImport::dispatch($import->id);

        return to_route('timesheets.import', ['import' => $import->id, 'step' => 'preview']);
    }

    /** The rows that can't be imported, with an Error column (before or after the import). */
    public function errors(TimesheetImport $import, CurrentCompany $current, TimesheetImporter $importer): StreamedResponse
    {
        $this->authorizeImport($import);
        $name = preg_replace('/\.csv$/i', '', $import->name).'-errors.csv';
        $disk = Storage::disk(TimesheetImport::DISK);

        if ($import->status->isFinished()) {
            abort_if($import->errors_path === null || ! $disk->exists($import->errors_path), 404);

            return $disk->download($import->errors_path, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $rows = array_values(array_filter($importer->rows($current->require(), $import), fn (ImportRow $r) => ! $r->ok()));
        $csv = TimesheetImporter::errorCsv($import, $rows);

        return response()->streamDownload(fn () => print ($csv), $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "Remove file", or start over. A running import can't be removed. */
    public function destroy(TimesheetImport $import): RedirectResponse
    {
        $this->authorizeImport($import);
        abort_if($import->status->isRunning(), 409);

        if (! $import->status->isFinished()) {
            Storage::disk(TimesheetImport::DISK)->delete($import->path);
            $import->delete();
        }

        return to_route('timesheets.import');
    }

    /**
     * "Monthly hours by hand": one month total per row, each spread over the month's working
     * days like month mode (spec §8.2).
     */
    public function monthly(Request $request, CurrentCompany $current, LogTime $log): RedirectResponse
    {
        $this->authorizeImport();
        $company = $current->require();

        /** @var array{month: string, saturdays?: bool, rows: list<array{member_id: int|string, hours: string, project_id: int|string, billable?: bool, notes?: string|null}>} $data */
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'saturdays' => ['boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.member_id' => ['required', 'integer', 'distinct', Rule::exists('members', 'id')->where('company_id', $company->id)->where('status', MemberStatus::Active->value)],
            'rows.*.hours' => ['required', 'string', 'max:12'],
            'rows.*.project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('company_id', $company->id)->where('status', ProjectStatus::Active->value)],
            'rows.*.billable' => ['boolean'],
            'rows.*.notes' => ['nullable', 'string', 'max:500'],
        ], [
            'rows.required' => 'Add at least one member.',
            'rows.*.member_id.required' => 'Pick a member.',
            'rows.*.member_id.distinct' => 'This member is on another row.',
            'rows.*.hours.required' => 'Enter the hours.',
            'rows.*.project_id.required' => 'Pick a project.',
        ]);

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month']) ?: CarbonImmutable::today();
        $saturdays = (bool) ($data['saturdays'] ?? false);
        $days = Hours::workingDays($month, Hours::mask($company->working_days, $saturdays));
        $log->assertOpen($company, $days);

        $errors = [];

        foreach ($data['rows'] as $i => $row) {
            $centi = Hours::parse($row['hours']);

            if ($centi === null) {
                $errors["rows.{$i}.hours"] = 'Enter hours like 160 or 152:30.';
            } elseif ($centi < Hours::MIN) {
                $errors["rows.{$i}.hours"] = 'Hours can’t be less than 0.25.';
            } elseif ($centi > Hours::MAX * count($days)) {
                $errors["rows.{$i}.hours"] = 'That’s more than 24 h a day over '.count($days).' working days.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var User $actor */
        $actor = $request->user();
        $members = Member::query()->whereKey(array_column($data['rows'], 'member_id'))->get()->keyBy('id');

        DB::transaction(function () use ($data, $members, $log, $actor, $days): void {
            foreach ($data['rows'] as $row) {
                $member = $members[(int) $row['member_id']];
                Gate::authorize('create', [TimeEntry::class, $member]);

                $log->batch($member, $days, (int) Hours::parse($row['hours']), [
                    'project_id' => (int) $row['project_id'],
                    'partner_id' => null,
                    'billable' => (bool) ($row['billable'] ?? true),
                    'notes' => filled($row['notes'] ?? null) ? trim((string) $row['notes']) : null,
                ], $actor);
            }
        });

        $count = count($data['rows']);
        activity('timesheets')->performedOn($company)->causedBy($actor)->withProperties(['members' => $count, 'month' => $data['month']])->log('saved monthly hours');
        Inertia::flash('toast', ['type' => 'success', 'message' => "Monthly hours saved for {$count} ".($count === 1 ? 'member' : 'members')]);

        return to_route('timesheets.index', ['month' => $data['month']]);
    }

    /** Importing needs "Log time" and isn't for Members, who log only their own hours. */
    private function authorizeImport(?TimesheetImport $import = null): void
    {
        $user = request()->user();
        abort_unless($user instanceof User && $user->can('action.time.log') && ! $this->policy->ownOnly(), 403);

        // An import belongs to the person who uploaded it.
        abort_if($import !== null && $import->user_id !== $user->id, 404);
    }

    private function current(Request $request): ?TimesheetImport
    {
        $id = $request->integer('import');

        if ($id === 0) {
            return null;
        }

        /** @var User $user */
        $user = $request->user();

        return TimesheetImport::query()->whereKey($id)->where('user_id', $user->id)->first();
    }

    private function stepFor(?TimesheetImport $import, mixed $asked): string
    {
        if ($import === null) {
            return 'upload';
        }

        if ($import->status !== ImportStatus::Uploaded && $import->status !== ImportStatus::Mapped) {
            return 'preview';
        }

        return $asked === 'preview' && $import->status === ImportStatus::Mapped ? 'preview' : 'map';
    }

    /** @return array<string, mixed> */
    private function present(Company $company, TimesheetImport $import, string $step, TimesheetImporter $importer, Request $request): array
    {
        $base = [
            'id' => $import->id,
            'name' => $import->name,
            'size' => $import->size,
            'rows' => $import->rows,
            'status' => $import->status->value,
        ];

        if ($import->status->isFinished() || $import->status->isRunning()) {
            return $base + [
                'result' => [
                    'imported' => $import->imported_count,
                    'centi' => $import->imported_centi,
                    'skipped' => $import->skipped_count,
                    'hasErrors' => $import->errors_path !== null,
                    'failure' => $import->failure,
                ],
            ];
        }

        $file = $importer->file($import);

        if ($step === 'map') {
            $guess = ColumnMapper::guess($file->header, $file->rows, $this->memberKeys());
            $mapping = $import->mapping ?? $guess['mapping'];
            $dateColumn = array_search('date', $mapping, true);

            return $base + [
                'columns' => array_map(fn (int $i, string $name) => [
                    'index' => $i,
                    'name' => $name,
                    'field' => $mapping[$i] ?? null,
                    'how' => ($mapping[$i] ?? null) === null ? 'skipped' : (($mapping[$i] === $guess['mapping'][$i]) ? ($guess['how'][$i] ?? 'chosen') : 'chosen'),
                    'samples' => ColumnMapper::samples($file->rows, $i),
                ], array_keys($file->header), $file->header),
                'autoMatched' => count(array_filter($guess['how'])),
                'notes' => array_values(array_filter([
                    ...array_map(
                        fn (int $i) => '“'.$file->header[$i].'” was matched to Member by its values: they’re team members’ names.',
                        array_keys(array_filter($guess['how'], fn ($how) => $how === 'values')),
                    ),
                    $dateColumn === false ? null : ImportDates::explain(array_column($file->rows, (int) $dateColumn)),
                ])),
            ];
        }

        $rows = $importer->rows($company, $import);
        $ok = array_filter($rows, fn (ImportRow $r) => $r->ok());
        $filter = in_array($request->query('show'), ['ok', 'errors'], true) ? (string) $request->query('show') : 'all';
        $shown = array_values(match ($filter) {
            'ok' => $ok,
            'errors' => array_filter($rows, fn (ImportRow $r) => ! $r->ok()),
            default => $rows,
        });
        $pages = max(1, (int) ceil(count($shown) / self::PREVIEW_PER_PAGE));
        $page = min($pages, max(1, $request->integer('page', 1)));

        return $base + [
            'summary' => [
                'rows' => count($rows),
                'ok' => count($ok),
                'errors' => count($rows) - count($ok),
                'okCenti' => array_sum(array_map(fn (ImportRow $r) => (int) $r->centi, $ok)),
                'newProjects' => array_values(array_unique(array_map(fn (ImportRow $r) => $r->project, array_filter($ok, fn (ImportRow $r) => $r->projectId === null)))),
            ],
            'preview' => [
                'filter' => $filter,
                'page' => $page,
                'pages' => $pages,
                'rows' => array_map(fn (ImportRow $r) => [
                    'number' => $r->number,
                    'member' => $r->member,
                    'date' => $r->ok() && $r->date !== null ? ($r->monthly ? $r->date->format('M Y').' · month' : $r->date->format('j M Y')) : $r->dateRaw,
                    'hours' => $r->ok() ? number_format((int) $r->centi / 100, 2, '.', ',') : $r->hoursRaw,
                    'project' => $r->project,
                    'billable' => $r->billable,
                    'error' => $r->error,
                ], array_slice($shown, ($page - 1) * self::PREVIEW_PER_PAGE, self::PREVIEW_PER_PAGE)),
            ],
        ];
    }

    /** @return list<string> */
    private function memberKeys(): array
    {
        $keys = [];

        foreach (Member::query()->get(['name', 'email']) as $m) {
            $keys[] = mb_strtolower($m->name);

            if ($m->email !== null) {
                $keys[] = mb_strtolower($m->email);
            }
        }

        return $keys;
    }
}

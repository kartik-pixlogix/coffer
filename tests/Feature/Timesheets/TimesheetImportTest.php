<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\ImportStatus;
use App\Jobs\CommitTimesheetImport;
use App\Ledger\FiscalYears;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimesheetImport;
use App\Notifications\Events\ImportFinished;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function importCompany(): object
{
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $rohan = Member::factory()->create(['partner_id' => $books->aarav->id, 'name' => 'Rohan Desai', 'email' => 'rohan@vertexlabs.in']);
    $sana = Member::factory()->create(['partner_id' => $books->ishita->id, 'name' => 'Sana Qureshi']);
    Project::query()->create(['company_id' => $books->company->id, 'name' => 'Saarthi portal']);

    return (object) ['company' => $books->company, 'admin' => $admin, 'rohan' => $rohan, 'sana' => $sana];
}

function csvUpload(string $contents, string $name = 'hours-oct-2026.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

function uploadCsv(object $t, string $csv): TimesheetImport
{
    test()->actingAs($t->admin)->post(route('timesheets.import.store'), ['file' => csvUpload($csv)])->assertSessionHasNoErrors();

    return TimesheetImport::query()->withoutGlobalScope('company')->latest('id')->firstOrFail();
}

const SAMPLE_CSV = <<<'CSV'
    Log owner,Date,Time spent,Project,Billable?,Notes
    Rohan Desai,1-Oct-26,2:30,Saarthi portal,yes,standup
    Sana Qureshi,1-Oct-26,8,Saarthi portal,yes,
    Rohit D,1-Oct-26,6,Saarthi portal,yes,
    rohan@vertexlabs.in,02/10/2026,7.5,Kiran Exports site,no,
    Rohan Desai,2026-10-05,25:00,Internal,no,
    Sana Qureshi,31-Sep-26,7,Saarthi portal,yes,
    Sana Qureshi,1-Oct-26,8,Saarthi portal,yes,
    Rohan Desai,2026-09,42,Internal,no,
    CSV;

beforeEach(function () {
    Storage::fake('local');
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the template downloads with the expected columns', function () {
    $t = importCompany();

    $response = $this->actingAs($t->admin)->get(route('timesheets.import.template'))->assertOk();

    expect($response->streamedContent())->toStartWith("developer,date,hours,project,billable\nRohan Desai,1-Apr-26,2:30");
});

test('uploading stores the file and guesses the mapping', function () {
    $t = importCompany();
    $import = uploadCsv($t, SAMPLE_CSV);

    expect($import->rows)->toBe(8)
        ->and($import->status)->toBe(ImportStatus::Uploaded)
        ->and($import->mapping)->toBe(['member', 'date', 'hours', 'project', 'billable', 'notes']);
    Storage::disk('local')->assertExists($import->path);

    $this->actingAs($t->admin)->get(route('timesheets.import', ['import' => $import->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('timesheets/import')
            ->where('step', 'map')
            ->where('import.columns.0.name', 'Log owner')
            ->where('import.columns.0.field', 'member')
            ->where('import.columns.0.how', 'auto')
            ->where('import.columns.0.samples', ['Rohan Desai', 'Sana Qureshi', 'Rohit D'])
            ->where('import.autoMatched', 6));
});

test('uploads are limited to CSV files with rows', function () {
    $t = importCompany();

    $this->actingAs($t->admin)->post(route('timesheets.import.store'), ['file' => UploadedFile::fake()->create('hours.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors(['file' => 'Upload a .csv file.']);
    $this->actingAs($t->admin)->post(route('timesheets.import.store'), ['file' => UploadedFile::fake()->create('big.csv', 6000, 'text/csv')])
        ->assertSessionHasErrors(['file' => 'This file is over 5 MB. Split it into smaller files.']);
    $this->actingAs($t->admin)->post(route('timesheets.import.store'), ['file' => csvUpload("developer,date,hours,project\n")])
        ->assertSessionHasErrors(['file' => 'This file has no rows to import. Check it has a header row and at least one entry.']);

    $tooMany = "developer,date,hours,project\n".str_repeat("Rohan Desai,1-Oct-26,1,Internal\n", 5001);
    $this->actingAs($t->admin)->post(route('timesheets.import.store'), ['file' => csvUpload($tooMany)])
        ->assertSessionHasErrors(['file' => 'This file has more than 5,000 rows. Split it into smaller files.']);
});

test('the mapping must cover member, date, hours and project, once each', function () {
    $t = importCompany();
    $import = uploadCsv($t, SAMPLE_CSV);

    $this->actingAs($t->admin)->put(route('timesheets.import.mapping', $import), ['mapping' => ['member', 'date', null, 'project', 'billable', null]])
        ->assertSessionHasErrors(['mapping' => 'Match a column to Member, Date, Hours and Project.']);
    $this->actingAs($t->admin)->put(route('timesheets.import.mapping', $import), ['mapping' => ['member', 'date', 'hours', 'project', 'project', null]])
        ->assertSessionHasErrors(['mapping' => 'Each Coffer field can only come from one column.']);

    $this->actingAs($t->admin)->put(route('timesheets.import.mapping', $import), ['mapping' => ['member', 'date', 'hours', 'project', 'billable', null]])
        ->assertRedirect(route('timesheets.import', ['import' => $import->id, 'step' => 'preview']));
    expect($import->fresh()->status)->toBe(ImportStatus::Mapped);
});

test('the preview counts rows and explains each error', function () {
    $t = importCompany();
    $import = uploadCsv($t, SAMPLE_CSV);
    $this->actingAs($t->admin)->put(route('timesheets.import.mapping', $import), ['mapping' => $import->mapping]);

    $this->actingAs($t->admin)->get(route('timesheets.import', ['import' => $import->id, 'step' => 'preview']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('step', 'preview')
            ->where('import.summary.rows', 8)
            ->where('import.summary.ok', 4)
            ->where('import.summary.errors', 4)
            ->where('import.summary.okCenti', 250 + 800 + 750 + 4200)
            ->where('import.summary.newProjects', ['Kiran Exports site'])
            ->where('import.preview.rows.0.date', '1 Oct 2026')
            ->where('import.preview.rows.0.hours', '2.50')
            ->where('import.preview.rows.2.error', 'No member called “Rohit D”. Did you mean Rohan Desai?')
            ->where('import.preview.rows.3.member', 'Rohan Desai') // matched by email
            ->where('import.preview.rows.4.error', 'Hours can’t be more than 24 in a day.')
            ->where('import.preview.rows.4.hours', '25:00')
            ->where('import.preview.rows.5.error', '31 September doesn’t exist.')
            ->where('import.preview.rows.6.error', 'Same as row 2.')
            ->where('import.preview.rows.7.date', 'Sep 2026 · month'));

    $this->actingAs($t->admin)->get(route('timesheets.import', ['import' => $import->id, 'step' => 'preview', 'show' => 'errors']))
        ->assertInertia(fn (Assert $page) => $page->has('import.preview.rows', 4));
});

test('existing entries and closed years are flagged', function () {
    $t = importCompany();
    $project = Project::query()->withoutGlobalScope('company')->where('company_id', $t->company->id)->where('name', 'Saarthi portal')->firstOrFail();
    TimeEntry::query()->create(['company_id' => $t->company->id, 'member_id' => $t->sana->id, 'partner_id' => $t->sana->partner_id, 'project_id' => $project->id, 'date' => '2026-10-01', 'hours_centi' => 800, 'billable' => true]);
    app(FiscalYears::class)->forDate($t->company, CarbonImmutable::parse('2026-03-01'))->update(['closed_at' => now()]);

    $import = uploadCsv($t, "developer,date,hours,project\nSana Qureshi,1-Oct-26,8,Saarthi portal\nRohan Desai,15-Mar-26,4,Internal\n");
    $this->actingAs($t->admin)->put(route('timesheets.import.mapping', $import), ['mapping' => $import->mapping]);

    $this->actingAs($t->admin)->get(route('timesheets.import', ['import' => $import->id, 'step' => 'preview']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('import.preview.rows.0.error', 'Already logged: Sana Qureshi, 8.0 h on 1 Oct 2026.')
            ->where('import.preview.rows.1.error', 'FY 2025-26 is closed, so this date can’t be imported.'));
});

test('importing is queued, commits the valid rows and keeps an error CSV', function () {
    $t = importCompany();
    $import = uploadCsv($t, SAMPLE_CSV);
    $this->actingAs($t->admin)->put(route('timesheets.import.mapping', $import), ['mapping' => $import->mapping]);

    // Before importing, the error CSV can already be downloaded.
    $before = $this->actingAs($t->admin)->get(route('timesheets.import.errors', $import))->assertOk()->streamedContent();
    expect($before)->toStartWith('"Log owner",Date,"Time spent",Project,Billable?,Notes,Error')
        ->toContain('"Rohit D",1-Oct-26,6,"Saarthi portal",yes,,"No member called “Rohit D”. Did you mean Rohan Desai?"');

    Queue::fake();
    $this->actingAs($t->admin)->post(route('timesheets.import.commit', $import))->assertRedirect();
    Queue::assertPushed(CommitTimesheetImport::class, fn ($job) => $job->importId === $import->id);
    expect($import->fresh()->status)->toBe(ImportStatus::Queued);

    $this->actingAs($t->admin)->get(route('timesheets.import', ['import' => $import->id]))
        ->assertInertia(fn (Assert $page) => $page->where('step', 'preview')->where('import.status', 'queued'));

    Notification::fake();
    app()->call([new CommitTimesheetImport($import->id), 'handle']);
    // Spec §19 "import finished": whoever started it hears how it went.
    Notification::assertSentTo($t->admin, ImportFinished::class, fn (ImportFinished $n, array $c, object $u) => $n->toArray($u)['title'] === 'Your timesheet import finished'
        && str_contains($n->toArray($u)['body'], '25 entries · 60.0 h')
        && str_contains($n->toArray($u)['body'], '4 rows skipped'));

    $import->refresh();
    $entries = TimeEntry::query()->withoutGlobalScope('company')->where('import_id', $import->id)->get();
    $september = $entries->filter(fn (TimeEntry $e) => $e->date->month === 9);

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->skipped_count)->toBe(4)
        ->and($import->imported_centi)->toBe(6000)
        ->and($entries->sum('hours_centi'))->toBe(6000)
        ->and($september)->toHaveCount(22) // 42 h over September's 22 weekdays
        ->and($september->sum('hours_centi'))->toBe(4200)
        ->and($september->pluck('batch')->unique())->toHaveCount(1)
        ->and($import->imported_count)->toBe(25)
        ->and(Project::query()->withoutGlobalScope('company')->where('company_id', $t->company->id)->where('name', 'Kiran Exports site')->exists())->toBeTrue();

    $after = $this->actingAs($t->admin)->get(route('timesheets.import.errors', $import))->assertOk();
    expect($after->streamedContent())->toContain('31 September doesn’t exist.');

    $this->actingAs($t->admin)->get(route('timesheets.import', ['import' => $import->id]))
        ->assertInertia(fn (Assert $page) => $page->where('import.result.imported', 25)->where('import.result.centi', 6000)->where('import.result.skipped', 4));
});

test('imports belong to their uploader and Members can’t import', function () {
    $t = importCompany();
    $import = uploadCsv($t, SAMPLE_CSV);
    $other = userWithRole(CompanyRole::Admin, $t->company);

    $this->actingAs($other)->get(route('timesheets.import.errors', $import))->assertNotFound();
    $this->actingAs($other)->post(route('timesheets.import.commit', $import))->assertNotFound();

    $member = userWithRole(CompanyRole::Member, $t->company);
    $this->actingAs($member)->get(route('timesheets.import'))->assertForbidden();
    $this->actingAs($member)->post(route('timesheets.import.store'), ['file' => csvUpload(SAMPLE_CSV)])->assertForbidden();
});

test('removing an upload deletes the file', function () {
    $t = importCompany();
    $import = uploadCsv($t, SAMPLE_CSV);

    $this->actingAs($t->admin)->delete(route('timesheets.import.destroy', $import))->assertRedirect(route('timesheets.import'));

    Storage::disk('local')->assertMissing($import->path);
    expect(TimesheetImport::query()->withoutGlobalScope('company')->find($import->id))->toBeNull();
});

test('monthly hours by hand spread each row over the month', function () {
    $t = importCompany();
    $internal = Project::query()->withoutGlobalScope('company')->where('company_id', $t->company->id)->where('name', 'Internal')->firstOrFail();

    $this->actingAs($t->admin)->post(route('timesheets.monthly'), [
        'month' => '2026-11',
        'saturdays' => false,
        'rows' => [
            ['member_id' => $t->rohan->id, 'hours' => '168', 'project_id' => $internal->id, 'billable' => true],
            ['member_id' => $t->sana->id, 'hours' => '152:30', 'project_id' => $internal->id, 'billable' => false],
        ],
    ])->assertRedirect(route('timesheets.index', ['month' => '2026-11']))
        ->assertInertiaFlash('toast.message', 'Monthly hours saved for 2 members');

    $rohan = TimeEntry::query()->withoutGlobalScope('company')->where('member_id', $t->rohan->id)->get();
    $sana = TimeEntry::query()->withoutGlobalScope('company')->where('member_id', $t->sana->id)->get();

    expect($rohan)->toHaveCount(21)->and($rohan->sum('hours_centi'))->toBe(16800)
        ->and($sana->sum('hours_centi'))->toBe(15250)->and($sana->every(fn ($e) => ! $e->billable))->toBeTrue();

    $this->actingAs($t->admin)->post(route('timesheets.monthly'), [
        'month' => '2026-11',
        'rows' => [['member_id' => $t->rohan->id, 'hours' => '999', 'project_id' => $internal->id]],
    ])->assertSessionHasErrors(['rows.0.hours' => 'That’s more than 24 h a day over 21 working days.']);
});

test('imported rows can’t take a member past 24 h on a day (G4)', function () {
    $t = importCompany();
    $project = Project::query()->withoutGlobalScope('company')->where('company_id', $t->company->id)->where('name', 'Saarthi portal')->firstOrFail();
    TimeEntry::query()->create(['company_id' => $t->company->id, 'member_id' => $t->rohan->id, 'partner_id' => $t->rohan->partner_id, 'project_id' => $project->id, 'date' => '2026-10-01', 'hours_centi' => 1600, 'billable' => true]);

    $import = uploadCsv($t, "developer,date,hours,project\nRohan Desai,1-Oct-26,6,Saarthi portal\nRohan Desai,1-Oct-26,3,Internal\n");
    $this->actingAs($t->admin)->put(route('timesheets.import.mapping', $import), ['mapping' => $import->mapping]);

    $this->actingAs($t->admin)->get(route('timesheets.import', ['import' => $import->id, 'step' => 'preview']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('import.preview.rows.0.error', null)
            ->where('import.preview.rows.1.error', 'That puts Rohan Desai at 25.0 h on 1 Oct 2026. A day holds at most 24 h across all entries.'));
});

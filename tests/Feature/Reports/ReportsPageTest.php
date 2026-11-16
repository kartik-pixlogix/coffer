<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\ExportStatus;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Vertex Labs: ₹6,00,000 income (₹4,00,000 Aarav's), ₹1,50,000 expenses with ₹50,000 salary. */
function reportBooks(): object
{
    $books = singleBooks(openingPaise: 1_00_000_00);
    $p = app(Postings::class);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $p->incomeToAccount($books->company, fyDay(15), 4_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav);
    $p->incomeToAccount($books->company, fyDay(120), 2_00_000_00, category($books->company, 'income', 'Consulting'), $books->account, $books->ishita);
    $p->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::company(1_00_000_00, $books->account)]);
    $p->expense($books->company, fyDay(125), category($books->company, 'expense', 'Salary'), [PayerLine::company(50_000_00, $books->account)]);

    return $books;
}

test('page access follows the matrix: Admins, Partners and Viewers; never Members', function (CompanyRole $role, int $status) {
    $books = reportBooks();

    $this->actingAs(userWithRole($role, $books->company))->get(route('reports.index'))->assertStatus($status);
    $this->actingAs(userWithRole($role, $books->company))->get(route('reports.export', ['format' => 'pdf']))->assertStatus($status);
})->with([
    'admin' => [CompanyRole::Admin, 200],
    'partner' => [CompanyRole::Partner, 200],
    'viewer' => [CompanyRole::Viewer, 200],
    'member' => [CompanyRole::Member, 403],
]);

test('the page shows KPIs, ratios, the months and partner P&L for the year to date', function () {
    $books = reportBooks();

    $this->actingAs(userWithRole(CompanyRole::Viewer, $books->company))->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')
            ->where('period.preset', 'fy')
            ->where('period.label', '1 Apr – 15 Nov 2026')
            ->where('report.kpis.income', 6_00_000_00)
            ->where('report.kpis.expenses', 1_50_000_00)
            ->where('report.kpis.salary', 50_000_00)
            ->where('report.kpis.other', 1_00_000_00)
            ->where('report.kpis.net', 4_50_000_00)
            ->where('report.kpis.marginBps', 7500)
            ->where('report.kpis.expenseRatioBps', 2500)
            ->where('report.kpis.months', 8)
            ->where('report.kpis.avgIncome', 75_000_00)
            ->has('report.months', 8)
            ->where('report.months.0.month', '2026-04')
            ->where('report.months.0.income', 4_00_000_00)
            ->where('report.totals.net', 4_50_000_00)
            ->has('report.partners', 2)
            ->where('report.partners.0.name', 'Aarav Mehta')
            ->where('report.partners.0.revenue', 4_00_000_00)
            ->where('report.partners.0.equityBps', 6000)
            ->where('report.partnerTotals.profitDue', 4_50_000_00)
            ->where('report.partnerTotals.net', 4_50_000_00)
            ->where('exportQueued', false)
            ->where('exports', [])
            ->has('can.pages'));
});

test('a quarter, a month and a custom range narrow the figures and keep the year’s bars', function () {
    $books = reportBooks();
    $user = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($user)->get(route('reports.index', ['period' => 'q1']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.preset', 'q1')
            ->where('report.kpis.income', 4_00_000_00)
            ->has('report.months', 3)
            ->has('report.bars', 8)
            ->where('report.bars.0.inPeriod', true)
            ->where('report.bars.3.inPeriod', false));

    $this->actingAs($user)->get(route('reports.index', ['period' => 'month', 'month' => '2026-06']))
        ->assertInertia(fn (Assert $page) => $page->where('period.preset', 'month')->where('report.kpis.income', 0)->where('report.kpis.marginBps', null));

    $this->actingAs($user)->get(route('reports.index', ['period' => 'custom', 'from' => '2026-07-30', 'to' => '2026-08-10']))
        ->assertInertia(fn (Assert $page) => $page->where('period.preset', 'custom')->where('report.kpis.income', 2_00_000_00)->where('report.kpis.net', 1_50_000_00));
});

test('the PDF export opens as a PDF', function () {
    $books = reportBooks();

    $response = $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))
        ->get(route('reports.export', ['format' => 'pdf']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $pdf = $response->streamedContent();

    expect(str_starts_with($pdf, '%PDF'))->toBeTrue()
        ->and($pdf)->toContain('PlusJakartaSans')
        ->and($response->headers->get('Content-Disposition'))->toContain('vertex-labs-pvt-ltd-pnl-2026-04-01-to-2026-11-15.pdf');
});

test('the Excel export is a workbook with the summary, monthly and partner sheets', function () {
    $books = reportBooks();

    $response = $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))
        ->get(route('reports.export', ['format' => 'xlsx']))
        ->assertOk();

    $path = $response->baseResponse->getFile()->getPathname();
    $copy = tempnam(sys_get_temp_dir(), 'xlsx-test').'.xlsx';
    copy($path, $copy);

    expect(file_get_contents($copy, length: 2))->toBe('PK');

    $reader = SimpleExcelReader::create($copy)->noHeaderRow();
    expect($reader->getSheetNames())->toBe(['Summary', 'Monthly', 'Partners']);

    $summary = SimpleExcelReader::create($copy)->noHeaderRow()->fromSheetName('Summary')->getRows()->all();
    $monthly = SimpleExcelReader::create($copy)->noHeaderRow()->fromSheetName('Monthly')->getRows()->all();
    $partners = SimpleExcelReader::create($copy)->noHeaderRow()->fromSheetName('Partners')->getRows()->all();

    expect($summary[4])->toBe(['Revenue', 600000])
        ->and($summary[8])->toBe(['Net profit', 450000])
        ->and($monthly[1])->toBe(['April 2026', 400000, 100000, 100000, 0, 300000, 0.75])
        ->and(end($monthly))->toBe(['Total', 600000, 150000, 100000, 50000, 450000, 0.75])
        ->and($partners[1][0])->toBe('Aarav Mehta')
        ->and($partners[1][3])->toBe(400000);

    @unlink($copy);
});

test('exports validate the format', function () {
    $books = reportBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))
        ->get(route('reports.export', ['format' => 'docx']))
        ->assertSessionHasErrors('format');
});

test('a large period is exported by a queued job and offered for download to whoever asked', function () {
    config(['reports.queue_exports_over_lines' => 0]);
    $books = reportBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('reports.export', ['format' => 'pdf', 'period' => 'q1']))
        ->assertRedirect(route('reports.index', ['fy' => 2026, 'period' => 'q1']))
        ->assertInertiaFlash('toast.type', 'info');

    $export = ReportExport::query()->withoutGlobalScopes()->sole();
    expect($export->status)->toBe(ExportStatus::Ready)
        ->and($export->label)->toBe('P&L PDF · 1 Apr – 30 Jun 2026');
    Storage::disk('local')->assertExists($export->path);

    $this->actingAs($admin)->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page->where('exportQueued', true)->where('exports.0.status', 'ready')->where('exports.0.url', route('reports.exports.download', $export)));

    $download = $this->actingAs($admin)->get(route('reports.exports.download', $export))->assertOk();
    expect(str_starts_with($download->streamedContent(), '%PDF'))->toBeTrue();

    // Another user of the same company, and anyone in another company, get a 404.
    $this->actingAs(userWithRole(CompanyRole::Partner, $books->company))->get(route('reports.exports.download', $export))->assertNotFound();
    $this->actingAs(userWithRole(CompanyRole::Admin))->get(route('reports.exports.download', $export))->assertNotFound();
});

test('another company’s figures never show', function () {
    $books = reportBooks();
    $stranger = userWithRole(CompanyRole::Admin);

    $this->actingAs($stranger)->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page->where('report.kpis.income', 0)->where('report.partners', []));
    expect($books->company->id)->not->toBe(companyOf($stranger)->id);
});

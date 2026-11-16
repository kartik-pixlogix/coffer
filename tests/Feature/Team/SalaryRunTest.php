<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Enums\CompanyRole;
use App\Enums\CostType;
use App\Enums\JournalType;
use App\Ledger\FiscalYears;
use App\Ledger\IntegrityCheck;
use App\Ledger\Period;
use App\Ledger\Reports;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Member;
use App\Models\Project;
use App\Models\SalaryRun;
use App\Models\TimeEntry;
use App\Services\Team\MemberRates;
use App\Services\Team\SalaryGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

function salaryMember(object $books, object $partner, CostType $type, int $rate, string $name = 'Rohan Desai'): Member
{
    return Member::factory()->create(['partner_id' => $partner->id, 'name' => $name, 'cost_type' => $type, 'rate_paise' => $rate]);
}

function workHours(Member $member, string $date, int $centi, bool $billable = true): TimeEntry
{
    return TimeEntry::query()->withoutGlobalScope('company')->create([
        'company_id' => $member->company_id,
        'member_id' => $member->id,
        'partner_id' => $member->partner_id,
        'project_id' => Project::query()->withoutGlobalScope('company')->where('company_id', $member->company_id)->value('id'),
        'date' => $date,
        'hours_centi' => $centi,
        'billable' => $billable,
    ]);
}

/** @return list<array{type: string, debit: int, credit: int, role: string}> */
function salaryLines(Member $member): array
{
    return JournalLine::query()
        ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
        ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
        ->where('e.company_id', $member->company_id)
        ->where('e.type', JournalType::Salary->value)
        ->orderBy('journal_lines.id')
        ->get(['a.role', 'journal_lines.debit_paise', 'journal_lines.credit_paise', 'journal_lines.member_id', 'journal_lines.side_id', 'a.money_account_id'])
        ->map(fn ($l) => ['role' => $l->role, 'debit' => (int) $l->debit_paise, 'credit' => (int) $l->credit_paise])
        ->all();
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('hourly salary is billable hours × rate, posted on the month’s last day from the partner’s account', function () {
    $books = singleBooks();
    $rohan = salaryMember($books, $books->aarav, CostType::Hourly, 500_00);
    workHours($rohan, '2026-10-01', 800);
    workHours($rohan, '2026-10-02', 750);
    workHours($rohan, '2026-10-03', 300, billable: false);

    $outcome = app(SalaryGenerator::class)->runMember($books->company, $rohan->fresh(), CarbonImmutable::parse('2026-10-01'));

    $run = SalaryRun::query()->withoutGlobalScope('company')->sole();
    $entry = JournalEntry::query()->withoutGlobalScope('company')->findOrFail($run->journal_entry_id);

    expect($outcome)->toBe('posted')
        ->and($run->amount_paise)->toBe(7_750_00)
        ->and($run->billable_centi)->toBe(1550)
        ->and($entry->type)->toBe(JournalType::Salary)
        ->and($entry->date->toDateString())->toBe('2026-10-31')
        ->and($entry->memo)->toBe('Salary — Rohan Desai — 2026-10')
        ->and($entry->source_type)->toBe(SalaryRun::class)
        ->and(salaryLines($rohan))->toBe([
            ['role' => AccountRole::Expense->value, 'debit' => 7_750_00, 'credit' => 0],
            ['role' => AccountRole::Money->value, 'debit' => 0, 'credit' => 7_750_00],
        ]);
});

test('a JV member’s salary is paid from their side’s account', function () {
    $books = jvBooks();
    $sana = salaryMember($books, $books->kabir, CostType::FixedMonthly, 67_500_00, 'Sana Qureshi');

    app(SalaryGenerator::class)->runMember($books->company, $sana->fresh(), CarbonImmutable::parse('2026-10-01'));

    $credit = JournalLine::query()->withoutGlobalScope('company')->where('credit_paise', 67_500_00)->sole();
    $debit = JournalLine::query()->withoutGlobalScope('company')->where('debit_paise', 67_500_00)->sole();

    expect($credit->account->money_account_id)->toBe($books->bluefinAccount->id)
        ->and($debit->member_id)->toBe($sana->id)
        ->and($debit->partner_id)->toBe($books->kabir->id);
});

test('running again is idempotent, and an hours change reverses and re-posts', function () {
    $books = singleBooks();
    $rohan = salaryMember($books, $books->aarav, CostType::Hourly, 500_00);
    workHours($rohan, '2026-10-01', 800);
    $salaries = app(SalaryGenerator::class);
    $october = CarbonImmutable::parse('2026-10-01');

    expect($salaries->runMember($books->company, $rohan->fresh(), $october))->toBe('posted')
        ->and($salaries->runMember($books->company, $rohan->fresh(), $october))->toBe('unchanged')
        ->and(JournalEntry::query()->withoutGlobalScope('company')->count())->toBe(1);

    // Logging more October hours re-runs October by itself (queue is sync in tests).
    workHours($rohan, '2026-10-02', 400);

    $run = SalaryRun::query()->withoutGlobalScope('company')->sole();
    $entries = JournalEntry::query()->withoutGlobalScope('company')->orderBy('id')->get();

    expect($run->amount_paise)->toBe(6_000_00)
        ->and($entries)->toHaveCount(3)
        ->and($entries[1]->reverses_id)->toBe($entries[0]->id)
        ->and($entries[2]->id)->toBe($run->journal_entry_id)
        ->and(app(Reports::class)->figures($books->company, Period::between(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')))['salary'])->toBe(6_000_00);

    // Deleting all of it leaves nothing posted.
    TimeEntry::query()->withoutGlobalScope('company')->get()->each->delete();

    expect(SalaryRun::query()->withoutGlobalScope('company')->sole()->journal_entry_id)->toBeNull()
        ->and(app(IntegrityCheck::class)->run($books->company))->toBe([]);
});

test('a rate change mid-year leaves earlier salaries alone', function () {
    $books = singleBooks();
    $arjun = salaryMember($books, $books->aarav, CostType::Hourly, 600_00, 'Arjun Nair');
    workHours($arjun, '2026-09-10', 1000);
    workHours($arjun, '2026-10-10', 1000);
    $salaries = app(SalaryGenerator::class);

    $salaries->runMember($books->company, $arjun->fresh(), CarbonImmutable::parse('2026-09-01'));
    app(MemberRates::class)->change($arjun, CostType::Hourly, 700_00, CarbonImmutable::parse('2026-10-01'));
    $salaries->runMonth($books->company, CarbonImmutable::parse('2026-09-01'));
    $salaries->runMonth($books->company, CarbonImmutable::parse('2026-10-01'));

    $runs = SalaryRun::query()->withoutGlobalScope('company')->orderBy('month')->pluck('amount_paise')->all();

    expect($runs)->toBe([6_000_00, 7_000_00]);
});

test('deactivated fixed members are paid for their last month only', function () {
    $books = singleBooks();
    $dev = salaryMember($books, $books->ishita, CostType::FixedMonthly, 40_000_00, 'Dev Patel');
    $dev->update(['deactivated_on' => '2026-09-12']);
    $salaries = app(SalaryGenerator::class);

    $september = $salaries->runMonth($books->company, CarbonImmutable::parse('2026-09-01'));
    $october = $salaries->runMonth($books->company, CarbonImmutable::parse('2026-10-01'));

    expect($september->counts['posted'])->toBe(1)
        ->and($october->counts['posted'])->toBe(0)
        ->and(SalaryRun::query()->withoutGlobalScope('company')->pluck('amount_paise')->all())->toBe([40_000_00]);
});

test('closed fiscal years are left alone', function () {
    $books = singleBooks();
    $dev = salaryMember($books, $books->ishita, CostType::FixedMonthly, 40_000_00, 'Dev Patel');
    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2026-10-31'))->update(['closed_at' => now()]);

    expect(app(SalaryGenerator::class)->runMember($books->company, $dev->fresh(), CarbonImmutable::parse('2026-10-01')))->toBe('locked')
        ->and(SalaryRun::query()->withoutGlobalScope('company')->count())->toBe(0);
});

test('the monthly command runs last month for every company', function () {
    $single = singleBooks();
    $jv = jvBooks();
    salaryMember($single, $single->aarav, CostType::FixedMonthly, 40_000_00, 'Dev Patel');
    salaryMember($jv, $jv->meera, CostType::FixedMonthly, 35_000_00, 'Vivaan Kapoor');

    Artisan::call('coffer:salaries');
    Artisan::call('coffer:salaries'); // again: nothing changes

    expect(SalaryRun::query()->withoutGlobalScope('company')->whereDate('month', '2026-10-01')->pluck('amount_paise')->sort()->values()->all())->toBe([35_000_00, 40_000_00])
        ->and(JournalEntry::query()->withoutGlobalScope('company')->where('type', JournalType::Salary->value)->count())->toBe(2);
});

test('Admins preview and run salaries from Team; others can’t', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $rohan = salaryMember($books, $books->aarav, CostType::Hourly, 500_00);
    workHours($rohan, '2026-10-05', 1000);

    $this->actingAs($admin)->getJson(route('team.salaries.preview', ['month' => '2026-10']))
        ->assertOk()
        ->assertJsonPath('rows.0.name', 'Rohan Desai')
        ->assertJsonPath('rows.0.amountPaise', 5_000_00)
        ->assertJsonPath('rows.0.postedPaise', null);

    $this->actingAs($admin)->post(route('team.salaries.store'), ['month' => '2026-10'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Salaries for October 2026: 1 posted');

    $this->actingAs($admin)->post(route('team.salaries.store'), ['month' => '2026-11'])
        ->assertSessionHasErrors(['month' => 'Salaries run for months that are over.']);

    $partner = userWithRole(CompanyRole::Partner, $books->company);
    $this->actingAs($partner)->post(route('team.salaries.store'), ['month' => '2026-10'])->assertForbidden();
    $this->actingAs($admin)->get(route('team.index'))->assertInertia(fn (Assert $page) => $page->where('abilities.runSalaries', true));
});

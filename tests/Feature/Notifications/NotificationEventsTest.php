<?php

declare(strict_types=1);

use App\Actions\Invites\AcceptInvite;
use App\Actions\Invites\IssueInvite;
use App\Enums\CompanyRole;
use App\Enums\CostType;
use App\Jobs\CommitTimesheetImport;
use App\Ledger\Postings;
use App\Models\Company;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Member;
use App\Models\Plan;
use App\Models\TimesheetImport;
use App\Models\User;
use App\Notifications\AccessRequested;
use App\Notifications\Events\ExpenseDecided;
use App\Notifications\Events\ExpenseSubmitted;
use App\Notifications\Events\FiscalYearClosed;
use App\Notifications\Events\FiscalYearReminder;
use App\Notifications\Events\ImportFinished;
use App\Notifications\Events\InviteAccepted;
use App\Notifications\Events\SalaryRunDone;
use App\Notifications\Events\SettlementDue;
use App\Notifications\Events\TrialEnding;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Spec §19 events (D78): who hears what, on which channel, with the company stamped on
 * the row, and nothing for the person who caused it.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** A JV with an Admin, two partner users and a Member. */
function notifBooks(): object
{
    $books = jvBooks();
    $books->admin = userWithRole(CompanyRole::Admin, $books->company, ['name' => 'Admin User']);
    $books->aaravUser = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Aarav Mehta']);
    $books->kabirUser = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Kabir Shah']);
    $books->rohanUser = userWithRole(CompanyRole::Member, $books->company, ['name' => 'Rohan Desai']);
    $books->viewer = userWithRole(CompanyRole::Viewer, $books->company, ['name' => 'Ledger Viewer']);
    $books->rohan = Member::factory()->create(['partner_id' => $books->aarav->id, 'name' => 'Rohan Desai', 'user_id' => $books->rohanUser->id, 'rate_paise' => 500_00]);

    return $books;
}

/** @return array<string, mixed> */
function notifExpense(object $books, array $overrides = []): array
{
    return $overrides + [
        'description' => 'Uber to Asha Retail',
        'amount_paise' => 640_00,
        'spent_on' => '2026-10-12',
        'category_id' => category($books->company, 'expense', 'Travel')->id,
        'payer_mode' => 'full',
        'payers' => [['type' => 'member', 'member_id' => $books->rohan->id, 'account_id' => null, 'amount_paise' => 640_00]],
    ];
}

test('a submitted expense reaches everyone who may approve it, never the submitter; the row carries its company', function () {
    $b = notifBooks();

    $this->actingAs($b->rohanUser)->post(route('expenses.store'), notifExpense($b))->assertRedirect();

    $expense = Expense::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    $rows = DatabaseNotification::query()->where('type', ExpenseSubmitted::class)->get();

    expect($rows->pluck('notifiable_id')->sort()->values()->all())->toBe(collect([$b->admin->id, $b->aaravUser->id, $b->kabirUser->id])->sort()->values()->all())
        ->and($rows->pluck('company_id')->unique()->all())->toBe([$b->company->id])
        ->and($rows->pluck('category')->unique()->all())->toBe(['approvals'])
        ->and($rows->first()->data)->toMatchArray([
            'title' => 'Rohan Desai added an expense',
            'body' => 'Uber to Asha Retail · ₹640 · Travel',
            'kind' => 'expense.submitted',
            'subject' => ['type' => 'expense', 'id' => $expense->id],
            'tone' => 'lime',
        ]);
});

test('approving or rejecting tells the submitter, with the reason; deciding your own sends nothing', function () {
    $b = notifBooks();
    Notification::fake();

    $this->actingAs($b->rohanUser)->post(route('expenses.store'), notifExpense($b));
    $first = Expense::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    $this->actingAs($b->rohanUser)->post(route('expenses.store'), notifExpense($b, ['description' => 'Team lunch']));
    $second = Expense::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

    $this->actingAs($b->kabirUser)->post(route('expenses.approve', $first))->assertRedirect();
    $this->actingAs($b->kabirUser)->post(route('expenses.reject', $second), ['reason' => 'Not a business lunch'])->assertRedirect();

    Notification::assertSentTo($b->rohanUser, ExpenseDecided::class, fn (ExpenseDecided $n, array $c, object $u) => $n->toArray($u)['title'] === 'Kabir Shah approved your expense');
    Notification::assertSentTo($b->rohanUser, ExpenseDecided::class, fn (ExpenseDecided $n, array $c, object $u) => $n->toArray($u)['title'] === 'Kabir Shah rejected your expense'
        && str_contains($n->toArray($u)['body'], '“Not a business lunch”') && $n->toArray($u)['tone'] === 'neg');

    // The Admin submits and approves their own: no one is told it was approved.
    $this->actingAs($b->admin)->post(route('expenses.store'), notifExpense($b, ['description' => 'Domain renewal']));
    $own = Expense::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    $this->actingAs($b->admin)->post(route('expenses.approve', $own))->assertRedirect();
    Notification::assertNotSentTo($b->admin, ExpenseDecided::class);
    Notification::assertNotSentTo($b->admin, ExpenseSubmitted::class, fn (ExpenseSubmitted $n) => $n->subject === ['type' => 'expense', 'id' => $own->id]);
});

test('channels follow the preference: email off keeps the bell; a failed payment is always emailed', function () {
    $b = notifBooks();
    $b->kabirUser->forceFill(['notification_preferences' => ['expense.submitted' => ['mail' => false, 'database' => true], 'billing.payment_failed' => ['mail' => false, 'database' => false]]])->save();
    Notification::fake();

    $this->actingAs($b->rohanUser)->post(route('expenses.store'), notifExpense($b));

    Notification::assertSentTo($b->kabirUser, ExpenseSubmitted::class, fn ($n, array $channels) => $channels === ['database']);
    Notification::assertSentTo($b->aaravUser, ExpenseSubmitted::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
    expect($b->kabirUser->fresh()->wantsNotification('billing.payment_failed', 'mail'))->toBeTrue()
        ->and($b->kabirUser->fresh()->wantsNotification('billing.payment_failed', 'database'))->toBeFalse();
});

test('accepting an invite tells whoever sent it', function () {
    $company = Company::factory()->create(['name' => 'Northstar Studio']);
    $admin = userWithRole(CompanyRole::Admin, $company, ['name' => 'Aarav Mehta']);
    $invite = app(IssueInvite::class)->email($company, $admin, 'meera@example.test', CompanyRole::Partner);
    $meera = User::factory()->create(['name' => 'Meera Iyer', 'email' => 'meera@example.test']);
    Notification::fake();

    app(AcceptInvite::class)->handle($invite, $meera);

    Notification::assertSentTo($admin, InviteAccepted::class, fn (InviteAccepted $n, array $c, object $u) => $n->toArray($u)['title'] === 'Meera Iyer joined Northstar Studio'
        && $n->toArray($u)['company_id'] === $company->id);
});

test('the monthly salary run tells the people who manage the team', function () {
    $b = notifBooks();
    $member = Member::factory()->create(['company_id' => $b->company->id, 'partner_id' => $b->aarav->id, 'name' => 'Dev Patel', 'cost_type' => CostType::FixedMonthly, 'rate_paise' => 40_000_00]);
    Notification::fake();

    $this->artisan('coffer:salaries', ['--month' => '2026-10', '--company' => $b->company->id])->assertSuccessful();

    Notification::assertSentTo($b->admin, SalaryRunDone::class, fn (SalaryRunDone $n, array $c, object $u) => $n->toArray($u)['title'] === 'Salaries for October 2026 are posted');
    Notification::assertNotSentTo([$b->aaravUser, $b->rohanUser, $b->viewer], SalaryRunDone::class);

    // Nothing new to post: nobody is told again.
    Notification::fake();
    $this->artisan('coffer:salaries', ['--month' => '2026-10', '--company' => $b->company->id])->assertSuccessful();
    Notification::assertNothingSent();
    expect($member->exists)->toBeTrue();
});

test('a failed timesheet import tells whoever started it', function () {
    $b = notifBooks();
    $import = TimesheetImport::query()->create([
        'company_id' => $b->company->id, 'user_id' => $b->admin->id, 'name' => 'october.csv', 'path' => 'x.csv', 'size' => 10, 'rows' => 3,
        'header' => ['a'], 'status' => 'queued',
    ]);
    Notification::fake();

    (new CommitTimesheetImport($import->id))->failed(new RuntimeException('boom'));

    Notification::assertSentTo($b->admin, ImportFinished::class, fn (ImportFinished $n, array $c, object $u) => $n->toArray($u)['title'] === 'Your timesheet import failed'
        && $n->toArray($u)['tone'] === 'neg');
});

test('closing a fiscal year tells the partners, not the person who closed it', function () {
    CarbonImmutable::setTestNow('2027-05-10 10:00:00');
    $b = notifBooks();
    $year = FiscalYear::query()->withoutGlobalScopes()->firstOrCreate(
        ['company_id' => $b->company->id, 'starts_on' => '2026-04-01'],
        ['ends_on' => '2027-03-31', 'label' => 'FY 2026-27'],
    );
    Notification::fake();

    $this->actingAs($b->admin)->post(route('settings.company.close', $year), ['confirm' => 'CLOSE FY 2026-27'])->assertRedirect();

    Notification::assertSentTo([$b->aaravUser, $b->kabirUser], FiscalYearClosed::class, fn (FiscalYearClosed $n, array $c, object $u) => $n->toArray($u)['title'] === 'FY 2026-27 is closed');
    Notification::assertNotSentTo([$b->admin, $b->rohanUser, $b->viewer], FiscalYearClosed::class);
});

test('reminders: a settlement due over the threshold, a year waiting to close, a trial ending — each at most once per period', function () {
    CarbonImmutable::setTestNow('2026-11-15 08:30:00');
    $b = notifBooks();
    $b->company->forceFill(['created_at' => '2025-06-01 00:00:00'])->save();
    // Vertex Labs collects ₹1,00,000 the JV earned; Bluefin is owed half.
    app(Postings::class)->incomeToAccount($b->company, fyDay(30), 1_00_000_00, category($b->company, 'income', 'Project'), $b->vertexAccount);

    $workspace = $b->company->workspace;
    $workspace->forceFill(['plan_id' => Plan::query()->where('key', 'growth')->value('id'), 'trial_ends_at' => now()->addDays(3)])->save();
    Notification::fake();

    $this->artisan('coffer:reminders')->assertSuccessful();

    Notification::assertSentTo([$b->admin, $b->aaravUser, $b->kabirUser], SettlementDue::class, fn (SettlementDue $n, array $c, object $u) => $n->toArray($u)['title'] === 'Settlement due: ₹50,000'
        && $n->toArray($u)['body'] === 'Vertex Labs owes Bluefin Infotech for Apr – Nov');
    Notification::assertNotSentTo([$b->rohanUser, $b->viewer], SettlementDue::class);
    Notification::assertSentTo($b->admin, FiscalYearReminder::class, fn (FiscalYearReminder $n, array $c, object $u) => $n->toArray($u)['title'] === 'FY 2025-26 is ready to close');
    Notification::assertSentTo($workspace->owner, TrialEnding::class, fn (TrialEnding $n, array $c, object $u) => $n->toArray($u)['title'] === 'Your Coffer trial ends in 3 days'
        && $n->toArray($u)['company_id'] === null);

    Notification::fake();
    $this->artisan('coffer:reminders')->assertSuccessful();
    Notification::assertNotSentTo($b->admin, SettlementDue::class);
    Notification::assertNotSentTo($b->admin, FiscalYearReminder::class);
});

test('asking for a page goes to the Admins’ bell as well as their inbox', function () {
    $company = Company::factory()->create();
    $admin = userWithRole(CompanyRole::Admin, $company);
    $viewer = userWithRole(CompanyRole::Viewer, $company, ['name' => 'Ledger Viewer']);

    $this->actingAs($viewer)->post(route('access-requests.store'), ['page' => 'income', 'reason' => 'Year-end audit'])->assertRedirect();

    $row = DatabaseNotification::query()->where('type', AccessRequested::class)->sole();
    expect($row->notifiable_id)->toBe($admin->id)
        ->and($row->category)->toBe('workspace')
        ->and($row->data['title'])->toBe('Ledger Viewer asked to open Income')
        ->and($row->data['body'])->toContain('“Year-end audit”');
});

test('notification emails use the brand template with the Indian-locale footer', function () {
    $b = notifBooks();
    $expense = app(CurrentCompany::class)->run($b->company, function () use ($b) {
        $this->actingAs($b->rohanUser)->post(route('expenses.store'), notifExpense($b));

        return Expense::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->load('category');
    });

    $html = (string) ExpenseSubmitted::for($expense, 'Rohan Desai')->toMail($b->kabirUser)->render();

    expect($html)->toContain('Rohan Desai added an expense')
        ->toContain('Uber to Asha Retail · ₹640 · Travel')
        ->toContain('Review the expense')
        ->toContain('brand-disc')
        ->toContain('IST')
        ->toContain('Indian grouping')
        ->toContain('Made in India')
        ->toContain('#2d2e32'); // graphite button, inlined from the Coffer theme
});

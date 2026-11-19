<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Member;
use App\Models\User;
use App\Notifications\Events\ExpenseSubmitted;
use App\Notifications\Events\PaymentFailed;
use App\Notifications\Events\SettlementDue;
use App\Notifications\Vault\VaultItemExpiring;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The bell's panel, /notifications and Settings › Notifications (spec §19, §16, D78):
 * your own rows in the current company (plus workspace-wide billing ones), filters,
 * pages, read state and inline approval through the Expenses routes.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Stores a notification for `$user` the way the database channel does. */
function notifyRow(User $user, string $class, ?int $companyId, string $title, string $category, ?array $subject = null, ?CarbonImmutable $at = null): DatabaseNotification
{
    /** @var DatabaseNotification */
    return DatabaseNotification::query()->forceCreate([
        'id' => (string) str()->uuid(),
        'type' => $class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'company_id' => $companyId,
        'category' => $category,
        'data' => ['title' => $title, 'body' => 'Body', 'url' => '/x', 'icon' => 'receipt', 'kind' => 'k', 'category' => $category, 'company_id' => $companyId, 'tone' => 'plain', 'link' => 'Open', 'subject' => $subject],
        'created_at' => $at ?? now(),
        'updated_at' => $at ?? now(),
    ]);
}

test('everyone in a company has the page and the panel; only their own rows, in this company, from the last 30 days', function (CompanyRole $role) {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $user = userWithRole($role, $company);
    $someoneElse = userWithRole(CompanyRole::Admin, $company);

    notifyRow($user, SettlementDue::class, $company->id, 'Mine here', 'money');
    notifyRow($user, PaymentFailed::class, null, 'Workspace-wide', 'workspace', null, now()->subHour());
    notifyRow($user, SettlementDue::class, $other->id, 'Other company', 'money');
    notifyRow($someoneElse, SettlementDue::class, $company->id, 'Not mine', 'money');
    notifyRow($user, SettlementDue::class, $company->id, 'Too old', 'money', null, now()->subDays(31));

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('notifications/index')
            ->where('counts.all', 2)
            ->where('counts.unread', 2)
            ->where('counts.money', 1)
            ->where('counts.workspace', 1)
            ->has('items', 2)
            ->where('items.0.title', 'Mine here')
            ->where('items.1.title', 'Workspace-wide')
            ->has('can.pages'));

    $this->actingAs($user)->getJson(route('notifications.panel'))
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('counts.unread', 2);
})->with([CompanyRole::Admin, CompanyRole::Partner, CompanyRole::Member, CompanyRole::Viewer]);

test('guests and people without a company are sent away', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
    $this->getJson(route('notifications.panel'))->assertUnauthorized();
});

test('filters, pages of 20 and an invalid filter', function () {
    $company = Company::factory()->create();
    $user = userWithRole(CompanyRole::Admin, $company);

    foreach (range(1, 22) as $i) {
        notifyRow($user, VaultItemExpiring::class, $company->id, "Expiring {$i}", 'security', null, now()->subMinutes($i));
    }

    notifyRow($user, SettlementDue::class, $company->id, 'Settle', 'money')->markAsRead();

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->has('items', 20)->where('pagination.pageCount', 2)->where('pagination.total', 23));
    $this->actingAs($user)->get(route('notifications.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('items', 3));
    $this->actingAs($user)->get(route('notifications.index', ['filter' => 'money']))
        ->assertInertia(fn (Assert $page) => $page->where('filter', 'money')->has('items', 1)->where('items.0.unread', false));
    $this->actingAs($user)->get(route('notifications.index', ['filter' => 'unread']))
        ->assertInertia(fn (Assert $page) => $page->where('pagination.total', 22));
    $this->actingAs($user)->getJson(route('notifications.panel', ['filter' => 'nope']))->assertUnprocessable();
});

test('marking one read, then all read; someone else’s row is a 404', function () {
    $company = Company::factory()->create();
    $user = userWithRole(CompanyRole::Partner, $company);
    $other = userWithRole(CompanyRole::Admin, $company);
    $a = notifyRow($user, SettlementDue::class, $company->id, 'A', 'money');
    notifyRow($user, VaultItemExpiring::class, $company->id, 'B', 'security');
    notifyRow($user, VaultItemExpiring::class, $company->id, 'C', 'security');
    $theirs = notifyRow($other, SettlementDue::class, $company->id, 'Theirs', 'money');

    $this->actingAs($user)->postJson(route('notifications.read', $a->id))->assertOk()->assertJson(['unread' => 2]);
    $this->actingAs($user)->postJson(route('notifications.read', $theirs->id))->assertNotFound();
    expect($theirs->fresh()->read_at)->toBeNull();

    $this->actingAs($user)->postJson(route('notifications.read-all', ['filter' => 'security']))->assertOk()->assertJson(['unread' => 0]);
    $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 0));
});

test('the shared prop holds only the unread count; the rows load with the panel', function () {
    $company = Company::factory()->create();
    $user = userWithRole(CompanyRole::Admin, $company);
    notifyRow($user, SettlementDue::class, $company->id, 'One', 'money');

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications', ['unread' => 1])->missing('items'));
});

test('an expense waiting offers Approve to someone who may decide it; afterwards the row says who did', function () {
    $books = jvBooks();
    $kabir = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Kabir Shah']);
    $rohanUser = userWithRole(CompanyRole::Member, $books->company, ['name' => 'Rohan Desai']);
    $rohan = Member::factory()->create(['partner_id' => $books->aarav->id, 'user_id' => $rohanUser->id, 'rate_paise' => 500_00]);

    $this->actingAs($rohanUser)->post(route('expenses.store'), [
        'description' => 'Uber to Asha Retail', 'amount_paise' => 640_00, 'spent_on' => '2026-10-12',
        'category_id' => category($books->company, 'expense', 'Travel')->id, 'payer_mode' => 'full',
        'payers' => [['type' => 'member', 'member_id' => $rohan->id, 'account_id' => null, 'amount_paise' => 640_00]],
    ])->assertSessionHasNoErrors();
    $expense = Expense::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

    $this->actingAs($kabir)->getJson(route('notifications.panel'))
        ->assertJsonPath('items.0.title', 'Rohan Desai added an expense')
        ->assertJsonPath('items.0.expenseId', $expense->id)
        ->assertJsonPath('items.0.canDecide', true)
        ->assertJsonPath('items.0.done', null);

    // The panel's Approve is the Expenses route, with its own checks.
    $this->actingAs($kabir)->post(route('expenses.approve', $expense))->assertRedirect();

    $this->actingAs($kabir)->getJson(route('notifications.panel'))
        ->assertJsonPath('items.0.canDecide', false)
        ->assertJsonPath('items.0.done', ['label' => 'Approved by you', 'tone' => 'pos']);

    // A Member never sees an Approve button (they can't decide), and it's not their row anyway.
    expect(DatabaseNotification::query()->where('type', ExpenseSubmitted::class)->where('notifiable_id', $rohanUser->id)->exists())->toBeFalse();
});

test('Settings › Notifications: everyone may open it; choices save per event and channel; required channels stay on', function (CompanyRole $role) {
    $company = Company::factory()->create();
    $user = userWithRole($role, $company);

    $this->actingAs($user)->get(route('settings.notifications'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/notifications')
            ->has('groups', 4)
            ->where('groups.0.key', 'approvals')
            ->where('groups.0.events.0.key', 'expense.submitted')
            ->where('groups.0.events.0.mail', true)
            ->where('groups.3.events.5.key', 'billing.payment_failed')
            ->where('groups.3.events.5.required', ['mail'])
            ->has('can.pages'));

    $this->actingAs($user)->put(route('settings.notifications.update'), ['preferences' => [
        'expense.submitted' => ['mail' => false, 'database' => true],
        'billing.payment_failed' => ['mail' => false],
        'not.an.event' => ['mail' => false],
    ]])->assertRedirect()->assertInertiaFlash('toast.message', 'Notification settings saved');

    $prefs = $user->fresh()->notification_preferences;
    expect($prefs['expense.submitted'])->toBe(['mail' => false, 'database' => true])
        ->and($prefs['billing.payment_failed'])->toBe(['mail' => true])
        ->and($prefs)->not->toHaveKey('not.an.event');
})->with([CompanyRole::Admin, CompanyRole::Member, CompanyRole::Viewer]);

test('Settings › Notifications validation', function () {
    $user = userWithRole(CompanyRole::Admin);

    $this->actingAs($user)->put(route('settings.notifications.update'), [])->assertSessionHasErrors('preferences');
    $this->actingAs($user)->put(route('settings.notifications.update'), ['preferences' => ['expense.submitted' => ['mail' => 'often']]])
        ->assertSessionHasErrors('preferences.expense.submitted.mail');
});

test('old notifications are pruned after 30 days', function () {
    $company = Company::factory()->create();
    $user = userWithRole(CompanyRole::Admin, $company);
    notifyRow($user, SettlementDue::class, $company->id, 'New', 'money');
    notifyRow($user, SettlementDue::class, $company->id, 'Old', 'money', null, now()->subDays(31));

    $this->artisan('coffer:notifications-prune')->expectsOutput('Deleted 1 old notifications.')->assertSuccessful();
    expect(DatabaseNotification::query()->count())->toBe(1);
});

test('several approvals load without lazy loads; counts are what still needs a decision; deleted expenses say so (P5, F8, X9, N1)', function () {
    $books = jvBooks();
    $kabir = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Kabir Shah']);
    $rohanUser = userWithRole(CompanyRole::Member, $books->company, ['name' => 'Rohan Desai']);
    $rohan = Member::factory()->create(['partner_id' => $books->aarav->id, 'user_id' => $rohanUser->id, 'rate_paise' => 500_00]);

    foreach (['Uber to Asha Retail', 'Lunch with Kiran Exports', 'Printer ink'] as $description) {
        $this->actingAs($rohanUser)->post(route('expenses.store'), [
            'description' => $description, 'amount_paise' => 640_00, 'spent_on' => '2026-10-12',
            'category_id' => category($books->company, 'expense', 'Travel')->id, 'payer_mode' => 'full',
            'payers' => [['type' => 'member', 'member_id' => $rohan->id, 'account_id' => null, 'amount_paise' => 640_00]],
        ])->assertSessionHasNoErrors();
    }
    [$first, $second, $third] = Expense::query()->withoutGlobalScopes()->orderBy('id')->get()->all();

    $this->actingAs($kabir)->get(route('notifications.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('counts.actionable', 3)->where('counts.approvals', 3));

    $this->actingAs($kabir)->post(route('expenses.approve', $first))->assertRedirect();
    $this->actingAs($kabir)->post(route('expenses.reject', $second), ['reason' => 'Not a JV cost'])->assertRedirect();
    DatabaseNotification::query()->where('notifiable_id', $kabir->id)->update(['read_at' => now()]);

    // Read but still waiting: not "all caught up".
    $this->actingAs($kabir)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->where('counts.unread', 0)->where('counts.actionable', 1)->where('counts.approvals', 1));

    $this->actingAs($rohanUser)->delete(route('expenses.destroy', $third))->assertRedirect();

    $rows = $this->actingAs($kabir)->getJson(route('notifications.panel'))->assertOk()->json('items');
    $gone = collect($rows)->first(fn (array $r) => str_contains($r['body'], 'This expense was deleted.'));
    expect($gone)->not->toBeNull()
        ->and($gone['url'])->toBe('')
        ->and($gone['canDecide'])->toBeFalse();
});

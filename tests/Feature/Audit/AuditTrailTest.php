<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\LedgerPoster;
use App\Ledger\OpeningBalance;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Expense;
use App\Models\PlatformAdmin;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditTrail;
use App\Services\Platform\Impersonator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| The audit trail (D153, Companies (Accounts) Rules 3(1)): every money change is logged
| with who, when, where and before/after; the log is append-only in the database and
| hash-chained, so `coffer:audit-verify` finds any row changed behind the app's back.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return list<AuditEvent> */
function auditOf(Company $company, ?string $action = null): array
{
    return array_values(AuditEvent::query()->withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->when($action, fn ($q) => $q->where('action', $action))
        ->orderBy('seq')->get()->all());
}

/** A JV with a partner who submits and another who approves. */
function auditBooks(): object
{
    $books = jvBooks();
    $books->kabirUser = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Kabir Shah']);
    $books->aaravUser = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Aarav Mehta']);
    $books->kabir->update(['user_id' => $books->kabirUser->id]);
    $books->aarav->update(['user_id' => $books->aaravUser->id]);

    return $books;
}

/** @return array<string, mixed> */
function auditExpense(object $books, array $overrides = []): array
{
    return $overrides + [
        'description' => 'Client visit — Jaipur',
        'amount_paise' => 9_800_00,
        'spent_on' => '2026-10-12',
        'category_id' => category($books->company, 'expense', 'Travel')->id,
        'payer_mode' => 'full',
        'notes' => 'Met the Kiran Exports team',
        'payers' => [['type' => 'partner', 'partner_id' => $books->kabir->id, 'account_id' => null, 'amount_paise' => 9_800_00]],
    ];
}

test('a submitted, edited and approved expense is in the trail with who, where and before/after', function () {
    $books = auditBooks();

    $this->actingAs($books->kabirUser)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'AuditTest/1.0'])
        ->post(route('expenses.store'), auditExpense($books))->assertSessionHasNoErrors();
    $expense = Expense::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->latest('id')->firstOrFail();

    $created = collect(auditOf($books->company, 'created'))->firstWhere('subject_label', 'Expense: Client visit — Jaipur');
    expect($created)->not->toBeNull()
        ->and($created->user_id)->toBe($books->kabirUser->id)
        ->and($created->actor)->toBe('user')
        ->and($created->ip)->toBe('203.0.113.7')
        ->and($created->user_agent)->toBe('AuditTest/1.0')
        ->and($created->changeSet()['amount_paise'])->toBe(['to' => 9_800_00]);

    $this->actingAs($books->kabirUser)->put(route('expenses.update', $expense), auditExpense($books, ['amount_paise' => 9_900_00, 'payers' => [
        ['type' => 'partner', 'partner_id' => $books->kabir->id, 'account_id' => null, 'amount_paise' => 9_900_00],
    ]]))->assertSessionHasNoErrors();

    $edit = collect(auditOf($books->company, 'updated'))->first(fn (AuditEvent $e) => $e->subject_type === $expense->getMorphClass() && isset($e->changeSet()['amount_paise']));
    expect($edit->changeSet()['amount_paise'])->toBe(['from' => 9_800_00, 'to' => 9_900_00]);

    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();

    $approved = collect(auditOf($books->company, 'approved'))->firstWhere('subject_id', $expense->id);
    expect($approved)->not->toBeNull()
        ->and($approved->user_id)->toBe($books->aaravUser->id)
        ->and($approved->changeSet()['status'])->toBe(['from' => 'pending', 'to' => 'approved']);

    // Approving posted an entry, which is in the trail with its lines.
    $posted = collect(auditOf($books->company, 'posted'))->last();
    expect($posted->changeSet()['lines']['to'])->toHaveCount(2)
        ->and($posted->user_id)->toBe($books->aaravUser->id);
});

test('sealed fields are logged as changed, never with their value', function () {
    $books = auditBooks();

    $this->actingAs($books->kabirUser)->post(route('expenses.store'), auditExpense($books))->assertSessionHasNoErrors();

    $raw = DB::table('audit_events')->where('company_id', $books->company->id)->pluck('changes')->implode("\n");
    expect($raw)->not->toContain('Met the Kiran Exports team');

    $created = collect(auditOf($books->company, 'created'))->firstWhere('subject_label', 'Expense: Client visit — Jaipur');
    expect($created->changeSet()['notes'])->toBe(['changed' => true]);
});

test('posting and reversing are both recorded', function () {
    $books = singleBooks(openingPaise: 1_00_000_00);
    $entry = app(OpeningBalance::class)->post($books->company, fyDay());

    app(LedgerPoster::class)->reverse($entry);

    $reversed = auditOf($books->company, 'reversed');
    expect($reversed)->toHaveCount(1)
        ->and($reversed[0]->subject_id)->toBe($entry->id)
        ->and($reversed[0]->actor)->toBe('console');
    expect(auditOf($books->company, 'posted'))->toHaveCount(2);
});

test('an impersonating platform admin is named on what they change', function () {
    $books = auditBooks();
    $admin = PlatformAdmin::query()->create(['name' => 'Coffer Support', 'email' => 'support@coffer.test', 'password' => 'secret-password-1']);

    $this->actingAs($books->kabirUser)
        ->withSession([Impersonator::SESSION => ['id' => 1, 'admin_id' => $admin->id, 'admin_name' => 'Coffer Support', 'user_id' => $books->kabirUser->id, 'workspace_id' => null, 'reason' => 'Ticket 12']])
        ->post(route('expenses.store'), auditExpense($books));

    $created = collect(auditOf($books->company, 'created'))->firstWhere('subject_label', 'Expense: Client visit — Jaipur');
    expect($created->impersonator)->toBe('Coffer Support')
        ->and($created->user_id)->toBe($books->kabirUser->id);
});

test('each company has its own unbroken chain, and verify passes', function () {
    $one = singleBooks(openingPaise: 50_000_00);
    $two = singleBooks(openingPaise: 70_000_00);
    app(OpeningBalance::class)->post($one->company, fyDay());
    app(OpeningBalance::class)->post($two->company, fyDay());

    foreach ([$one->company, $two->company] as $company) {
        $seqs = array_map(fn (AuditEvent $e) => $e->seq, auditOf($company));
        expect($seqs)->toBe(range(1, count($seqs)))
            ->and(app(AuditChain::class)->verify($company->id))->toBeNull();
    }

    $this->artisan('coffer:audit-verify')->assertSuccessful();
});

test('changing one audit row makes the verify command fail and name it', function () {
    $books = singleBooks(openingPaise: 50_000_00);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $target = auditOf($books->company)[1];

    tamperWithLedger(fn () => DB::table('audit_events')->where('id', $target->id)->update(['changes' => '{"amount_paise":{"to":1}}']));

    expect(app(AuditChain::class)->verify($books->company->id))->toBe(['seq' => $target->seq, 'problem' => 'was changed after it was written']);
    $this->artisan('coffer:audit-verify', ['--company' => $books->company->id])
        ->expectsOutputToContain("Event {$target->seq} was changed after it was written.")
        ->assertFailed();
});

test('a removed audit row is found too', function () {
    $books = singleBooks(openingPaise: 50_000_00);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $events = auditOf($books->company);
    $middle = $events[intdiv(count($events), 2)];

    tamperWithLedger(fn () => DB::table('audit_events')->where('id', $middle->id)->delete());

    expect(app(AuditChain::class)->verify($books->company->id))->toBe(['seq' => $middle->seq, 'problem' => 'is missing']);
});

test('a removed last row is found by the head', function () {
    $books = singleBooks(openingPaise: 50_000_00);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $last = collect(auditOf($books->company))->last();

    tamperWithLedger(fn () => DB::table('audit_events')->where('id', $last->id)->delete());

    expect(app(AuditChain::class)->verify($books->company->id))->toBe(['seq' => $last->seq, 'problem' => 'is missing (the trail ends early)']);
});

test('the database refuses to change or delete audit rows', function () {
    $books = singleBooks(openingPaise: 50_000_00);
    $event = auditOf($books->company)[0];

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->update(['action' => 'nothing']))
        ->toThrow(QueryException::class, 'audit trail is append-only');
    expect(fn () => DB::table('audit_events')->where('id', $event->id)->delete())
        ->toThrow(QueryException::class, 'audit trail is append-only');
});

test('the model refuses to write or change audit rows itself', function () {
    $books = singleBooks();
    $event = auditOf($books->company)[0];

    expect(fn () => $event->forceFill(['action' => 'x'])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(fn () => AuditEvent::query()->forceCreate(['company_id' => $books->company->id]))->toThrow(LogicException::class);
});

test('a hash made under a previous APP_KEY still verifies', function () {
    $books = singleBooks(openingPaise: 50_000_00);
    $old = config('app.key');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
    app(AuditTrail::class)->record($books->company->id, 'note', label: 'After the key change');

    expect(app(AuditChain::class)->verify($books->company->id))->toBeNull();

    config(['app.previous_keys' => []]);
    expect(app(AuditChain::class)->verify($books->company->id))->not->toBeNull();
});

test('permission matrix changes are in the trail', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->put(route('settings.roles.update'), ['matrix' => [
        'partner' => ['page.dashboard'],
        'member' => [],
        'viewer' => [],
    ]])->assertSessionHasNoErrors();

    $changed = auditOf($books->company, 'permissions changed');
    expect($changed)->toHaveCount(1)
        ->and($changed[0]->changeSet()['partner']['to'])->toBe(['page.dashboard']);
});

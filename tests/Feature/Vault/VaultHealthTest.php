<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Jobs\CheckVaultBreaches;
use App\Models\Company;
use App\Models\VaultAuditEvent;
use App\Models\VaultItem;
use App\Notifications\Vault\VaultBreachFound;
use App\Notifications\Vault\VaultItemExpiring;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Weak + old MySQL, Netlify and WordPress sharing a password, a token expiring in 9 days. */
function healthBooks(): object
{
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $mysql = vaultItemFor($company, ['password' => 'saarthi2024'], ['name' => 'MySQL — production database', 'strength' => 0]);
    DB::table('vault_items')->where('id', $mysql->id)->update(['rotated_at' => '2026-04-09 10:00:00']);
    $netlify = vaultItemFor($company, ['password' => 'demo-Kiran@2025-x'], ['name' => 'Netlify — marketing site', 'strength' => 3]);
    $wordpress = vaultItemFor($company, ['password' => 'demo-Kiran@2025-x'], ['name' => 'WordPress — Kiran Exports', 'strength' => 3]);
    $vercel = vaultItemFor($company, ['key' => 'demo-vcp-token'], ['name' => 'Vercel — team API token', 'type' => 'api_key', 'provider' => 'vercel', 'fields' => [], 'expires_at' => '2026-11-24']);

    return (object) compact('company', 'admin', 'mysql', 'netlify', 'wordpress', 'vercel');
}

test('the health page scores the visible items and lists what to fix with Rotate', function () {
    $b = healthBooks();

    $this->actingAs($b->admin)->withSession(vaultUnlocked())->get(route('vault.health', ['filter' => 'reused']))
        ->assertInertia(fn (Assert $page) => $page->component('vault/health')
            ->where('filter', 'reused')
            ->where('report.counts.weak', 1)
            ->where('report.counts.reused', 2)
            ->where('report.counts.old', 1)
            ->where('report.counts.expiring', 1)
            ->where('report.counts.no2fa', 3)
            // 100 − 4 (weak) − 10 (reused) − 2 (old) − 2 (expiring) − 3 (no 2FA)
            ->where('report.score', 79)
            ->where('report.biggest', 'reused')
            ->where('report.lists.0.key', 'weak')
            ->where('report.lists.0.rows.0.name', 'MySQL — production database')
            ->where('report.lists.0.rows.0.canFix', true)
            ->where('report.lists.1.rows.0.detail', 'Same password as WordPress — Kiran Exports')
            ->where('report.lists.2.rows.0.detail', 'Last changed 9 Apr 2026 · 220 days ago')
            ->where('report.lists.4.rows.0.detail', 'Expires on 24 Nov 2026 · in 9 days'));

    // Rotating the weak password raises the score.
    $this->actingAs($b->admin)->withSession(vaultUnlocked())->put(route('vault.items.update', $b->mysql), [
        'vault_id' => $b->mysql->vault_id, 'type' => 'login', 'name' => $b->mysql->name, 'strength' => 4,
        'fields' => ['url' => 'https://github.com/login', 'username' => 'vertexlabs-admin'], 'secrets' => ['password' => 'demo-New-Long-Passphrase-2026!'],
    ])->assertRedirect();

    $this->actingAs($b->admin)->withSession(vaultUnlocked())->get(route('vault.health'))
        ->assertInertia(fn (Assert $page) => $page->where('report.counts.weak', 0)->where('report.counts.old', 0)->where('report.score', 85));
});

test('people see only their items; viewers are denied and a locked session gets no report', function () {
    $b = healthBooks();
    $member = vaultUser(CompanyRole::Member, $b->company);
    vaultGrant($member, $b->netlify, VaultAccessLevel::View);
    $viewer = vaultUser(CompanyRole::Viewer, $b->company);

    $this->actingAs($member)->withSession(vaultUnlocked())->get(route('vault.health'))
        ->assertInertia(fn (Assert $page) => $page->where('report.total', 1)
            ->where('report.counts.reused', 0)
            ->where('report.lists.5.rows.0.name', 'Netlify — marketing site')
            ->where('report.lists.5.rows.0.canFix', false));

    $this->flushSession();
    $this->actingAs($b->admin)->get(route('vault.health'))->assertInertia(fn (Assert $page) => $page->where('report', null));
    $this->actingAs($viewer)->get(route('vault.health'))->assertForbidden();
});

test('the report exports as CSV with names only, audited, behind the lock', function () {
    $b = healthBooks();

    $csv = $this->actingAs($b->admin)->withSession(vaultUnlocked())->get(route('vault.health.export'))->assertOk();
    $body = $csv->streamedContent();
    expect($body)->toContain('"Weak passwords","MySQL — production database"')
        ->not->toContain('saarthi2024')
        ->not->toContain('demo-Kiran@2025-x');
    expect(VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'exported')->sole()->meta)->toBe(['what' => 'health report', 'rows' => 8]);

    $this->flushSession();
    $this->actingAs($b->admin)->get(route('vault.health.export'))->assertRedirect();
});

test('the breach check sends only a 5-character SHA-1 prefix with padding, stores the flag and notifies', function () {
    config(['vault.hibp_enabled' => true]);
    Notification::fake();
    $b = healthBooks();
    $hash = strtoupper(sha1('saarthi2024'));
    $other = strtoupper(sha1('demo-Kiran@2025-x'));

    Http::fake([
        'api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":42\r\n0000000000000000000000000000000000A:0", 200),
        'api.pwnedpasswords.com/range/*' => Http::response('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:0', 200),
    ]);

    app(CurrentCompany::class)->set(null);
    CheckVaultBreaches::dispatchSync($b->company->id);

    Http::assertSent(function (HttpRequest $request) use ($hash) {
        return $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)
            && $request->hasHeader('Add-Padding', 'true');
    });
    Http::assertSentCount(2);
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url().$request->body(), substr($hash, 5)) || str_contains($request->url(), substr($other, 5)));

    $mysql = VaultItem::query()->withoutGlobalScopes()->find($b->mysql->id);
    expect($mysql->breached)->toBeTrue()->and($mysql->breach_checked_at)->not->toBeNull()
        ->and(VaultItem::query()->withoutGlobalScopes()->find($b->netlify->id)->breached)->toBeFalse();
    Notification::assertSentTo($b->admin, VaultBreachFound::class, fn (VaultBreachFound $n, array $c, object $u) => $n->toArray($u)['title'] === 'MySQL — production database was found in a breach');

    // The page shows it; nothing about the hash is stored anywhere.
    expect(json_encode(DB::table('vault_items')->get()))->not->toContain(substr($hash, 5));
});

test('breach checks stay off unless enabled, and Run check again queues ids only', function () {
    Http::fake();
    $b = healthBooks();

    CheckVaultBreaches::dispatchSync($b->company->id);
    Http::assertNothingSent();

    $this->actingAs($b->admin)->withSession(vaultUnlocked())->post(route('vault.health.check'))
        ->assertRedirect()->assertInertiaFlash('toast.message', 'Breach checks are off for this company.');

    config(['vault.hibp_enabled' => true]);
    Queue::fake();
    $this->actingAs($b->admin)->withSession(vaultUnlocked())->post(route('vault.health.check'))->assertRedirect();
    Queue::assertPushed(CheckVaultBreaches::class, function (CheckVaultBreaches $job) use ($b) {
        expect(serialize($job))->not->toContain('saarthi2024');

        return $job->companyId === $b->company->id && count($job->itemIds ?? []) === 4;
    });

    $this->artisan('coffer:vault-breach-check')->expectsOutputToContain('Queued breach checks for')->assertSuccessful();
});

test('expiry alerts go out at 30, 7 and 1 days to people who can edit the item, once a day', function () {
    Notification::fake();
    $b = healthBooks();
    $editor = vaultUser(CompanyRole::Partner, $b->company);
    $user = vaultUser(CompanyRole::Member, $b->company);
    vaultGrant($editor, $b->vercel, VaultAccessLevel::Edit);
    vaultGrant($user, $b->vercel, VaultAccessLevel::Use);

    $this->artisan('coffer:vault-expiry-alerts')->expectsOutput('Sent 0 expiry alerts.')->assertSuccessful();

    CarbonImmutable::setTestNow('2026-11-17 08:00:00');
    $this->artisan('coffer:vault-expiry-alerts')->expectsOutput('Sent 2 expiry alerts.')->assertSuccessful();
    $this->artisan('coffer:vault-expiry-alerts')->expectsOutput('Sent 0 expiry alerts.')->assertSuccessful();

    Notification::assertSentTo([$b->admin, $editor], VaultItemExpiring::class, function (VaultItemExpiring $n, array $channels, object $u) use ($b) {
        $p = $n->toArray($u);

        return $p['title'] === 'Vercel — team API token expires in 7 days'
            && $p['kind'] === 'vault.expiring' && $p['icon'] === 'clock'
            && str_contains($p['url'], '/vault?item='.$b->vercel->id)
            && $channels === ['database', 'mail'];
    });
    Notification::assertNotSentTo($user, VaultItemExpiring::class);

    // Mail follows the person's preference.
    $b->admin->forceFill(['notification_preferences' => ['vault.expiring' => ['mail' => false]]])->save();
    CarbonImmutable::setTestNow('2026-11-23 08:00:00');
    $this->artisan('coffer:vault-expiry-alerts')->assertSuccessful();
    Notification::assertSentTo($b->admin, VaultItemExpiring::class, fn (VaultItemExpiring $n, array $channels) => $channels === ['database'] && str_contains($n->title, 'tomorrow'));
});

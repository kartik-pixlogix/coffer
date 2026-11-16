<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultAuditAction;
use App\Models\Company;
use App\Models\VaultAuditEvent;
use App\Services\Vault\VaultAudit;
use App\Support\CurrentCompany;
use App\Support\Permissions\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

const MAC_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
const ANDROID = 'Mozilla/5.0 (Linux; Android 15; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';

test('the audit log shows events with filters, KPIs, new devices and pagination', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $rohan = vaultUser(CompanyRole::Member, $company);
    $rohan->update(['name' => 'Rohan Desai']);
    $item = vaultItemFor($company, ['password' => 'demo-Audit-1!'], ['name' => 'MySQL — production database']);
    vaultGrant($rohan, $item, VaultAccessLevel::Use);

    // Real requests write the device and the "new device" flag.
    $this->actingAs($rohan)->withSession(vaultUnlocked())->withHeader('User-Agent', MAC_CHROME)
        ->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertOk();
    $this->actingAs($rohan)->withSession(vaultUnlocked())->withHeader('User-Agent', MAC_CHROME)
        ->postJson(route('vault.items.copy', $item), ['field' => 'password'])->assertOk();
    $this->actingAs($rohan)->withSession(vaultUnlocked())->withHeader('User-Agent', ANDROID)
        ->postJson(route('vault.items.copy', $item), ['field' => 'password'])->assertOk();

    $events = VaultAuditEvent::query()->withoutGlobalScopes()->whereIn('action', ['revealed', 'copied'])->orderBy('id')->get();
    expect($events->pluck('device')->all())->toBe(['Chrome on macOS', 'Chrome on macOS', 'Chrome on Android'])
        ->and($events->pluck('new_device')->all())->toBe([true, false, true]);

    // An old event outside the 7-day default range.
    DB::table('vault_audit_events')->insert([
        'company_id' => $company->id, 'user_id' => $admin->id, 'vault_item_id' => $item->id, 'item_name' => $item->name,
        'action' => 'viewed', 'created_at' => '2026-10-20 10:00:00',
    ]);

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.audit'))
        ->assertInertia(fn (Assert $page) => $page->component('vault/audit')
            ->where('filters.range', '7d')
            ->where('filters.from', '2026-11-09')
            ->where('log.kpis.reveals', 1)
            // Rohan's two devices, and the item's creator (a first event, from the test client).
            ->where('log.kpis.newDevices', 3)
            ->where('log.counts.copied', 2)
            ->where('log.rows.0.user', 'Rohan Desai')
            ->where('log.rows.0.label', 'Copied')
            ->where('log.rows.0.device', 'Chrome on Android')
            ->where('log.rows.0.newDevice', true)
            ->where('log.rows.0.item', 'MySQL — production database'));

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.audit', ['action' => 'revealed', 'user' => $rohan->id]))
        ->assertInertia(fn (Assert $page) => $page->where('log.total', 1)->where('log.rows.0.action', 'revealed'));

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.audit', ['q' => 'android']))
        ->assertInertia(fn (Assert $page) => $page->where('log.total', 1));

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.audit', ['range' => '30d', 'action' => 'viewed']))
        ->assertInertia(fn (Assert $page) => $page->where('log.rows.0.at', fn ($at) => str_starts_with($at, '2026-10-20')));

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.audit', ['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page->where('log.total', 1)->where('filters.from', '2026-10-01'));

    // 30 more events: two pages of 25.
    app(CurrentCompany::class)->run($company, function () use ($admin, $item) {
        foreach (range(1, 30) as $i) {
            app(VaultAudit::class)->record(VaultAuditAction::Viewed, $admin, $item);
        }
    });
    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.audit', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->where('log.page', 2)->where('log.pages', 2)->has('log.rows', 9));
});

test('the CSV export follows the filters, is audited, and needs the lock open', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Audit-2!'], ['name' => '=cmd|calc']);
    $this->actingAs($admin)->withSession(vaultUnlocked())->withHeader('User-Agent', MAC_CHROME)
        ->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertOk();

    $body = $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.audit.export', ['action' => 'revealed']))
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();

    expect($body)->toContain('Time,User,Action,Field,Item,"IP address",Device,"New device"')
        ->toContain("Revealed,password,'=cmd|calc,127.0.0.1,\"Chrome on macOS\",yes")
        ->not->toContain('demo-Audit-2!');

    $export = VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'exported')->sole();
    expect($export->meta)->toBe(['what' => 'audit log', 'rows' => 1, 'from' => '2026-11-09', 'to' => '2026-11-15']);

    $this->flushSession();
    $this->actingAs($admin)->get(route('vault.audit.export'))->assertRedirect();
});

test('only Admins, or roles given "Export and view audit log", open the audit log', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $partner = vaultUser(CompanyRole::Partner, $company);

    $this->actingAs($partner)->withSession(vaultUnlocked())->get(route('vault.audit'))->assertForbidden();
    $this->actingAs($partner)->withSession(vaultUnlocked())->get(route('vault.audit.export'))->assertForbidden();

    // The matrix grants it to partners.
    Role::query()->where('company_id', $company->id)->where('name', 'partner')->firstOrFail()->givePermissionTo(Catalog::actionPermission('vault.export'));

    $this->actingAs($partner)->withSession(vaultUnlocked())->get(route('vault.audit'))->assertOk();

    // A locked session sees the lock card, not the log.
    $this->flushSession();
    $this->actingAs($admin)->get(route('vault.audit'))->assertInertia(fn (Assert $page) => $page->where('log', null));
});

test('events older than 12 months are pruned and nothing newer is touched', function () {
    $company = Company::factory()->create();
    foreach (['2025-11-10 10:00:00', '2025-11-20 10:00:00', '2026-11-01 10:00:00'] as $at) {
        DB::table('vault_audit_events')->insert(['company_id' => $company->id, 'action' => 'viewed', 'created_at' => $at]);
    }

    $this->artisan('coffer:vault-audit-prune')->expectsOutput('Deleted 1 vault audit event older than 12 months.')->assertSuccessful();
    expect(DB::table('vault_audit_events')->count())->toBe(2);
});

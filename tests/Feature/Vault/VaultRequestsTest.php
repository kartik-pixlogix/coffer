<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultRequestStatus;
use App\Models\Company;
use App\Models\VaultAccessRequest;
use App\Models\VaultGrant;
use App\Notifications\Vault\VaultAccessDecided;
use App\Notifications\Vault\VaultAccessRequested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('a View user asks for Use on an item; Managers are notified; approving writes the grant and tells the requester', function () {
    Notification::fake();
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $manager = vaultUser(CompanyRole::Partner, $company);
    $rohan = vaultUser(CompanyRole::Member, $company);
    $rohan->update(['name' => 'Rohan Desai']);
    $item = vaultItemFor($company, ['password' => 'demo-Req-1!'], ['name' => 'AWS — root account']);
    vaultGrant($rohan, $item, VaultAccessLevel::View);
    vaultGrant($manager, $item, VaultAccessLevel::Manage);

    $this->actingAs($rohan)->post(route('vault.requests.store'), [
        'item_id' => $item->id, 'level' => 'use', 'duration' => '24h', 'reason' => 'Rotating the S3 keys for the Asha Retail bucket.',
    ])->assertRedirect()->assertInertiaFlash('toast.message', 'Request sent. You’ll get a notification when it’s decided.');

    $request = VaultAccessRequest::query()->withoutGlobalScopes()->sole();
    expect($request->status)->toBe(VaultRequestStatus::Pending)->and($request->level)->toBe(VaultAccessLevel::Use);

    Notification::assertSentTo([$admin, $manager], VaultAccessRequested::class, function (VaultAccessRequested $n, array $channels, object $notifiable) {
        $payload = $n->toArray($notifiable);

        return $payload['title'] === 'Rohan Desai asked for Use access'
            && str_contains($payload['body'], 'AWS — root account · for 24 hours')
            && $payload['kind'] === 'vault.access_requested' && $payload['category'] === 'vault'
            && $channels === ['database', 'mail'];
    });
    Notification::assertNotSentTo($rohan, VaultAccessRequested::class);

    // Asking twice is refused.
    $this->actingAs($rohan)->post(route('vault.requests.store'), ['item_id' => $item->id, 'level' => 'use', 'duration' => '24h', 'reason' => 'Again please'])
        ->assertSessionHasErrors('reason');

    // The inbox shows it to the Manager.
    $this->actingAs($manager)->withSession(vaultUnlocked())->get(route('vault.requests'))
        ->assertInertia(fn (Assert $page) => $page->component('vault/requests')
            ->where('inbox.requests.0.name', 'Rohan Desai')
            ->where('inbox.requests.0.target', 'AWS — root account')
            ->where('inbox.requests.0.span', 'for 24 hours')
            ->where('inbox.requests.0.status', 'pending'));

    $this->actingAs($manager)->withSession(vaultUnlocked())->post(route('vault.requests.approve', $request))
        ->assertRedirect()->assertInertiaFlash('toast.message', 'Rohan Desai can use AWS — root account');

    $grant = VaultGrant::query()->withoutGlobalScopes()->where('user_id', $rohan->id)->sole();
    expect($grant->level)->toBe(VaultAccessLevel::Use)
        ->and($grant->expires_at?->toDateTimeString())->toBe('2026-11-16 10:00:00')
        ->and($request->refresh()->status)->toBe(VaultRequestStatus::Approved)
        ->and($request->decided_by)->toBe($manager->id);

    Notification::assertSentTo($rohan, VaultAccessDecided::class, fn (VaultAccessDecided $n, array $c, object $u) => $n->toArray($u)['title'] === 'You can now use AWS — root account');

    // Decided already.
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.requests.deny', $request))->assertStatus(409);
});

test('denying keeps access as it was, notes the reason and tells the requester', function () {
    Notification::fake();
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $member = vaultUser(CompanyRole::Member, $company);
    $vault = vaultIn($company, 'Kiran Exports');

    $this->actingAs($member)->post(route('vault.requests.store'), ['vault_id' => $vault->id, 'level' => 'view', 'duration' => 'never', 'reason' => 'Kiran asked us to move the site.'])
        ->assertRedirect();
    $request = VaultAccessRequest::query()->withoutGlobalScopes()->sole();

    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.requests.deny', $request), ['note' => 'Use the staging vault instead'])
        ->assertRedirect()->assertInertiaFlash('toast.message', "Request from {$member->name} denied");

    expect($request->refresh()->status)->toBe(VaultRequestStatus::Denied)
        ->and($request->decision_note)->toBe('Use the staging vault instead')
        ->and(VaultGrant::query()->withoutGlobalScopes()->count())->toBe(0);
    Notification::assertSentTo($member, VaultAccessDecided::class, fn (VaultAccessDecided $n, array $c, object $u) => str_contains($n->toArray($u)['body'], 'Use the staging vault instead'));
});

test('requests are checked: the role ceiling, what you already have, the reason, items you can’t see and other companies', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $member = vaultUser(CompanyRole::Member, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Req-2!']);
    $hidden = vaultItemFor($company, ['password' => 'demo-Req-3!'], ['name' => 'Hidden']);
    vaultGrant($member, $item, VaultAccessLevel::Use);

    $this->actingAs($member)->post(route('vault.requests.store'), ['item_id' => $item->id, 'level' => 'use', 'duration' => 'never', 'reason' => 'need it'])
        ->assertSessionHasErrors(['level' => 'You already have Use access.']);
    $this->actingAs($member)->post(route('vault.requests.store'), ['item_id' => $item->id, 'level' => 'edit', 'duration' => 'never', 'reason' => ''])
        ->assertSessionHasErrors('reason');
    $this->actingAs($member)->post(route('vault.requests.store'), ['item_id' => $hidden->id, 'level' => 'view', 'duration' => 'never', 'reason' => 'need it'])
        ->assertNotFound();

    $elsewhere = vaultItemFor(Company::factory()->create(), ['password' => 'demo-Req-4!']);
    $this->actingAs($member)->post(route('vault.requests.store'), ['item_id' => $elsewhere->id, 'level' => 'view', 'duration' => 'never', 'reason' => 'need it'])
        ->assertNotFound();

    // Someone who can't decide gets 403; withdrawing is only for the requester.
    $this->actingAs($member)->post(route('vault.requests.store'), ['item_id' => $item->id, 'level' => 'edit', 'duration' => '7d', 'reason' => 'rotating it'])->assertRedirect();
    $request = VaultAccessRequest::query()->withoutGlobalScopes()->sole();
    $other = vaultUser(CompanyRole::Member, $company);
    $this->actingAs($other)->withSession(vaultUnlocked())->post(route('vault.requests.approve', $request))->assertForbidden();
    $this->actingAs($other)->delete(route('vault.requests.destroy', $request))->assertNotFound();
    $this->actingAs($member)->withSession(vaultUnlocked())->post(route('vault.requests.approve', $request))->assertForbidden();

    // Deciding needs the vault unlocked.
    $this->flushSession();
    $this->actingAs($admin)->post(route('vault.requests.approve', $request))->assertRedirect()
        ->assertInertiaFlash('toast.message', 'The vault locked. Unlock it to continue.');
    expect($request->refresh()->status)->toBe(VaultRequestStatus::Pending);

    $this->actingAs($member)->delete(route('vault.requests.destroy', $request))->assertRedirect();
    expect($request->refresh()->status)->toBe(VaultRequestStatus::Cancelled);
});

test('the requests page needs the vault page and shows nothing while locked; viewers are denied', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $viewer = vaultUser(CompanyRole::Viewer, $company);

    $this->actingAs($admin)->get(route('vault.requests'))
        ->assertInertia(fn (Assert $page) => $page->component('vault/requests')->where('inbox', null)->where('vaultState.unlocked', false));
    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.requests'))
        ->assertInertia(fn (Assert $page) => $page->where('inbox.requests', [])->where('inbox.managesAnything', true));
    $this->actingAs($viewer)->get(route('vault.requests'))->assertForbidden();
});

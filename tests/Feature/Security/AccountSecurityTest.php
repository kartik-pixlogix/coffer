<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Impersonation;
use App\Models\Partner;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Platform\Impersonator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

/*
| Account security (D110, D111): P9 asks for the password or a code on the request that
| turns 2FA off, makes new recovery codes or replaces the authenticator; S2 blocks every
| account-security action while a platform admin impersonates; S1/S3/S4/S5 impersonation.
*/

function twoFactorPartner(): User
{
    return userWithRole(CompanyRole::Partner, attributes: [
        'two_factor_secret' => encrypt(VAULT_TEST_2FA),
        'two_factor_recovery_codes' => encrypt(json_encode(['A-1', 'B-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

function opsAdmin(): PlatformAdmin
{
    return PlatformAdmin::query()->create(['name' => 'Coffer Platform', 'email' => 'ops@coffer.test', 'password' => 'platform-password']);
}

/** @return array<string, mixed> */
function impersonationSession(PlatformAdmin $admin, User $user, ?int $id = null): array
{
    return [Impersonator::SESSION => [
        'id' => $id ?? 0, 'admin_id' => $admin->id, 'admin_name' => $admin->name, 'user_id' => $user->id,
        'workspace_id' => null, 'reason' => 'Support ticket 4411: reports look wrong',
    ]];
}

test('turning 2FA off needs the password or a current code in the request, even after a recent confirmation (P9)', function () {
    $user = twoFactorPartner();
    $session = ['auth.password_confirmed_at' => time()];

    $this->actingAs($user)->withSession($session)->delete(route('two-factor.disable'))
        ->assertSessionHasErrors(['confirmation' => 'Enter your password or a 6-digit code to confirm.']);
    $this->actingAs($user)->withSession($session)->delete(route('two-factor.disable'), ['confirmation' => 'not-my-password'])
        ->assertSessionHasErrors(['confirmation' => 'That password didn’t work.']);
    $this->actingAs($user)->withSession($session)->delete(route('two-factor.disable'), ['confirmation' => '000000'])
        ->assertSessionHasErrors('confirmation');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($user)->withSession($session)
        ->delete(route('two-factor.disable'), ['confirmation' => (new Google2FA)->getCurrentOtp(VAULT_TEST_2FA)])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('new recovery codes and replacing the authenticator need the same confirmation (P9)', function () {
    $user = twoFactorPartner();
    $session = ['auth.password_confirmed_at' => time()];
    $before = $user->recoveryCodes();

    $this->actingAs($user)->withSession($session)->post(route('two-factor.regenerate-recovery-codes'))->assertSessionHasErrors('confirmation');
    $this->actingAs($user)->withSession($session)->post(route('profile.two-factor.replace'))->assertSessionHasErrors('confirmation');
    expect($user->fresh()->recoveryCodes())->toBe($before)
        ->and(decrypt($user->fresh()->two_factor_secret))->toBe(VAULT_TEST_2FA);

    $this->actingAs($user)->withSession($session)->post(route('two-factor.regenerate-recovery-codes'), ['confirmation' => 'password'])->assertSessionHasNoErrors();
    expect($user->fresh()->recoveryCodes())->not->toBe($before);
});

test('while a platform admin impersonates, every account-security action is refused (S2)', function () {
    $admin = opsAdmin();
    $user = twoFactorPartner();
    $session = [...impersonationSession($admin, $user), 'auth.password_confirmed_at' => time()];

    $this->actingAs($user)->withSession($session)->put(route('profile.password.update'), [
        'current_password' => 'password', 'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
    ])->assertRedirect();
    $this->actingAs($user)->withSession($session)->delete(route('two-factor.disable'), ['confirmation' => 'password'])->assertRedirect();
    $this->actingAs($user)->withSession($session)->post(route('two-factor.regenerate-recovery-codes'), ['confirmation' => 'password'])->assertRedirect();
    $this->actingAs($user)->withSession($session)->postJson(route('privacy.exports.store'))->assertForbidden();
    $this->actingAs($user)->withSession($session)->post(route('privacy.account.destroy'), ['confirmation' => 'DELETE', 'password' => 'password'])->assertRedirect();
    $this->actingAs($user)->withSession($session)->get(route('two-factor.recovery-codes'))->assertForbidden();

    $fresh = $user->fresh();
    expect(Hash::check('password', $fresh->password))->toBeTrue()
        ->and($fresh->hasTwoFactorEnabled())->toBeTrue()
        ->and($fresh->deletion_requested_at)->toBeNull()
        ->and(Activity::query()->where('description', 'account security blocked while impersonating')->count())->toBe(6);

    // The profile page says why and hides the cards.
    $this->actingAs($user)->withSession($session)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('impersonation.user', $user->name));
});

test('stop impersonating from the Inertia banner is a hard navigation to /admin (S1)', function () {
    $admin = opsAdmin();
    $tenant = userWithRole(CompanyRole::Admin);

    $this->actingAs($admin, 'platform')->actingAs($tenant, 'web')
        ->withSession(impersonationSession($admin, $tenant))
        ->post(route('impersonation.stop'), [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/admin'));
});

test('signing out of the app keeps the platform admin signed in to /admin and closes the impersonation (S3, S4)', function () {
    $admin = opsAdmin();
    $tenant = userWithRole(CompanyRole::Admin);
    $row = Impersonation::query()->create([
        'platform_admin_id' => $admin->id, 'user_id' => $tenant->id,
        'reason' => 'Support ticket 4411: reports look wrong', 'started_at' => now(),
    ]);

    $this->actingAs($admin, 'platform')->actingAs($tenant, 'web')
        ->withSession([...impersonationSession($admin, $tenant, $row->id), 'platform.marker' => 'kept'])
        ->post(route('logout'))->assertRedirect();

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::guard('platform')->user()?->is($admin))->toBeTrue()
        // The shared session (the platform sign-in lives in it) wasn't flushed, as Fortify's sign-out did.
        ->and(session()->get('platform.marker'))->toBe('kept')
        ->and($row->refresh()->ended_at)->not->toBeNull();
});

test('a plain tenant sign-out still ends the whole session', function () {
    $tenant = userWithRole(CompanyRole::Admin);

    $this->actingAs($tenant)->withSession(['current_company_id' => companyOf($tenant)->id])->post(route('logout'))->assertRedirect();

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(session()->has('current_company_id'))->toBeFalse();
});

test('the banner says who views as whom and flags a suspended workspace (S4, S5)', function () {
    $admin = opsAdmin();
    $tenant = userWithRole(CompanyRole::Admin);
    companyOf($tenant)->workspace->forceFill(['suspended_at' => now(), 'suspended_reason' => 'Chargeback'])->save();

    $this->actingAs($tenant)->withSession(impersonationSession($admin, $tenant))->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.admin', 'Coffer Platform')
            ->where('impersonation.user', $tenant->name)
            ->where('impersonation.suspended', true));
});

test('the palette search finds records the role may open, in this company only (F5)', function () {
    $books = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $viewer = userWithRole(CompanyRole::Viewer, $books->company);
    $other = jvBooks();
    Partner::factory()->for($other->company)->create(['name' => 'Aarav Elsewhere']);

    $groups = collect($this->actingAs($admin)->getJson(route('search', ['q' => 'aarav']))->assertOk()->json('groups'));
    expect($groups->firstWhere('label', 'Partners')['items'])->toHaveCount(1)
        ->and(json_encode($groups->all()))->not->toContain('Elsewhere');

    // A Viewer can't open Partners or Expenses by default: those groups never come back.
    $viewerGroups = collect($this->actingAs($viewer)->getJson(route('search', ['q' => 'aarav']))->assertOk()->json('groups'))->pluck('label');
    expect($viewerGroups)->not->toContain('Expenses');

    $this->actingAs($admin)->getJson(route('search', ['q' => 'a']))->assertOk()->assertExactJson(['groups' => []]);
});

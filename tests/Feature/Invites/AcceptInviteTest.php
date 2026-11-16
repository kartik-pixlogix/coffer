<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Invite;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Notifications\InviteRenewalRequested;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/** Saarthi-style JV invite: Kabir as a Partner on the Bluefin side at 50% of the side. */
function partnerInvite(array $attributes = []): Invite
{
    $company = Company::factory()->jv()->create(['name' => 'Saarthi Portal JV']);
    $inviter = userWithRole(CompanyRole::Admin, $company, ['name' => 'Aarav Mehta']);
    Side::factory()->for($company)->create(['name' => 'Vertex Labs', 'equity_bps' => 5000, 'position' => 0]);
    $side = Side::factory()->for($company)->create(['name' => 'Bluefin Infotech', 'equity_bps' => 5000, 'position' => 1]);
    $partner = Partner::factory()->for($company)->create(['name' => 'Kabir Shah', 'side_id' => $side->id, 'equity_bps' => 5000]);

    return Invite::factory()->for($company)->create([
        'email' => 'kabir@bluefininfotech.in',
        'role' => CompanyRole::Partner,
        'partner_id' => $partner->id,
        'invited_by' => $inviter->id,
        ...$attributes,
    ]);
}

test('a valid invite shows who, where, the role and what it can do', function () {
    $invite = partnerInvite();

    $this->get(route('invites.show', $invite->token()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invite')
            ->where('valid', true)
            ->where('inviter.name', 'Aarav Mehta')
            ->where('company.name', 'Saarthi Portal JV')
            ->where('company.sides', ['Vertex Labs', 'Bluefin Infotech'])
            ->where('invite.role', 'partner')
            ->where('partner.side', 'Bluefin Infotech')
            ->where('partner.equityBps', 5000)
            ->where('abilities.can', [
                'See the dashboard, reports and settlements',
                'Add income and expenses, and approve them',
                'Use vault items shared with you',
            ])
            ->where('abilities.cannot', ['Change billing or company settings (Admins only)'])
            ->where('account', null)
            ->where('existingAccount', false));
});

test('an unknown token is a 404', function () {
    $this->get(route('invites.show', 'nope'))->assertNotFound();
});

test('expired, revoked, declined and used invites show the expired state', function (array $state) {
    $invite = partnerInvite($state);

    $this->get(route('invites.show', $invite->token()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('valid', false));
})->with([
    'expired' => [['expires_at' => now()->subDay()]],
    'revoked' => [['revoked_at' => now()]],
    'declined' => [['declined_at' => now()]],
]);

test('joining with a password creates the account, the role and the partner link', function () {
    $invite = partnerInvite();

    $this->post(route('invites.accept', $invite->token()), ['password' => 'a long enough passphrase'])
        ->assertRedirect(route('home'))
        ->assertSessionHas(CurrentCompany::SESSION_KEY, $invite->company_id);

    $user = User::query()->where('email', 'kabir@bluefininfotech.in')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Kabir Shah')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($invite->company->roleOf($user))->toBe(CompanyRole::Partner)
        ->and(Partner::query()->withoutGlobalScopes()->find($invite->partner_id)->user_id)->toBe($user->id)
        ->and($invite->fresh()->accepted_at)->not->toBeNull();

    // An email invite works once.
    auth()->logout();
    $this->get(route('invites.show', $invite->token()))->assertInertia(fn (Assert $page) => $page->where('valid', false));
    $this->post(route('invites.accept', $invite->token()), ['password' => 'a long enough passphrase'])->assertSessionHasErrors();
});

test('the new password must be at least 12 characters', function () {
    $invite = partnerInvite();

    $this->post(route('invites.accept', $invite->token()), ['password' => 'short'])
        ->assertSessionHasErrors(['password' => 'Use at least 12 characters.']);

    $this->assertGuest();
});

test('a share link can be used by several people until it expires', function () {
    $company = Company::factory()->create();
    $invite = Invite::factory()->link()->for($company)->create(['role' => CompanyRole::Member]);

    foreach (['priya@vertexlabs.in', 'tanvi@vertexlabs.in'] as $email) {
        $this->post(route('invites.accept', $invite->token()), ['name' => 'New Person', 'email' => $email, 'password' => 'a long enough passphrase'])
            ->assertRedirect(route('home'));
        auth()->logout();
    }

    expect($invite->fresh()->uses)->toBe(2)
        ->and($company->memberships()->count())->toBe(2)
        // Share-link sign-ups confirm their own email.
        ->and(User::query()->where('email', 'priya@vertexlabs.in')->value('email_verified_at'))->toBeNull();
});

test('a signed-in user with the invited email joins straight away', function () {
    $invite = partnerInvite();
    $kabir = userWithRole(CompanyRole::Viewer, attributes: ['email' => 'kabir@bluefininfotech.in']);

    $this->actingAs($kabir)
        ->get(route('invites.show', $invite->token()))
        ->assertInertia(fn (Assert $page) => $page->where('account', ['email' => 'kabir@bluefininfotech.in', 'matches' => true]));

    $this->post(route('invites.accept', $invite->token()))->assertRedirect(route('home'));

    expect($invite->company->roleOf($kabir))->toBe(CompanyRole::Partner);
});

test('a signed-in user can’t take someone else’s invite', function () {
    $invite = partnerInvite();
    $other = userWithRole(attributes: ['email' => 'meera@bluefininfotech.in']);

    $this->actingAs($other)
        ->post(route('invites.accept', $invite->token()))
        ->assertSessionHasErrors(['invite' => 'This invite is for kabir@bluefininfotech.in. Sign out and open it again.']);

    expect($invite->company->roleOf($other))->toBeNull();
});

test('someone already in the company is told so', function () {
    $invite = partnerInvite();
    $kabir = userWithRole(CompanyRole::Viewer, $invite->company, ['email' => 'kabir@bluefininfotech.in']);

    $this->actingAs($kabir)
        ->post(route('invites.accept', $invite->token()))
        ->assertSessionHasErrors(['invite' => 'You’re already in Saarthi Portal JV.']);
});

test('an existing account is asked to sign in and comes back to the invite', function () {
    $invite = partnerInvite();
    User::factory()->create(['email' => 'kabir@bluefininfotech.in']);

    $this->get(route('invites.show', $invite->token()))
        ->assertInertia(fn (Assert $page) => $page->where('existingAccount', true))
        ->assertSessionHas('url.intended', route('invites.show', $invite->token()));

    $this->post(route('invites.accept', $invite->token()), ['password' => 'a long enough passphrase'])
        ->assertSessionHasErrors('email');
});

test('declining ends an email invite', function () {
    $invite = partnerInvite();

    $this->post(route('invites.decline', $invite->token()))->assertRedirect(route('invites.show', $invite->token()));

    expect($invite->fresh()->declined_at)->not->toBeNull();
});

test('asking for a new link emails the inviter once', function () {
    Notification::fake();
    $invite = partnerInvite(['expires_at' => now()->subDay()]);

    $this->post(route('invites.renew', $invite->token()))->assertRedirect(route('invites.show', $invite->token()));
    $this->post(route('invites.renew', $invite->token()));

    Notification::assertSentTo($invite->inviter, InviteRenewalRequested::class);
    Notification::assertCount(1);

    $this->get(route('invites.show', $invite->token()))
        ->assertInertia(fn (Assert $page) => $page->where('renewalRequested', true));
});

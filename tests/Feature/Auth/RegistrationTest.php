<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

$valid = fn (array $overrides = []) => [
    'name' => 'Aarav Mehta',
    'company_name' => 'Vertex Group',
    'email' => 'aarav@vertexlabs.in',
    'password' => 'a short sentence works',
    'terms' => '1',
    ...$overrides,
];

test('the sign-up page renders', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/register'));
});

test('signing up creates the user and their workspace on a 14-day trial', function () use ($valid) {
    $this->post(route('register.store'), $valid())
        ->assertRedirect(route('home', absolute: false));

    $user = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $workspace = Workspace::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($workspace->name)->toBe('Vertex Group')
        ->and($workspace->owner_id)->toBe($user->id)
        ->and($workspace->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($user->email_verified_at)->toBeNull();
});

test('a new sign-up verifies their email, then lands in the wizard', function () use ($valid) {
    $this->post(route('register.store'), $valid());

    $this->get(route('home'))->assertRedirect(route('verification.notice'));

    $user = User::query()->sole();
    $user->markEmailAsVerified();

    $this->actingAs($user->fresh())->get(route('home'))->assertRedirect(route('companies.create'));
});

test('sign-up is validated with the design’s copy', function (array $input, string $field, string $message) use ($valid) {
    User::factory()->create(['email' => 'taken@vertexlabs.in']);

    $this->post(route('register.store'), $valid($input))
        ->assertSessionHasErrors([$field => $message]);

    $this->assertGuest();
})->with([
    'short password' => [['password' => 'too short'], 'password', 'Use at least 12 characters. A short sentence works well.'],
    'bad email' => [['email' => 'aarav'], 'email', 'Use your work email so teammates can find the company.'],
    'taken email' => [['email' => 'taken@vertexlabs.in'], 'email', 'There’s already an account with this email. Sign in instead.'],
    'no company' => [['company_name' => ''], 'company_name', 'Add your company’s name.'],
    'no terms' => [['terms' => null], 'terms', 'Agree to the Terms and Privacy Policy to continue.'],
]);

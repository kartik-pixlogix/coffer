<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Category;
use Inertia\Testing\AssertableInertia as Assert;

test('admins see income and expense categories with Salary as a system category', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)
        ->get(route('settings.categories'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/categories')
            ->has('categories', 10)
            ->where('categories', fn ($rows) => collect($rows)->firstWhere('name', 'Salary')['system'] === true
                && collect($rows)->where('kind', 'income')->count() === 4));
});

test('roles without the Settings page are denied', function (CompanyRole $role) {
    $books = singleBooks();
    $user = userWithRole($role, $books->company);
    $salary = category($books->company, 'expense', 'Software');

    $this->actingAs($user)->get(route('settings.categories'))->assertForbidden();
    $this->actingAs($user)->post(route('settings.categories.store'), ['kind' => 'expense', 'name' => 'X'])->assertForbidden();
    $this->actingAs($user)->put(route('settings.categories.update', $salary), ['name' => 'X'])->assertForbidden();
    $this->actingAs($user)->patch(route('settings.categories.archive', $salary))->assertForbidden();
})->with([CompanyRole::Partner, CompanyRole::Member, CompanyRole::Viewer]);

test('adding a category creates its ledger account and keeps names unique per kind', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('settings.categories.store'), ['kind' => 'expense', 'name' => ''])
        ->assertSessionHasErrors(['name' => 'Add the category’s name.']);
    $this->actingAs($admin)->post(route('settings.categories.store'), ['kind' => 'expense', 'name' => 'software'])
        ->assertSessionHasErrors(['name' => 'There’s already a category with this name.']);
    $this->actingAs($admin)->post(route('settings.categories.store'), ['kind' => 'nope', 'name' => 'Hosting'])
        ->assertSessionHasErrors('kind');

    $this->actingAs($admin)->post(route('settings.categories.store'), ['kind' => 'expense', 'name' => '  Hosting  '])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Hosting added');

    // An income category may share an expense category's name.
    $this->actingAs($admin)->post(route('settings.categories.store'), ['kind' => 'income', 'name' => 'Hosting'])
        ->assertSessionHasNoErrors();

    $hosting = category($books->company, 'expense', 'Hosting');

    expect(Account::query()->withoutGlobalScopes()->where('category_id', $hosting->id)->where('role', AccountRole::Expense->value)->value('name'))
        ->toBe('Expenses — Hosting');
});

test('a category can be renamed, archived and restored; its account follows the name', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $travel = category($books->company, 'expense', 'Travel');

    $this->actingAs($admin)->put(route('settings.categories.update', $travel), ['name' => 'Travel & stay'])
        ->assertSessionHasNoErrors();

    expect($travel->refresh()->name)->toBe('Travel & stay')
        ->and(Account::query()->withoutGlobalScopes()->where('category_id', $travel->id)->value('name'))->toBe('Expenses — Travel & stay');

    $this->actingAs($admin)->patch(route('settings.categories.archive', $travel))->assertInertiaFlash('toast.message', 'Travel & stay archived');
    expect($travel->refresh()->archived_at)->not->toBeNull();

    $this->actingAs($admin)->patch(route('settings.categories.archive', $travel))->assertInertiaFlash('toast.message', 'Travel & stay restored');
    expect($travel->refresh()->archived_at)->toBeNull();
});

test('Salary is locked', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $salary = category($books->company, 'expense', 'Salary');

    $this->actingAs($admin)->put(route('settings.categories.update', $salary), ['name' => 'Wages'])
        ->assertSessionHasErrors(['category' => 'Salary is a system category, so it can’t be changed.']);
    $this->actingAs($admin)->patch(route('settings.categories.archive', $salary))->assertSessionHasErrors('category');

    expect($salary->refresh()->name)->toBe(Category::SALARY)->and($salary->archived_at)->toBeNull();
});

test('another company’s category is not found', function () {
    $books = singleBooks();
    $other = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $theirs = category($other->company, 'expense', 'Travel');

    $this->actingAs($admin)->put(route('settings.categories.update', $theirs), ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($admin)->patch(route('settings.categories.archive', $theirs))->assertNotFound();
});

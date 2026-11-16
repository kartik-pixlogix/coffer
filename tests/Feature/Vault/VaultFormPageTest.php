<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;

// Add item (VaultAddCatalog / VaultAddLogin) and Edit render the same page, never with a secret.

test('the add page shows the catalog, then the template; it needs an unlocked vault', function () {
    $company = Company::factory()->create();
    vaultIn($company);
    $admin = vaultUser(CompanyRole::Admin, $company);

    $this->actingAs($admin)->get(route('vault.items.create'))->assertRedirect(route('vault.index'));

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.items.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('vault/create')
            ->where('step', 'catalog')
            ->has('catalog', 32)
            ->where('catalog.0.name', 'Hostinger')
            ->where('catalog.0.monogram', 'HO')
            ->has('templates', 10)
            ->has('can.pages'));

    $this->actingAs($admin)->get(route('vault.items.create', ['provider' => 'aws']))
        ->assertInertia(fn (Assert $page) => $page->where('step', 'details')->where('type', 'login')->where('provider.customFields.2', ['label' => 'Secret access key', 'secret' => true]));
});

test('the edit page never carries a secret, only which ones are set', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Never-In-Edit-1', 'notes' => 'demo note text'], [
        'custom_fields' => [['label' => 'Token', 'secret' => true, 'value' => 'demo-custom-secret-7']],
    ]);

    $response = $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.items.edit', $item))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('vault/create')
            ->where('item.secretsSet', ['notes', 'password'])
            ->where('item.customFields.0.set', true)
            ->where('item.customFields.0.value', ''));

    expect($response->getContent())->not->toContain('demo-Never-In-Edit-1')->not->toContain('demo note text')->not->toContain('demo-custom-secret-7');
});

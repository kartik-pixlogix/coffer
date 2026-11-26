<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\GstRegistration;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/* Settings › Tax (D159): the company's GST registration, TAN and who files. */

test('only roles with Settings may open it', function (CompanyRole $role, int $status) {
    $books = singleBooks();

    $this->actingAs(userWithRole($role, $books->company))->get(route('settings.tax'))->assertStatus($status);
})->with([
    'admin' => [CompanyRole::Admin, 200],
    'partner' => [CompanyRole::Partner, 403],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('it saves the profile, normalising the GSTIN and TAN, and seals the TAN', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->put(route('settings.tax.update'), [
        'gst_registration' => 'regular',
        'state_code' => '27',
        'gstin' => ' 27aajcv1234k1z8 ',
        'tan' => 'pnev01234b',
        'gst_filed_by' => 'ca',
    ])->assertSessionHasNoErrors();

    $company = $books->company->refresh();
    expect($company->gst_registration)->toBe(GstRegistration::Regular)
        ->and($company->state_code)->toBe('27')
        ->and($company->gstin)->toBe('27AAJCV1234K1Z8')
        ->and($company->tan)->toBe('PNEV01234B')
        ->and(DB::table('companies')->where('id', $company->id)->value('tan'))->toStartWith('cf1:');

    $this->actingAs($admin)->get(route('settings.tax'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/tax')->where('tax.tan', 'PNEV01234B')->has('options.states'));
});

test('a registered company needs a GSTIN that matches its state', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $base = ['gst_registration' => 'regular', 'state_code' => '27', 'gst_filed_by' => 'in_house'];

    $this->actingAs($admin)->put(route('settings.tax.update'), $base)->assertSessionHasErrors('gstin');
    $this->actingAs($admin)->put(route('settings.tax.update'), [...$base, 'gstin' => '29AAJCV1234K1Z8'])->assertSessionHasErrors('gstin');
    $this->actingAs($admin)->put(route('settings.tax.update'), [...$base, 'gstin' => 'NOTAGSTIN'])->assertSessionHasErrors('gstin');
    $this->actingAs($admin)->put(route('settings.tax.update'), [...$base, 'state_code' => null, 'gstin' => '27AAJCV1234K1Z8'])->assertSessionHasErrors('state_code');
    $this->actingAs($admin)->put(route('settings.tax.update'), [...$base, 'gstin' => '27AAJCV1234K1Z8', 'tan' => 'BAD'])->assertSessionHasErrors('tan');
});

test('an unregistered company needs nothing more', function () {
    $books = singleBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))->put(route('settings.tax.update'), [
        'gst_registration' => 'unregistered', 'gst_filed_by' => 'in_house',
    ])->assertSessionHasNoErrors();
});

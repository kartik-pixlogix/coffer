<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\ExportStatus;
use App\Models\Company;
use App\Models\DataExport;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PaymentGateway;
use App\Services\Privacy\CompanyTables;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoVaultSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
| DPDPA (spec §23, D98): personal and workspace exports, account and workspace deletion after
| the grace period, anonymised where the books must keep the record.
*/

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    CarbonImmutable::setTestNow('2026-11-17 10:00:00');
});

/** Reads one entry of a finished export. */
function exportEntry(DataExport $export, string $name): string|false
{
    $local = tempnam(sys_get_temp_dir(), 'cfpx');
    file_put_contents($local, Storage::disk('local')->get((string) $export->path));
    $zip = new ZipArchive;
    $zip->open($local);

    return $zip->getFromName($name);
}

/** Every entry of a finished export, joined. */
function exportText(DataExport $export): string
{
    $local = tempnam(sys_get_temp_dir(), 'cfpx');
    file_put_contents($local, Storage::disk('local')->get((string) $export->path));
    $zip = new ZipArchive;
    $zip->open($local);
    $text = '';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $text .= $zip->getNameIndex($i)."\n".$zip->getFromIndex($i)."\n";
    }

    return $text;
}

test('the profile page carries the Your data panel', function () {
    $user = userWithRole(CompanyRole::Member);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('privacy.graceDays', 30)
            ->where('privacy.deletion', null)
            ->has('privacy.exports', 0)
            ->has('privacy.ownedWorkspaces', 0));
});

test('anyone can export their own data, without other people or vault secrets', function () {
    $this->seed(DatabaseSeeder::class);
    $rohan = User::query()->where('email', 'rohan@vertexlabs.in')->sole();

    $this->actingAs($rohan)->post(route('privacy.exports.store'), ['kind' => 'account'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'We’re preparing your export. It appears here when it’s ready.');

    $export = DataExport::query()->sole();
    expect($export->status)->toBe(ExportStatus::Ready)
        ->and($export->expires_at?->toDateString())->toBe('2026-11-24');

    $profile = json_decode((string) exportEntry($export, 'profile.json'), true);
    expect($profile['email'])->toBe('rohan@vertexlabs.in')
        ->and(json_decode((string) exportEntry($export, 'memberships.json'), true))->not->toBeEmpty();

    $text = exportText($export);
    expect($text)->not->toContain('aarav@vertexlabs.in')->not->toContain('ishita@vertexlabs.in');

    foreach (DemoVaultSeeder::SECRETS as $secret) {
        expect(str_contains($text, $secret))->toBeFalse('A vault secret appeared in a personal export.');
    }

    // The signed link from the panel downloads it, for its owner only.
    $url = $this->actingAs($rohan)->get(route('profile.edit'))->viewData('page')['props']['privacy']['exports'][0]['url'];
    expect($url)->toBeString();

    $this->actingAs($rohan)->get($url)->assertOk()->assertDownload("coffer-account-export-{$export->id}.zip");
    $this->actingAs(User::query()->where('email', 'aarav@vertexlabs.in')->sole())->get($url)->assertNotFound();
    $this->actingAs($rohan)->get(route('privacy.exports.download', $export))->assertForbidden();
});

test('only the owner can export a workspace; the archive has every company as CSV and no vault secrets', function () {
    $this->seed(DatabaseSeeder::class);
    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $ishita = User::query()->where('email', 'ishita@vertexlabs.in')->sole();
    $workspace = Workspace::query()->where('owner_id', $aarav->id)->sole();

    $this->actingAs($ishita)->post(route('privacy.exports.store'), ['kind' => 'workspace', 'workspace_id' => $workspace->id])->assertForbidden();
    $this->actingAs($aarav)->post(route('privacy.exports.store'), ['kind' => 'workspace', 'workspace_id' => $workspace->id])->assertRedirect();

    $export = DataExport::query()->where('kind', 'workspace')->sole();
    expect($export->status)->toBe(ExportStatus::Ready);

    $text = exportText($export);
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();

    expect($text)->toContain("saarthi-portal-jv-{$saarthi->id}/journal_entries.csv")
        ->toContain("saarthi-portal-jv-{$saarthi->id}/people.csv")
        ->toContain("saarthi-portal-jv-{$saarthi->id}/vault_items.csv")
        ->not->toContain('vault_secrets.csv')
        ->not->toContain('wrapped_key');

    foreach (DemoVaultSeeder::SECRETS as $secret) {
        expect(str_contains($text, $secret))->toBeFalse('A vault secret appeared in a workspace export.');
    }
});

test('deleting an account needs the password and DELETE, and a workspace owner must delete the workspace first', function () {
    $member = userWithRole(CompanyRole::Member, attributes: ['password' => 'member-password-123']);
    $owner = userWithRole(CompanyRole::Admin, attributes: ['password' => 'owner-password-123']);
    companyOf($owner)->workspace->forceFill(['owner_id' => $owner->id])->save();

    $this->actingAs($member)->post(route('privacy.account.destroy'), ['password' => 'wrong', 'confirmation' => 'DELETE'])->assertSessionHasErrors('password');
    $this->actingAs($member)->post(route('privacy.account.destroy'), ['password' => 'member-password-123', 'confirmation' => 'delete'])->assertSessionHasErrors('confirmation');
    $this->actingAs($owner)->post(route('privacy.account.destroy'), ['password' => 'owner-password-123', 'confirmation' => 'DELETE'])->assertSessionHasErrors('account');

    $this->actingAs($member)->post(route('privacy.account.destroy'), ['password' => 'member-password-123', 'confirmation' => 'DELETE'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Your account will be deleted on 17 Dec 2026. You can keep it until then.');

    expect($member->refresh()->deletion_scheduled_for?->toDateString())->toBe('2026-12-17');

    // Kept within the grace period: nothing happens on the day.
    $this->actingAs($member)->post(route('privacy.account.restore'))->assertRedirect();
    CarbonImmutable::setTestNow('2026-12-18 05:00:00');
    $this->artisan('coffer:privacy-purge')->assertSuccessful();

    expect($member->refresh()->name)->not->toBe('Deleted user')->and($member->deletion_scheduled_for)->toBeNull();
});

test('after the grace period an account is anonymised and the books keep its records', function () {
    $this->seed(DatabaseSeeder::class);
    $rohan = User::query()->where('email', 'rohan@vertexlabs.in')->sole();
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();
    $rohan->forceFill(['password' => 'rohan-password-123'])->save();

    $expenses = Expense::query()->withoutGlobalScopes()->where('submitted_by', $rohan->id)->count();
    $entries = TimeEntry::query()->withoutGlobalScopes()->whereHas('member', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $rohan->id))->count();
    $journal = JournalEntry::query()->withoutGlobalScopes()->count();

    $this->actingAs($rohan)->post(route('privacy.account.destroy'), ['password' => 'rohan-password-123', 'confirmation' => 'DELETE'])->assertSessionHasNoErrors();

    // Not before the day.
    CarbonImmutable::setTestNow('2026-12-16 23:00:00');
    $this->artisan('coffer:privacy-purge')->assertSuccessful();
    expect($rohan->refresh()->anonymised_at)->toBeNull();

    CarbonImmutable::setTestNow('2026-12-17 04:30:00');
    $this->artisan('coffer:privacy-purge')->assertSuccessful();

    $rohan->refresh();
    expect($rohan->name)->toBe('Deleted user')
        ->and($rohan->email)->toBe("deleted-user-{$rohan->id}@deleted.invalid")
        ->and($rohan->anonymised_at)->not->toBeNull()
        ->and($rohan->two_factor_secret)->toBeNull()
        ->and($rohan->companies()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $rohan->id)->count())->toBe(0)
        ->and(DB::table('vault_grants')->where('user_id', $rohan->id)->count())->toBe(0)
        // The records stay, still pointing at the same id.
        ->and(Expense::query()->withoutGlobalScopes()->where('submitted_by', $rohan->id)->count())->toBe($expenses)
        ->and(TimeEntry::query()->withoutGlobalScopes()->whereIn('member_id', DB::table('members')->where('company_id', $saarthi->id)->whereNull('user_id')->pluck('id'))->count())->toBeGreaterThanOrEqual($entries)
        ->and(JournalEntry::query()->withoutGlobalScopes()->count())->toBe($journal);

    // Nobody can sign in as them any more.
    auth()->logout();
    $this->post(route('login.store'), ['email' => 'rohan@vertexlabs.in', 'password' => 'rohan-password-123']);
    $this->assertGuest();

    $this->artisan('coffer:ledger-integrity')->assertSuccessful();
});

test('only the owner can delete a workspace, with the password and its exact name; the subscription is cancelled now', function () {
    $owner = userWithRole(CompanyRole::Admin, attributes: ['password' => 'owner-password-123']);
    $admin = userWithRole(CompanyRole::Admin, companyOf($owner), ['password' => 'admin-password-123']);
    $workspace = companyOf($owner)->workspace;
    $workspace->forceFill(['owner_id' => $owner->id, 'name' => 'Vertex Group'])->save();

    $cancelled = [];
    app()->instance(PaymentGateway::class, Mockery::mock(PaymentGateway::class, function ($mock) use (&$cancelled) {
        $mock->shouldReceive('cancelNow')->andReturnUsing(function (Workspace $w) use (&$cancelled): void {
            $cancelled[] = $w->id;
        });
    }));

    $this->actingAs($admin)->post(route('privacy.workspaces.destroy', $workspace), ['password' => 'admin-password-123', 'confirmation' => 'Vertex Group'])->assertForbidden();
    $this->actingAs($owner)->post(route('privacy.workspaces.destroy', $workspace), ['password' => 'owner-password-123', 'confirmation' => 'vertex group'])->assertSessionHasErrors('confirmation');
    expect($cancelled)->toBe([]);

    $this->actingAs($owner)->post(route('privacy.workspaces.destroy', $workspace), ['password' => 'owner-password-123', 'confirmation' => 'Vertex Group'])
        ->assertSessionHasNoErrors();

    expect($workspace->refresh()->deletion_scheduled_for?->toDateString())->toBe('2026-12-17')
        ->and($cancelled)->toBe([$workspace->id]);

    // Kept: the schedule clears.
    $this->actingAs($admin)->post(route('privacy.workspaces.restore', $workspace))->assertForbidden();
    $this->actingAs($owner)->post(route('privacy.workspaces.restore', $workspace))->assertRedirect();
    expect($workspace->refresh()->deletion_scheduled_for)->toBeNull();
});

test('after the grace period every row of the workspace is erased and other workspaces are untouched', function () {
    $this->seed(DatabaseSeeder::class);
    $other = singleBooks();
    $otherAccounts = DB::table('accounts')->where('company_id', $other->company->id)->count();

    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $workspace = Workspace::query()->where('owner_id', $aarav->id)->sole();
    $companyIds = Company::query()->where('workspace_id', $workspace->id)->pluck('id')->all();
    Storage::disk('local')->put("companies/{$companyIds[0]}/expenses/receipt.pdf", 'PDF');

    $workspace->forceFill(['deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->subMinute()])->save();
    $this->artisan('coffer:privacy-purge')->assertSuccessful();

    expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
        ->and(DB::table('companies')->whereIn('id', $companyIds)->count())->toBe(0);

    foreach (app(CompanyTables::class)->scoped() as $table) {
        expect(DB::table($table)->whereIn('company_id', $companyIds)->count())->toBe(0, "{$table} kept rows of an erased company");
    }

    Storage::disk('local')->assertMissing("companies/{$companyIds[0]}/expenses/receipt.pdf");
    expect(DB::table('accounts')->where('company_id', $other->company->id)->count())->toBe($otherAccounts)
        // People keep their accounts.
        ->and($aarav->refresh()->exists)->toBeTrue();
});

test('expired export files are deleted', function () {
    $user = userWithRole(CompanyRole::Member);
    Storage::disk('local')->put('privacy-exports/1/old.zip', 'zip');
    DataExport::query()->create(['user_id' => $user->id, 'kind' => 'account', 'status' => ExportStatus::Ready, 'path' => 'privacy-exports/1/old.zip', 'expires_at' => now()->subDay()]);

    $this->artisan('coffer:privacy-purge')->assertSuccessful();

    Storage::disk('local')->assertMissing('privacy-exports/1/old.zip');
    expect(DataExport::query()->count())->toBe(0);
});

test('signed download links expire', function () {
    $user = userWithRole(CompanyRole::Member);
    Storage::disk('local')->put('privacy-exports/x.zip', 'zip');
    $export = DataExport::query()->create(['user_id' => $user->id, 'kind' => 'account', 'status' => ExportStatus::Ready, 'path' => 'privacy-exports/x.zip', 'expires_at' => now()->addDay()]);
    $url = URL::temporarySignedRoute('privacy.exports.download', now()->addHour(), ['export' => $export->id]);

    $this->actingAs($user)->get($url)->assertOk();

    CarbonImmutable::setTestNow(now()->addHours(2));
    $this->actingAs($user)->get($url)->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the demo seeder recreates the design’s workspace (spec §24)', function () {
    $this->seed(DatabaseSeeder::class);

    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();

    expect(Company::query()->pluck('name')->sort()->values()->all())
        ->toBe(['Kriya Commerce', 'Northstar Studio', 'Saarthi Portal JV', 'Vertex Labs Pvt Ltd'])
        ->and($saarthi->workspace->owner_id)->toBe($aarav->id)
        ->and($aarav->companies()->count())->toBe(4)
        ->and($saarthi->roleOf($aarav))->toBe(CompanyRole::Admin)
        ->and($saarthi->roleOf(User::query()->where('email', 'rohan@vertexlabs.in')->sole()))->toBe(CompanyRole::Member)
        ->and($saarthi->roleOf(User::query()->where('email', 'accounts@ledgerworks.in')->sole()))->toBe(CompanyRole::Viewer);

    $overall = Partner::query()->withoutGlobalScopes()->where('company_id', $saarthi->id)->with('side')->get()
        ->mapWithKeys(fn (Partner $p) => [$p->name => $p->effectiveEquityBps()]);

    expect($overall->all())->toBe(['Aarav Mehta' => 2500, 'Ishita Rao' => 2500, 'Kabir Shah' => 2500, 'Meera Iyer' => 2500])
        ->and($overall->sum())->toBe(10000);
});

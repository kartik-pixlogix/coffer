<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\IntegrityCheck;
use App\Ledger\OpeningBalance;
use App\Ledger\Period;
use App\Ledger\Reports;
use App\Ledger\SettlementCalculator;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;
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

test('the demo books land on the design’s figures', function () {
    $this->seed(DatabaseSeeder::class);

    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();
    $period = Period::between(CarbonImmutable::create(2026, 4, 1), CarbonImmutable::create(2026, 10, 31));
    $figures = app(Reports::class)->figures($saarthi, $period);
    $settlement = app(SettlementCalculator::class)->calculate($saarthi, $period);
    $vertex = collect($settlement->sides)->first(fn ($s) => $s->side->name === 'Vertex Labs');

    expect($figures['income'])->toBe(62_10_000_00)
        ->and($figures['expenses'])->toBe(43_67_650_00)
        ->and($figures['net'])->toBe(18_42_350_00)
        ->and($figures['currentBalance'])->toBe(18_42_350_00)
        ->and($vertex->remaining)->toBe(3_24_600_00)
        ->and($vertex->status())->toBe('owes')
        ->and($settlement->transfers[0]['to']->name)->toBe('Bluefin Infotech')
        ->and($settlement->transfers[0]['amount'])->toBe(3_24_600_00);

    foreach (Company::query()->get() as $company) {
        expect(app(IntegrityCheck::class)->run($company, CarbonImmutable::create(2026, 11, 16)))->toBe([]);
    }

    $vertexLabs = Company::query()->where('name', 'Vertex Labs Pvt Ltd')->sole();
    expect(app(OpeningBalance::class)->current($vertexLabs))->not->toBeNull();
});

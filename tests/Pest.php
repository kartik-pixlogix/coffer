<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Enums\CompanyRole;
use App\Ledger\ChartOfAccounts;
use App\Models\Category;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests boot the app and run against a fresh database.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * A verified user with `$role` in `$company` (a new Single company by default).
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(CompanyRole $role = CompanyRole::Admin, ?Company $company = null, array $attributes = []): User
{
    $company ??= Company::factory()->create();
    $user = User::factory()->create($attributes);

    app(SetCompanyRole::class)->handle($company, $user, $role);

    return $user->refresh();
}

/** The first (and usually only) company a test user belongs to. */
function companyOf(User $user): Company
{
    /** @var Company */
    return $user->companies()->firstOrFail();
}

/**
 * A Single company with books set up: Vertex Labs, Aarav 60% / Ishita 40%.
 *
 * @return object{company: Company, aarav: Partner, ishita: Partner, account: MoneyAccount}
 */
function singleBooks(int $openingPaise = 0): object
{
    $company = Company::factory()->create(['name' => 'Vertex Labs Pvt Ltd', 'opening_balance_paise' => $openingPaise]);
    $aarav = Partner::factory()->for($company)->create(['name' => 'Aarav Mehta', 'equity_bps' => 6000, 'colour' => 1, 'position' => 0]);
    $ishita = Partner::factory()->for($company)->create(['name' => 'Ishita Rao', 'equity_bps' => 4000, 'colour' => 2, 'position' => 1]);

    $chart = app(ChartOfAccounts::class);
    $chart->setUp($company);

    return (object) ['company' => $company, 'aarav' => $aarav, 'ishita' => $ishita, 'account' => $chart->companyAccount($company)];
}

/**
 * A joint venture with books set up: Saarthi Portal JV, Vertex Labs 50% (Aarav 60 / Ishita 40)
 * and Bluefin Infotech 50% (Kabir 50 / Meera 50).
 */
function jvBooks(int $openingPaise = 0): object
{
    $company = Company::factory()->jv()->create(['name' => 'Saarthi Portal JV', 'opening_balance_paise' => $openingPaise]);
    $vertex = Side::factory()->for($company)->create(['name' => 'Vertex Labs', 'equity_bps' => 5000, 'position' => 0]);
    $bluefin = Side::factory()->for($company)->create(['name' => 'Bluefin Infotech', 'equity_bps' => 5000, 'position' => 1]);

    $partner = fn (string $name, $side, int $bps, int $colour, int $position) => Partner::factory()->for($company)
        ->create(['name' => $name, 'side_id' => $side->id, 'equity_bps' => $bps, 'colour' => $colour, 'position' => $position]);

    $books = (object) [
        'company' => $company,
        'vertex' => $vertex,
        'bluefin' => $bluefin,
        'aarav' => $partner('Aarav Mehta', $vertex, 6000, 1, 0),
        'ishita' => $partner('Ishita Rao', $vertex, 4000, 2, 1),
        'kabir' => $partner('Kabir Shah', $bluefin, 5000, 3, 2),
        'meera' => $partner('Meera Iyer', $bluefin, 5000, 4, 3),
    ];

    $chart = app(ChartOfAccounts::class);
    $chart->setUp($company);

    $books->account = $chart->companyAccount($company);
    $books->vertexAccount = $chart->sideAccount($vertex);
    $books->bluefinAccount = $chart->sideAccount($bluefin);

    return $books;
}

/** A company's category by kind and name ("income", "Project"). */
function category(Company $company, string $kind, string $name): Category
{
    return Category::query()->withoutGlobalScopes()
        ->where('company_id', $company->id)->where('kind', $kind)->where('name', $name)->firstOrFail();
}

/** 1 April 2026 + `$days`. */
function fyDay(int $days = 0): CarbonImmutable
{
    return CarbonImmutable::create(2026, 4, 1)->addDays($days);
}

/**
 * A journal entry's lines as "account: Dr|Cr paise [p=… s=… m=…]" strings, for exact
 * comparison in posting tests.
 *
 * @return list<string>
 */
function journalLines(int|JournalEntry|null $entry): array
{
    $id = $entry instanceof JournalEntry ? $entry->id : $entry;

    return JournalLine::query()->withoutGlobalScopes()->with(['account' => fn ($q) => $q->withoutGlobalScopes()])
        ->where('journal_entry_id', $id)->orderBy('id')->get()
        ->map(function (JournalLine $l) {
            $dims = array_filter(['p' => $l->partner_id, 's' => $l->side_id, 'm' => $l->member_id]);
            $dimText = $dims === [] ? '' : ' ['.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($dims), $dims)).']';

            return $l->account->name.': '.($l->debit_paise > 0 ? 'Dr '.$l->debit_paise : 'Cr '.$l->credit_paise).$dimText;
        })->values()->all();
}

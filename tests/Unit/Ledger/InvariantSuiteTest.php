<?php

declare(strict_types=1);

use App\Enums\AttributionMode;
use App\Enums\CompanyRole;
use App\Enums\CostType;
use App\Ledger\FiscalYears;
use App\Ledger\IntegrityCheck;
use App\Ledger\LedgerPoster;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Ledger\YearEnd;
use App\Models\Category;
use App\Models\JournalEntry;
use App\Models\Member;
use Carbon\CarbonImmutable;

/**
 * Property-style check of spec §4.5: random mixes of every §4.2 event, in both company
 * types and both attribution modes, with reversals and a year close in between. After
 * each batch, every invariant must hold (via the same IntegrityCheck the nightly job runs).
 */
function randomBooks(object $books, int $seed, int $events, CarbonImmutable $from, int $days): void
{
    mt_srand($seed);
    $p = app(Postings::class);
    $company = $books->company;

    $partners = collect([$books->aarav, $books->ishita, $books->kabir ?? null, $books->meera ?? null])->filter()->values();
    $accounts = collect([$books->account, $books->vertexAccount ?? null, $books->bluefinAccount ?? null])->filter()->values();
    $sides = collect([$books->vertex ?? null, $books->bluefin ?? null])->filter()->values();
    $members = $partners->map(fn ($partner) => Member::factory()->for($company)->create([
        'partner_id' => $partner->id,
        'cost_type' => mt_rand(0, 1) ? CostType::Hourly : CostType::FixedMonthly,
    ]));
    $income = Category::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('kind', 'income')->get();
    $expense = Category::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('kind', 'expense')->get();

    $pick = fn ($items) => $items[mt_rand(0, count($items) - 1)];
    $amount = fn () => mt_rand(1, 5_00_000_00) + (mt_rand(0, 3) === 0 ? mt_rand(1, 99) : 0);
    $posted = [];

    foreach (range(1, $events) as $_) {
        $date = $from->addDays(mt_rand(0, $days - 1));
        $partner = $pick($partners);
        $account = $pick($accounts);

        $posted[] = match (mt_rand(1, 13)) {
            1, 2, 3 => $p->incomeToAccount($company, $date, $amount(), $pick($income), $account, mt_rand(0, 2) ? $partner : null),
            4 => $p->incomeToPartner($company, $date, $amount(), $pick($income), $partner),
            5, 6 => $p->expense($company, $date, $pick($expense), array_values(array_filter([
                PayerLine::company($amount(), $account),
                mt_rand(0, 1) ? PayerLine::partner($amount(), $pick($partners), mt_rand(0, 1) ? null : $account) : null,
                mt_rand(0, 2) === 0 ? PayerLine::member($amount(), $pick($members)) : null,
            ]))),
            7 => $p->salary($company, $pick($members), $amount(), $date->endOfMonth()->startOfDay()),
            8 => $p->reimbursement($company, $date, mt_rand(1, 50_000_00), mt_rand(0, 1) ? $partner : $pick($members), $account),
            9 => $p->contribution($company, $date, $partner, $amount(), $account),
            10 => $p->drawing($company, $date, $partner, $amount(), $account),
            11 => mt_rand(0, 1) ? $p->loan($company, $date, $partner, $amount(), $account) : $p->repayment($company, $date, $partner, $amount(), $account),
            12 => mt_rand(0, 1) ? $p->convertToLoan($company, $date, $partner, mt_rand(1, 1_00_000_00)) : $p->incomeToAccount($company, $date, $amount(), $pick($income), $account),
            13 => $sides->count() > 1
                ? $p->settlementTransfer($company, $date, $sides[0], $sides[1], $amount())
                : $p->expense($company, $date, $pick($expense), [PayerLine::company($amount(), $account)]),
        };
    }

    // Correct a few: reversals must keep every invariant.
    foreach (array_slice($posted, 0, intdiv(count($posted), 10)) as $entry) {
        if (mt_rand(0, 1) === 1 && $entry instanceof JournalEntry && $entry->fresh()->reversed_by_id === null) {
            app(LedgerPoster::class)->reverse($entry->fresh());
        }
    }
}

dataset('company kinds', [
    'single, per partner' => [fn () => singleBooks(openingPaise: 5_00_000_00), AttributionMode::PerPartner, 101],
    'single, equity mode' => [fn () => singleBooks(openingPaise: 1_23_456_78), AttributionMode::JvPooled, 202],
    'JV, pooled' => [fn () => jvBooks(openingPaise: 10_00_000_01), AttributionMode::JvPooled, 303],
    'JV, per partner' => [fn () => jvBooks(), AttributionMode::PerPartner, 404],
]);

test('random books keep invariants 1–6, across a year close', function (Closure $make, AttributionMode $mode, int $seed) {
    $books = $make();
    $books->company->update(['attribution_mode' => $mode]);
    $company = $books->company->fresh();
    $books->company = $company;
    $check = app(IntegrityCheck::class);
    $today = fyDay(500);

    app(OpeningBalance::class)->post($company, fyDay());

    randomBooks($books, $seed, 120, fyDay(), 365);
    expect($check->run($company, $today))->toBe([]);

    $admin = userWithRole(CompanyRole::Admin, $company);
    app(YearEnd::class)->close($company, app(FiscalYears::class)->forDate($company, fyDay()), $admin);
    expect($check->run($company, $today))->toBe([]);

    randomBooks($books, $seed + 1, 120, fyDay(365), 120);
    expect($check->run($company, $today))->toBe([]);
})->with('company kinds');

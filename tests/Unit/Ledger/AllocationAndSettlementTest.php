<?php

declare(strict_types=1);

use App\Enums\AttributionMode;
use App\Ledger\ChartOfAccounts;
use App\Ledger\Lines;
use App\Ledger\PayerLine;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Ledger\ProfitAllocator;
use App\Ledger\SettlementCalculator;
use App\Models\Member;
use App\Models\Side;

function fy(): Period
{
    return Period::between(fyDay(), fyDay()->addYear()->subDay());
}

test('JV pooled mode splits net profit by effective equity', function () {
    $books = jvBooks();
    $p = app(Postings::class);
    $p->incomeToAccount($books->company, fyDay(5), 10_00_000_00, category($books->company, 'income', 'Project'), $books->vertexAccount, $books->aarav);
    $p->expense($books->company, fyDay(6), category($books->company, 'expense', 'Software'), [PayerLine::company(2_00_000_01, $books->bluefinAccount)]);

    $shares = app(ProfitAllocator::class)->allocate($books->company, fy());

    expect(Lines::netProfit($books->company, fy()))->toBe(7_99_999_99)
        ->and(array_sum($shares))->toBe(7_99_999_99)
        // 30% = 2,39,999.997: the leftover paise go to the larger remainders (Ishita, Kabir, Meera).
        ->and($shares[$books->aarav->id])->toBe(2_39_999_99)
        ->and($shares[$books->ishita->id])->toBe(1_60_000_00)
        ->and($shares[$books->kabir->id] + $shares[$books->meera->id])->toBe(4_00_000_00);
});

test('per-partner mode: attributed amounts plus equity of the rest', function () {
    $books = singleBooks();
    $p = app(Postings::class);
    $neha = Member::factory()->for($books->company)->create(['partner_id' => $books->ishita->id]);
    $project = category($books->company, 'income', 'Project');

    $p->incomeToAccount($books->company, fyDay(1), 5_00_000_00, $project, $books->account, $books->aarav);   // Aarav brought in
    $p->incomeToAccount($books->company, fyDay(2), 1_00_000_00, $project, $books->account);                  // unattributed
    $p->expense($books->company, fyDay(3), category($books->company, 'expense', 'Travel'), [PayerLine::partner(20_000_00, $books->ishita)]); // paid by Ishita
    $p->salary($books->company, $neha, 30_000_00, fyDay(29));                                                 // Ishita's member
    $p->expense($books->company, fyDay(4), category($books->company, 'expense', 'Software'), [PayerLine::company(10_000_00, $books->account)]); // unattributed

    $shares = app(ProfitAllocator::class)->allocate($books->company, fy());

    // Unattributed = 1,00,000 − 10,000 = 90,000 → Aarav 54,000, Ishita 36,000.
    expect($shares[$books->aarav->id])->toBe(5_00_000_00 + 54_000_00)
        ->and($shares[$books->ishita->id])->toBe(-20_000_00 - 30_000_00 + 36_000_00)
        ->and(array_sum($shares))->toBe(Lines::netProfit($books->company, fy()));
});

test('per-partner mode in a JV splits side-account costs within the side', function () {
    $books = jvBooks();
    $books->company->update(['attribution_mode' => AttributionMode::PerPartner]);
    $p = app(Postings::class);

    $p->expense($books->company, fyDay(3), category($books->company, 'expense', 'Hardware'), [PayerLine::company(1_000_01, $books->vertexAccount)]);

    $shares = app(ProfitAllocator::class)->allocate($books->company->fresh(), fy());

    expect($shares[$books->aarav->id])->toBe(-600_01)
        ->and($shares[$books->ishita->id])->toBe(-400_00)
        ->and($shares[$books->kabir->id])->toBe(0);
});

test('settlements: who holds what against what they’re entitled to', function () {
    $books = jvBooks();
    $p = app(Postings::class);
    $project = category($books->company, 'income', 'Project');

    $p->incomeToAccount($books->company, fyDay(1), 10_00_000_00, $project, $books->vertexAccount);       // Vertex collects 10L
    $p->expense($books->company, fyDay(2), category($books->company, 'expense', 'Marketing'), [PayerLine::company(2_00_000_00, $books->bluefinAccount)]); // Bluefin pays 2L
    $p->incomeToAccount($books->company, fyDay(3), 1_00_000_00, $project, $books->account);              // JV account +1L

    $result = app(SettlementCalculator::class)->calculate($books->company, fy());
    $vertex = $result->sides[$books->vertex->id];
    $bluefin = $result->sides[$books->bluefin->id];

    // Net profit 9L, entitled 4.5L each; pooled 50k each.
    expect($vertex->collected)->toBe(10_00_000_00)
        ->and($vertex->netHeld)->toBe(10_00_000_00)
        ->and($vertex->pooled)->toBe(50_000_00)
        ->and($vertex->entitled)->toBe(4_50_000_00)
        ->and($vertex->remaining)->toBe(6_00_000_00)
        ->and($vertex->status())->toBe('owes')
        ->and($bluefin->paid)->toBe(2_00_000_00)
        ->and($bluefin->remaining)->toBe(-6_00_000_00)
        ->and($bluefin->status())->toBe('owed')
        ->and($result->remainderSum())->toBe(0)
        ->and($result->transfers)->toHaveCount(1)
        ->and($result->transfers[0]['amount'])->toBe(6_00_000_00);

    $p->settlementTransfer($books->company, fyDay(10), $books->vertex, $books->bluefin, 6_00_000_00);
    $after = app(SettlementCalculator::class)->calculate($books->company, fy());

    expect($after->sides[$books->vertex->id]->status())->toBe('settled')
        ->and($after->sides[$books->vertex->id]->paidOut)->toBe(6_00_000_00)
        ->and($after->sides[$books->bluefin->id]->paidIn)->toBe(6_00_000_00)
        ->and($after->transfers)->toBe([]);
});

test('personal cards, members and reimbursements count for their side; remainders still sum to zero', function () {
    $books = jvBooks();
    $p = app(Postings::class);
    $sana = Member::factory()->for($books->company)->create(['partner_id' => $books->kabir->id]);

    $p->incomeToPartner($books->company, fyDay(1), 3_00_000_00, category($books->company, 'income', 'Consulting'), $books->meera);
    $p->expense($books->company, fyDay(2), category($books->company, 'expense', 'Travel'), [PayerLine::partner(15_000_00, $books->aarav), PayerLine::member(5_000_00, $sana)]);
    $p->reimbursement($books->company, fyDay(3), 5_000_00, $sana, $books->account);
    $p->salary($books->company, $sana, 45_000_00, fyDay(29));

    $result = app(SettlementCalculator::class)->calculate($books->company, fy());

    expect($result->sides[$books->bluefin->id]->collected)->toBe(3_00_000_00)
        ->and($result->sides[$books->vertex->id]->paid)->toBe(15_000_00)
        ->and($result->sides[$books->bluefin->id]->paid)->toBe(5_000_00 + 45_000_00)
        ->and($result->companyAccountNet)->toBe(-5_000_00)
        ->and($result->remainderSum())->toBe(0);
});

test('three sides settle with the fewest transfers', function () {
    $books = jvBooks();
    $books->vertex->update(['equity_bps' => 4000]);
    $books->bluefin->update(['equity_bps' => 3000]);
    $third = Side::factory()->for($books->company)->create(['name' => 'Kriya', 'equity_bps' => 3000, 'position' => 2]);
    $p = app(Postings::class);

    $p->incomeToAccount($books->company, fyDay(1), 9_00_000_00, category($books->company, 'income', 'Project'), app(ChartOfAccounts::class)->sideAccount($third));

    $result = app(SettlementCalculator::class)->calculate($books->company, fy());

    expect($result->remainderSum())->toBe(0)
        ->and($result->sides[$third->id]->remaining)->toBe(6_30_000_00)
        ->and(collect($result->transfers)->map(fn ($t) => [$t['from']->name, $t['to']->name, $t['amount']])->all())
        ->toBe([['Kriya', 'Vertex Labs', 3_60_000_00], ['Kriya', 'Bluefin Infotech', 2_70_000_00]]);
});

test('a Single company has no settlements', function () {
    expect(app(SettlementCalculator::class)->calculate(singleBooks()->company, fy())->sides)->toBe([]);
});

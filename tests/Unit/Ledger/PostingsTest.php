<?php

declare(strict_types=1);

use App\Enums\CostType;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Member;

function postings(): Postings
{
    return app(Postings::class);
}

/**
 * An entry's lines as "code: Dr|Cr amount [p=… s=… m=…]" strings, for exact comparison.
 *
 * @return list<string>
 */
function linesOf(JournalEntry $entry): array
{
    return JournalLine::query()->withoutGlobalScopes()->with(['account' => fn ($q) => $q->withoutGlobalScopes()])
        ->where('journal_entry_id', $entry->id)->orderBy('id')->get()
        ->map(function (JournalLine $l) {
            $dims = array_filter(['p' => $l->partner_id, 's' => $l->side_id, 'm' => $l->member_id]);
            $dimText = $dims === [] ? '' : ' ['.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($dims), $dims)).']';

            return $l->account->name.': '.($l->debit_paise > 0 ? 'Dr '.$l->debit_paise : 'Cr '.$l->credit_paise).$dimText;
        })->all();
}

test('the opening balance goes to capital by effective equity', function () {
    $books = jvBooks(openingPaise: 1_00_000_01);

    $entry = app(OpeningBalance::class)->post($books->company, fyDay(30));

    expect($entry->date->toDateString())->toBe('2026-04-01')
        ->and(linesOf($entry))->toBe([
            'Company account: Dr 10000001',
            "Capital — Aarav Mehta: Cr 3000001 [p={$books->aarav->id} s={$books->vertex->id}]",
            "Capital — Ishita Rao: Cr 2000000 [p={$books->ishita->id} s={$books->vertex->id}]",
            "Capital — Kabir Shah: Cr 2500000 [p={$books->kabir->id} s={$books->bluefin->id}]",
            "Capital — Meera Iyer: Cr 2500000 [p={$books->meera->id} s={$books->bluefin->id}]",
        ]);
});

test('changing the opening balance reverses and re-posts it', function () {
    $books = singleBooks(openingPaise: 2_50_000_00);
    $opening = app(OpeningBalance::class);
    $first = $opening->post($books->company, fyDay());

    $books->company->update(['opening_balance_paise' => 3_00_000_00]);
    $second = $opening->repost($books->company->fresh());

    expect($first->fresh()->reversed_by_id)->not->toBeNull()
        ->and($opening->current($books->company)->id)->toBe($second->id)
        ->and(linesOf($second)[0])->toBe('Company account: Dr 30000000');
});

test('income into a side account and income kept by a partner', function () {
    $books = jvBooks();
    $project = category($books->company, 'income', 'Project');

    $toAccount = postings()->incomeToAccount($books->company, fyDay(3), 5_00_000_00, $project, $books->vertexAccount, broughtBy: $books->ishita);
    $personal = postings()->incomeToPartner($books->company, fyDay(4), 80_000_00, $project, $books->kabir);

    expect(linesOf($toAccount))->toBe([
        "Vertex Labs account: Dr 50000000 [s={$books->vertex->id}]",
        "Income — Project: Cr 50000000 [p={$books->ishita->id} s={$books->vertex->id}]",
    ])->and(linesOf($personal))->toBe([
        "Partner current — Kabir Shah: Dr 8000000 [p={$books->kabir->id} s={$books->bluefin->id}]",
        "Income — Project: Cr 8000000 [p={$books->kabir->id} s={$books->bluefin->id}]",
    ]);
});

test('an expense posts one pair per payer, by account, personal card or member', function () {
    $books = jvBooks();
    $rohan = Member::factory()->for($books->company)->create(['name' => 'Rohan Desai', 'partner_id' => $books->aarav->id]);
    $software = category($books->company, 'expense', 'Software');

    $entry = postings()->expense($books->company, fyDay(8), $software, [
        PayerLine::company(10_000_00, $books->bluefinAccount),
        PayerLine::partner(2_000_00, $books->meera),
        PayerLine::member(500_00, $rohan),
    ], memo: 'AWS — October bill');

    expect(linesOf($entry))->toBe([
        "Expenses — Software: Dr 1000000 [s={$books->bluefin->id}]",
        "Bluefin Infotech account: Cr 1000000 [s={$books->bluefin->id}]",
        "Expenses — Software: Dr 200000 [p={$books->meera->id} s={$books->bluefin->id}]",
        "Partner current — Meera Iyer: Cr 200000 [p={$books->meera->id} s={$books->bluefin->id}]",
        "Expenses — Software: Dr 50000 [p={$books->aarav->id} s={$books->vertex->id} m={$rohan->id}]",
        "Reimbursements payable — Rohan Desai: Cr 50000 [p={$books->aarav->id} s={$books->vertex->id} m={$rohan->id}]",
    ]);
});

test('reimbursements, salary, capital, loans and settlement transfers', function () {
    $books = jvBooks();
    $dev = Member::factory()->for($books->company)->create(['name' => 'Dev Patel', 'partner_id' => $books->kabir->id, 'cost_type' => CostType::FixedMonthly, 'rate_paise' => 40_000_00]);

    $salary = postings()->salary($books->company, $dev, 40_000_00, fyDay(29));
    $reimburse = postings()->reimbursement($books->company, fyDay(30), 500_00, $dev, $books->bluefinAccount);
    $contribution = postings()->contribution($books->company, fyDay(31), $books->aarav, 1_00_000_00, $books->vertexAccount);
    $drawing = postings()->drawing($books->company, fyDay(32), $books->aarav, 25_000_00, $books->vertexAccount);
    $loan = postings()->loan($books->company, fyDay(33), $books->meera, 50_000_00, $books->bluefinAccount);
    $repayment = postings()->repayment($books->company, fyDay(34), $books->meera, 20_000_00, $books->bluefinAccount);
    $conversion = postings()->convertToLoan($books->company, fyDay(35), $books->meera, 2_000_00);
    $transfer = postings()->settlementTransfer($books->company, fyDay(36), $books->vertex, $books->bluefin, 3_24_600_00, 'NEFT 4471');

    $k = $books->kabir->id;
    $m = $books->meera->id;
    $b = $books->bluefin->id;
    $v = $books->vertex->id;
    $a = $books->aarav->id;

    expect($salary->memo)->toBe('Salary — Dev Patel — 2026-04')
        ->and(linesOf($salary))->toBe(["Expenses — Salary: Dr 4000000 [p={$k} s={$b} m={$dev->id}]", "Bluefin Infotech account: Cr 4000000 [s={$b}]"])
        ->and(linesOf($reimburse))->toBe(["Reimbursements payable — Dev Patel: Dr 50000 [p={$k} s={$b} m={$dev->id}]", "Bluefin Infotech account: Cr 50000 [s={$b}]"])
        ->and(linesOf($contribution))->toBe(["Vertex Labs account: Dr 10000000 [s={$v}]", "Capital — Aarav Mehta: Cr 10000000 [p={$a} s={$v}]"])
        ->and(linesOf($drawing))->toBe(["Capital — Aarav Mehta: Dr 2500000 [p={$a} s={$v}]", "Vertex Labs account: Cr 2500000 [s={$v}]"])
        ->and(linesOf($loan))->toBe(["Bluefin Infotech account: Dr 5000000 [s={$b}]", "Loans payable — Meera Iyer: Cr 5000000 [p={$m} s={$b}]"])
        ->and(linesOf($repayment))->toBe(["Loans payable — Meera Iyer: Dr 2000000 [p={$m} s={$b}]", "Bluefin Infotech account: Cr 2000000 [s={$b}]"])
        ->and(linesOf($conversion))->toBe(["Partner current — Meera Iyer: Dr 200000 [p={$m} s={$b}]", "Loans payable — Meera Iyer: Cr 200000 [p={$m} s={$b}]"])
        ->and(linesOf($transfer))->toBe(["Bluefin Infotech account: Dr 32460000 [s={$b}]", "Vertex Labs account: Cr 32460000 [s={$v}]"]);
});

test('a Single company’s salary is paid from the company account', function () {
    $books = singleBooks();
    $neha = Member::factory()->for($books->company)->create(['name' => 'Neha Joshi', 'partner_id' => $books->ishita->id]);

    expect(linesOf(postings()->salary($books->company, $neha, 32_000_00, fyDay(29)))[1])->toBe('Company account: Cr 3200000');
});

test('guards: wrong category kind, company paying personally, a transfer to the same side', function () {
    $books = jvBooks();

    expect(fn () => postings()->incomeToAccount($books->company, fyDay(), 100, category($books->company, 'expense', 'Travel'), $books->account))
        ->toThrow(LedgerException::class)
        ->and(fn () => postings()->settlementTransfer($books->company, fyDay(), $books->vertex, $books->vertex, 100))
        ->toThrow(LedgerException::class, 'Choose two different sides.');
});

test('the chart gets spec codes per role', function () {
    $books = singleBooks();
    $codes = Account::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->orderBy('code')->pluck('name', 'code')->all();

    expect($codes)->toMatchArray([
        '1000-1' => 'Company account',
        '1200-1' => 'Partner current — Aarav Mehta',
        '1200-2' => 'Partner current — Ishita Rao',
        '3000-1' => 'Capital — Aarav Mehta',
        '4001' => 'Income — Project',
        '5001' => 'Expenses — Salary',
        '5006' => 'Expenses — Office & misc',
    ]);
});

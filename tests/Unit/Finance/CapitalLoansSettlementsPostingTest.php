<?php

declare(strict_types=1);

use App\Actions\Capital\RecordCapitalMovement;
use App\Actions\Loans\LoanData;
use App\Actions\Loans\RecordLoan;
use App\Actions\Reimbursements\PayReimbursement;
use App\Actions\Settlements\RecordSettlementTransfer;
use App\Enums\CapitalMovementKind;
use App\Enums\CostType;
use App\Enums\JournalType;
use App\Ledger\FiscalYears;
use App\Ledger\IntegrityCheck;
use App\Ledger\PayerLine;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Ledger\SettlementCalculator;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\User;
use App\Services\Finance\PersonalCardBalances;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** The entry a record posted, and the one that reversed it (if any). */
function postedEntry(?int $id): JournalEntry
{
    return JournalEntry::query()->withoutGlobalScopes()->findOrFail($id);
}

function reversalOf(?int $id): JournalEntry
{
    return JournalEntry::query()->withoutGlobalScopes()->where('reverses_id', $id)->sole();
}

test('a contribution posts Dr account, Cr capital; a drawing the mirror; deleting reverses', function () {
    $books = jvBooks();
    $by = User::factory()->create();
    $record = app(RecordCapitalMovement::class);
    $a = $books->aarav->id;
    $v = $books->vertex->id;

    $in = $record->record($books->company, CapitalMovementKind::Contribution, $books->aarav, 2_00_000_00, fyDay(11), $books->vertexAccount, 'Initial capital', $by);
    $out = $record->record($books->company, CapitalMovementKind::Drawing, $books->aarav, 1_50_000_00, fyDay(182), $books->vertexAccount, null, $by);

    expect(postedEntry($in->journal_entry_id)->type)->toBe(JournalType::Contribution)
        ->and(postedEntry($in->journal_entry_id)->source_id)->toBe($in->id)
        ->and(journalLines($in->journal_entry_id))->toBe(["Vertex Labs account: Dr 20000000 [s={$v}]", "Capital — Aarav Mehta: Cr 20000000 [p={$a} s={$v}]"])
        ->and(postedEntry($out->journal_entry_id)->type)->toBe(JournalType::Drawing)
        ->and(journalLines($out->journal_entry_id))->toBe(["Capital — Aarav Mehta: Dr 15000000 [p={$a} s={$v}]", "Vertex Labs account: Cr 15000000 [s={$v}]"]);

    $record->delete($books->company, $out, $by);

    expect($out->refresh()->trashed())->toBeTrue()
        ->and(journalLines(reversalOf($out->journal_entry_id)))->toBe(["Capital — Aarav Mehta: Cr 15000000 [p={$a} s={$v}]", "Vertex Labs account: Dr 15000000 [s={$v}]"]);
});

test('a loan posts Dr account, Cr loans payable; repayments close it at ₹0 and never exceed what is outstanding', function () {
    $books = jvBooks();
    $by = User::factory()->create();
    $loans = app(RecordLoan::class);
    $k = $books->kabir->id;
    $b = $books->bluefin->id;

    $loan = $loans->lend($books->company, new LoanData($books->kabir, 1_00_000_00, fyDay(2), $books->bluefinAccount, 'Opening working capital', 50_000_00), $by);

    expect($loan->code())->toBe('L-01')
        ->and(postedEntry($loan->journal_entry_id)->type)->toBe(JournalType::Loan)
        ->and(journalLines($loan->journal_entry_id))->toBe(["Bluefin Infotech account: Dr 10000000 [s={$b}]", "Loans payable — Kabir Shah: Cr 10000000 [p={$k} s={$b}]"]);

    $first = $loans->repay($books->company, $loan, 50_000_00, fyDay(90), $books->bluefinAccount, 'June instalment', $by);
    expect(journalLines($first->journal_entry_id))->toBe(["Loans payable — Kabir Shah: Dr 5000000 [p={$k} s={$b}]", "Bluefin Infotech account: Cr 5000000 [s={$b}]"])
        ->and($loan->refresh()->closed_on)->toBeNull();

    expect(fn () => $loans->repay($books->company, $loan, 50_000_01, fyDay(150), $books->bluefinAccount, null, $by))
        ->toThrow(ValidationException::class, 'That’s more than the ₹50,000 outstanding.');
    expect(fn () => $loans->repay($books->company, $loan, 10_00, fyDay(1), $books->bluefinAccount, null, $by))
        ->toThrow(ValidationException::class);

    $last = $loans->repay($books->company, $loan, 50_000_00, fyDay(182), $books->bluefinAccount, null, $by);
    expect($loan->refresh()->closed_on?->toDateString())->toBe(fyDay(182)->toDateString())
        ->and(fn () => $loans->repay($books->company, $loan, 1_00, fyDay(183), $books->bluefinAccount, null, $by))
        ->toThrow(ValidationException::class, 'L-01 is already repaid.');

    // Deleting a repayment reverses it and reopens the loan; a loan with repayments can't be deleted.
    $loans->deleteRepayment($books->company, $last, $by);
    expect($loan->refresh()->closed_on)->toBeNull()
        ->and(journalLines(reversalOf($last->journal_entry_id)))->toBe(["Loans payable — Kabir Shah: Cr 5000000 [p={$k} s={$b}]", "Bluefin Infotech account: Dr 5000000 [s={$b}]"])
        ->and(fn () => $loans->delete($books->company, $loan, $by))->toThrow(ValidationException::class, 'L-01 has repayments. Delete those first.');

    $second = $loans->lend($books->company, new LoanData($books->kabir, 20_000_00, fyDay(5), $books->bluefinAccount), $by);
    $loans->delete($books->company, $second, $by);
    expect($second->refresh()->trashed())->toBeTrue()
        ->and(postedEntry($second->journal_entry_id)->isReversed())->toBeTrue()
        // Numbers are never reused.
        ->and($loans->lend($books->company, new LoanData($books->kabir, 1_00, fyDay(6), $books->bluefinAccount), $by)->code())->toBe('L-03');
});

test('a personal-card balance converts into a loan: Dr partner current, Cr loans payable, never more than owed', function () {
    $books = jvBooks();
    $by = User::factory()->create();
    $m = $books->meera->id;
    $b = $books->bluefin->id;
    app(Postings::class)->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::partner(18_450_00, $books->meera)]);

    expect(app(PersonalCardBalances::class)->owedTo($books->meera))->toBe(18_450_00);

    $loans = app(RecordLoan::class);
    expect(fn () => $loans->lend($books->company, new LoanData($books->meera, 18_450_01, fyDay(21), null), $by))
        ->toThrow(ValidationException::class, 'That’s more than the ₹18,450 owed to Meera Iyer.');

    $loan = $loans->lend($books->company, new LoanData($books->meera, 10_000_00, fyDay(21), null, 'AWS bill'), $by);

    expect($loan->converted)->toBeTrue()
        ->and(postedEntry($loan->journal_entry_id)->type)->toBe(JournalType::LoanConversion)
        ->and(journalLines($loan->journal_entry_id))->toBe(["Partner current — Meera Iyer: Dr 1000000 [p={$m} s={$b}]", "Loans payable — Meera Iyer: Cr 1000000 [p={$m} s={$b}]"])
        ->and(app(PersonalCardBalances::class)->owedTo($books->meera))->toBe(8_450_00)
        ->and(fn () => $loans->lend($books->company, new LoanData($books->kabir, 1_00, fyDay(21), null), $by))
        ->toThrow(ValidationException::class, 'The company doesn’t owe Kabir Shah anything for personal-card spending.');
});

test('payouts pay members and partners back: Dr reimbursements payable / partner current, Cr account', function () {
    $books = jvBooks();
    $by = User::factory()->create();
    $rohan = Member::factory()->for($books->company)->create(['name' => 'Rohan Desai', 'partner_id' => $books->aarav->id, 'cost_type' => CostType::Hourly, 'rate_paise' => 500_00]);
    $software = category($books->company, 'expense', 'Software');
    app(Postings::class)->expense($books->company, fyDay(20), $software, [PayerLine::member(4_200_00, $rohan), PayerLine::partner(12_980_00, $books->kabir)]);
    $pay = app(PayReimbursement::class);
    $a = $books->aarav->id;
    $v = $books->vertex->id;
    $k = $books->kabir->id;
    $b = $books->bluefin->id;

    expect(collect(app(PersonalCardBalances::class)->owed($books->company))->map(fn ($r) => [$r['type'], $r['name'], $r['owedPaise']])->all())
        ->toBe([['partner', 'Kabir Shah', 12_980_00], ['member', 'Rohan Desai', 4_200_00]]);

    $toRohan = $pay->pay($books->company, $rohan, 4_200_00, fyDay(25), $books->vertexAccount, 'Client visit', $by);
    $toKabir = $pay->pay($books->company, $books->kabir, 2_980_00, fyDay(25), $books->bluefinAccount, null, $by);

    expect(postedEntry($toRohan->journal_entry_id)->type)->toBe(JournalType::Reimbursement)
        ->and(journalLines($toRohan->journal_entry_id))->toBe(["Reimbursements payable — Rohan Desai: Dr 420000 [p={$a} s={$v} m={$rohan->id}]", "Vertex Labs account: Cr 420000 [s={$v}]"])
        ->and(journalLines($toKabir->journal_entry_id))->toBe(["Partner current — Kabir Shah: Dr 298000 [p={$k} s={$b}]", "Bluefin Infotech account: Cr 298000 [s={$b}]"])
        ->and(fn () => $pay->pay($books->company, $rohan, 1_00, fyDay(26), $books->vertexAccount, null, $by))
        ->toThrow(ValidationException::class, 'Nothing is owed to Rohan Desai for personal-card spending.')
        ->and(fn () => $pay->pay($books->company, $books->kabir, 10_000_01, fyDay(26), $books->bluefinAccount, null, $by))
        ->toThrow(ValidationException::class, 'That’s more than the ₹10,000 owed to Kabir Shah.');

    $pay->delete($books->company, $toKabir, $by);
    expect(app(PersonalCardBalances::class)->owedTo($books->kabir))->toBe(12_980_00)
        ->and(journalLines(reversalOf($toKabir->journal_entry_id)))->toBe(["Partner current — Kabir Shah: Cr 298000 [p={$k} s={$b}]", "Bluefin Infotech account: Dr 298000 [s={$b}]"]);
});

test('settlement transfers post, re-post on edit and reverse on delete; remainders still sum to zero', function () {
    $books = jvBooks();
    $by = User::factory()->create();
    $postings = app(Postings::class);
    $project = category($books->company, 'income', 'Project');
    $software = category($books->company, 'expense', 'Software');
    $postings->incomeToAccount($books->company, fyDay(10), 10_00_000_00, $project, $books->vertexAccount, $books->aarav);
    $postings->incomeToAccount($books->company, fyDay(11), 2_00_000_00, $project, $books->bluefinAccount, $books->kabir);
    $postings->incomeToAccount($books->company, fyDay(12), 50_000_00, $project, $books->account);
    $postings->expense($books->company, fyDay(13), $software, [PayerLine::company(1_00_000_00, $books->bluefinAccount)]);
    $postings->expense($books->company, fyDay(14), $software, [PayerLine::partner(10_000_00, $books->meera)]);
    // Capital and loans don't move settlements (spec §13).
    app(RecordCapitalMovement::class)->record($books->company, CapitalMovementKind::Contribution, $books->aarav, 5_00_000_00, fyDay(15), $books->vertexAccount, null, $by);
    app(RecordLoan::class)->lend($books->company, new LoanData($books->kabir, 3_00_000_00, fyDay(15), $books->bluefinAccount), $by);

    $year = Period::fiscalYearOf($books->company, fyDay());
    $calc = app(SettlementCalculator::class);
    $before = $calc->calculate($books->company, $year);
    $owed = $before->sides[$books->vertex->id]->remaining;

    expect($before->remainderSum())->toBe(0)
        // Net profit 11,40,000 → 5,70,000 each; Vertex holds 10,00,000 + 25,000 pooled.
        ->and($owed)->toBe(4_55_000_00)
        ->and($before->transfers[0]['amount'])->toBe($owed);

    $transfers = app(RecordSettlementTransfer::class);
    $v = $books->vertex->id;
    $b = $books->bluefin->id;
    $t = $transfers->record($books->company, $books->vertex, $books->bluefin, 2_00_000_00, fyDay(60), 'NEFT 0842', $by);

    expect(postedEntry($t->journal_entry_id)->type)->toBe(JournalType::Settlement)
        ->and(journalLines($t->journal_entry_id))->toBe(["Bluefin Infotech account: Dr 20000000 [s={$b}]", "Vertex Labs account: Cr 20000000 [s={$v}]"])
        ->and($calc->calculate($books->company, $year)->sides[$v]->remaining)->toBe(2_55_000_00);

    $old = $t->journal_entry_id;
    $transfers->update($books->company, $t, $books->vertex, $books->bluefin, $owed, fyDay(61), 'NEFT 0843', $by);

    $after = $calc->calculate($books->company, $year);
    expect(postedEntry($old)->isReversed())->toBeTrue()
        ->and(journalLines($t->refresh()->journal_entry_id))->toBe(["Bluefin Infotech account: Dr 45500000 [s={$b}]", "Vertex Labs account: Cr 45500000 [s={$v}]"])
        ->and($after->remainderSum())->toBe(0)
        ->and(collect($after->sides)->map->status()->unique()->values()->all())->toBe(['settled'])
        ->and($after->transfers)->toBe([]);

    // A reference alone doesn't re-post.
    $entry = $t->journal_entry_id;
    $transfers->update($books->company, $t, $books->vertex, $books->bluefin, $owed, fyDay(61), 'UTR 1', $by);
    expect($t->refresh()->journal_entry_id)->toBe($entry);

    $transfers->delete($books->company, $t, $by);
    expect($calc->calculate($books->company, $year)->sides[$v]->remaining)->toBe($owed)
        ->and($calc->calculate($books->company, $year)->remainderSum())->toBe(0)
        ->and(app(IntegrityCheck::class)->run($books->company, CarbonImmutable::create(2027, 3, 31)))->toBe([]);
});

test('a closed fiscal year rejects every new record', function () {
    $books = jvBooks();
    $by = User::factory()->create();
    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2025-12-01'))->update(['closed_at' => now()]);
    $day = CarbonImmutable::parse('2025-12-01');
    $locked = 'FY 2025-26 is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.';

    expect(fn () => app(RecordCapitalMovement::class)->record($books->company, CapitalMovementKind::Contribution, $books->aarav, 1_00, $day, $books->vertexAccount, null, $by))->toThrow(ValidationException::class, $locked)
        ->and(fn () => app(RecordLoan::class)->lend($books->company, new LoanData($books->aarav, 1_00, $day, $books->vertexAccount), $by))->toThrow(ValidationException::class, $locked)
        ->and(fn () => app(RecordSettlementTransfer::class)->record($books->company, $books->vertex, $books->bluefin, 1_00, $day, null, $by))->toThrow(ValidationException::class, $locked)
        ->and(fn () => app(PayReimbursement::class)->pay($books->company, $books->aarav, 1_00, $day, $books->vertexAccount, null, $by))->toThrow(ValidationException::class, $locked);
});

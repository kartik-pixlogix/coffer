<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\CategoryKind;
use App\Enums\JournalType;
use App\Enums\TdsSection;
use App\Ledger\Exceptions\LedgerException;
use App\Models\Category;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One method per row of spec §4.2. Each builds the lines with the dimensions that
 * attribution (§4.4) and settlements (§13) read, and posts through LedgerPoster.
 *
 * Dimension rules:
 * - Holdings lines (money accounts, partner current, reimbursements payable) carry the
 *   side that holds the money; the company account carries none.
 * - Income lines carry the partner who brought the money in.
 * - Expense lines carry who the cost is attributed to: the paying partner, the paying
 *   member and their partner, or (paid from a side account by the company) the side.
 */
final class Postings
{
    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerPoster $poster,
    ) {}

    /**
     * Income received into money account M: Dr M, Cr Income:category. With tax (D161), `$amount`
     * is what was received; the income is the taxable value, GST charged is owed as output
     * tax and TDS the client held back is claimed as TDS receivable.
     */
    public function incomeToAccount(Company $company, CarbonImmutable $date, int $amount, Category $category, MoneyAccount $into, ?Partner $broughtBy = null, ?int $projectId = null, ?string $memo = null, ?Model $source = null, ?User $by = null, ?IncomeTax $tax = null): JournalEntry
    {
        $this->assertKind($category, CategoryKind::Income);

        return $this->post($company, $date, JournalType::Income, [
            LineData::debit($this->chart->money($into), $amount, sideId: $into->side_id, projectId: $projectId),
            ...$this->incomeLines($company, $amount, $category, $broughtBy, $projectId, $tax),
        ], $memo, $source, $by);
    }

    /** Income received personally by partner P: Dr Partner current P, Cr Income:category. */
    public function incomeToPartner(Company $company, CarbonImmutable $date, int $amount, Category $category, Partner $receivedBy, ?Partner $broughtBy = null, ?int $projectId = null, ?string $memo = null, ?Model $source = null, ?User $by = null, ?IncomeTax $tax = null): JournalEntry
    {
        $this->assertKind($category, CategoryKind::Income);
        $broughtBy ??= $receivedBy;

        return $this->post($company, $date, JournalType::Income, [
            LineData::debit($this->chart->partnerCurrent($receivedBy), $amount, partnerId: $receivedBy->id, sideId: $receivedBy->side_id, projectId: $projectId),
            ...$this->incomeLines($company, $amount, $category, $broughtBy, $projectId, $tax),
        ], $memo, $source, $by);
    }

    /**
     * An approved expense, one pair of lines per payer (spec §4.2, §10):
     * from money account M → Cr M; partner's personal card → Cr Partner current;
     * member's personal card → Cr Reimbursements payable.
     *
     * With tax (D161) the payers pay the bill less TDS; the cost (taxable value, plus GST that
     * can't be claimed) is shared over the payer lines by what each paid, so attribution keeps
     * its dimensions, while input tax, reverse-charge GST and TDS payable go on company-level
     * tax accounts with no side or partner: they belong to the whole firm (and pool across a
     * JV's sides in settlements).
     *
     * @param  list<PayerLine>  $payers
     */
    public function expense(Company $company, CarbonImmutable $date, Category $category, array $payers, ?int $projectId = null, ?string $memo = null, ?Model $source = null, ?User $by = null, JournalType $type = JournalType::Expense, ?ExpenseTax $tax = null): JournalEntry
    {
        $this->assertKind($category, CategoryKind::Expense);

        if ($payers === []) {
            throw new LedgerException('An expense needs at least one payer.');
        }

        $expense = $this->chart->expense($category);
        $paid = array_sum(array_map(fn (PayerLine $p) => $p->amount, $payers));

        if ($tax !== null && $paid !== $tax->payable()) {
            throw new LedgerException('The payers must add up to the bill less TDS ('.Money::format($tax->payable()).').');
        }

        $costs = $tax === null
            ? array_map(fn (PayerLine $p) => $p->amount, $payers)
            : Rounding::largestRemainder($tax->cost(), array_map(fn (PayerLine $p) => $p->amount, $payers));
        $lines = [];

        foreach ($payers as $i => $payer) {
            [$partnerId, $sideId, $memberId] = $this->attribution($payer);
            $lines[] = LineData::debit($expense, $costs[$i], $partnerId, $sideId, $memberId, $projectId);
            $lines[] = $this->paymentLine($payer);
        }

        if ($tax !== null) {
            foreach ($tax->heads() as $head => $amount) {
                if ($tax->itc) {
                    $lines[] = LineData::debit($this->chart->gstInput($company, $head), $amount);
                }

                if ($tax->reverseCharge) {
                    $lines[] = LineData::credit($this->chart->rcmPayable($company, $head), $amount);
                }
            }

            if ($tax->tds > 0) {
                $lines[] = LineData::credit($this->chart->tdsPayable($company, ($tax->tdsSection ?? throw new LedgerException('TDS needs a section.'))->code()), $tax->tds);
            }
        }

        return $this->post($company, $date, $type, $lines, $memo, $source, $by);
    }

    /** Reimbursement paid out from M to a partner or member who paid personally. */
    public function reimbursement(Company $company, CarbonImmutable $date, int $amount, Partner|Member $to, MoneyAccount $from, ?string $memo = null, ?Model $source = null, ?User $by = null): JournalEntry
    {
        $owed = $to instanceof Partner
            ? LineData::debit($this->chart->partnerCurrent($to), $amount, partnerId: $to->id, sideId: $to->side_id)
            : LineData::debit($this->chart->reimbursementsPayable($to), $amount, partnerId: $to->partner_id, sideId: $this->memberSide($to), memberId: $to->id);

        return $this->post($company, $date, JournalType::Reimbursement, [
            $owed,
            LineData::credit($this->chart->money($from), $amount, sideId: $from->side_id),
        ], $memo ?? 'Reimbursement — '.$to->name, $source, $by);
    }

    /**
     * Salary for member m for a month (spec §8.3): Expense:Salary, paid from m's partner's
     * account (their side's account in a JV, the company account in a Single company).
     */
    public function salary(Company $company, Member $member, int $amount, CarbonImmutable $monthEnd, ?Model $source = null, ?User $by = null, int $tds = 0, ?TdsSection $section = null): JournalEntry
    {
        $partner = $member->partner()->withoutGlobalScopes()->firstOrFail();
        $from = $this->chart->payingAccountFor($partner);

        return $this->expense(
            $company,
            $monthEnd,
            $this->chart->salaryCategory($company),
            [PayerLine::member($amount - $tds, $member, $from)],
            memo: 'Salary — '.$member->name.' — '.$monthEnd->format('Y-m'),
            source: $source,
            by: $by,
            type: JournalType::Salary,
            // TDS on salary (D161): the member is paid the net; the TDS is owed to the government.
            tax: $tds > 0 ? new ExpenseTax($amount, tdsSection: $section ?? TdsSection::Salary, tds: $tds) : null,
        );
    }

    /** Capital contribution by P into M: Dr M, Cr Capital P. */
    public function contribution(Company $company, CarbonImmutable $date, Partner $partner, int $amount, MoneyAccount $into, ?string $memo = null, ?Model $source = null, ?User $by = null): JournalEntry
    {
        return $this->post($company, $date, JournalType::Contribution, [
            LineData::debit($this->chart->money($into), $amount, sideId: $into->side_id),
            LineData::credit($this->chart->capital($partner), $amount, partnerId: $partner->id, sideId: $partner->side_id),
        ], $memo, $source, $by);
    }

    /** Drawing by P from M: Dr Capital P, Cr M. */
    public function drawing(Company $company, CarbonImmutable $date, Partner $partner, int $amount, MoneyAccount $from, ?string $memo = null, ?Model $source = null, ?User $by = null): JournalEntry
    {
        return $this->post($company, $date, JournalType::Drawing, [
            LineData::debit($this->chart->capital($partner), $amount, partnerId: $partner->id, sideId: $partner->side_id),
            LineData::credit($this->chart->money($from), $amount, sideId: $from->side_id),
        ], $memo, $source, $by);
    }

    /** Loan from partner P into M: Dr M, Cr Loans payable P. */
    public function loan(Company $company, CarbonImmutable $date, Partner $partner, int $amount, MoneyAccount $into, ?string $memo = null, ?Model $source = null, ?User $by = null): JournalEntry
    {
        return $this->post($company, $date, JournalType::Loan, [
            LineData::debit($this->chart->money($into), $amount, sideId: $into->side_id),
            LineData::credit($this->chart->loansPayable($partner), $amount, partnerId: $partner->id, sideId: $partner->side_id),
        ], $memo, $source, $by);
    }

    /** Loan repayment to P from M: Dr Loans payable P, Cr M. */
    public function repayment(Company $company, CarbonImmutable $date, Partner $partner, int $amount, MoneyAccount $from, ?string $memo = null, ?Model $source = null, ?User $by = null): JournalEntry
    {
        return $this->post($company, $date, JournalType::Repayment, [
            LineData::debit($this->chart->loansPayable($partner), $amount, partnerId: $partner->id, sideId: $partner->side_id),
            LineData::credit($this->chart->money($from), $amount, sideId: $from->side_id),
        ], $memo, $source, $by);
    }

    /** Turn what the company owes P for personal-card spending into a loan (spec §12). */
    public function convertToLoan(Company $company, CarbonImmutable $date, Partner $partner, int $amount, ?string $memo = null, ?Model $source = null, ?User $by = null): JournalEntry
    {
        return $this->post($company, $date, JournalType::LoanConversion, [
            LineData::debit($this->chart->partnerCurrent($partner), $amount, partnerId: $partner->id, sideId: $partner->side_id),
            LineData::credit($this->chart->loansPayable($partner), $amount, partnerId: $partner->id, sideId: $partner->side_id),
        ], $memo ?? 'Personal-card balance converted to a loan', $source, $by);
    }

    /** Settlement transfer from side A to side B (spec §13): Dr B's account, Cr A's account. */
    public function settlementTransfer(Company $company, CarbonImmutable $date, Side $from, Side $to, int $amount, ?string $reference = null, ?Model $source = null, ?User $by = null): JournalEntry
    {
        if ($from->id === $to->id) {
            throw new LedgerException('Choose two different sides.');
        }

        return $this->post($company, $date, JournalType::Settlement, [
            LineData::debit($this->chart->money($this->chart->sideAccount($to)), $amount, sideId: $to->id),
            LineData::credit($this->chart->money($this->chart->sideAccount($from)), $amount, sideId: $from->id),
        ], $reference ? "Settlement {$from->name} → {$to->name} ({$reference})" : "Settlement {$from->name} → {$to->name}", $source, $by);
    }

    /**
     * The credit side of income: the income itself (with who brought it in), plus GST output
     * and TDS receivable when taxed (D161). Tax lines carry no side or partner.
     *
     * @return list<LineData>
     */
    private function incomeLines(Company $company, int $received, Category $category, ?Partner $broughtBy, ?int $projectId, ?IncomeTax $tax): array
    {
        if ($tax === null) {
            return [LineData::credit($this->chart->income($category), $received, partnerId: $broughtBy?->id, sideId: $broughtBy?->side_id, projectId: $projectId)];
        }

        if ($tax->received() !== $received) {
            throw new LedgerException('The amount received must be the invoice less TDS ('.Money::format($tax->received()).').');
        }

        $lines = [LineData::credit($this->chart->income($category), $tax->taxable, partnerId: $broughtBy?->id, sideId: $broughtBy?->side_id, projectId: $projectId)];

        foreach ($tax->heads() as $head => $amount) {
            $lines[] = LineData::credit($this->chart->gstOutput($company, $head), $amount);
        }

        if ($tax->tds > 0) {
            $lines[] = LineData::debit($this->chart->tdsReceivable($company), $tax->tds);
        }

        return $lines;
    }

    /** @return array{0: int|null, 1: int|null, 2: int|null} partner, side, member the cost is attributed to */
    private function attribution(PayerLine $payer): array
    {
        if ($payer->partner !== null) {
            return [$payer->partner->id, $payer->partner->side_id, null];
        }

        if ($payer->member !== null) {
            return [$payer->member->partner_id, $this->memberSide($payer->member), $payer->member->id];
        }

        // The company paid: from a side account it's that side's cost; from the company account, nobody's.
        return [null, $payer->from?->side_id, null];
    }

    private function paymentLine(PayerLine $payer): LineData
    {
        if (! $payer->isPersonal()) {
            /** @var MoneyAccount $from */
            $from = $payer->from;

            return LineData::credit($this->chart->money($from), $payer->amount, sideId: $from->side_id);
        }

        if ($payer->partner !== null) {
            return LineData::credit($this->chart->partnerCurrent($payer->partner), $payer->amount, partnerId: $payer->partner->id, sideId: $payer->partner->side_id);
        }

        if ($payer->member !== null) {
            return LineData::credit($this->chart->reimbursementsPayable($payer->member), $payer->amount, partnerId: $payer->member->partner_id, sideId: $this->memberSide($payer->member), memberId: $payer->member->id);
        }

        throw new LedgerException('The company can only pay from one of its accounts.');
    }

    /** The side of the partner a member bills to (JV), or null. */
    private function memberSide(Member $member): ?int
    {
        $sideId = Partner::query()->withoutGlobalScope('company')->whereKey($member->partner_id)->value('side_id');

        return is_numeric($sideId) ? (int) $sideId : null;
    }

    private function assertKind(Category $category, CategoryKind $kind): void
    {
        if ($category->kind !== $kind) {
            throw new LedgerException("“{$category->name}” isn’t an {$kind->value} category.");
        }
    }

    /** @param list<LineData> $lines */
    private function post(Company $company, CarbonImmutable $date, JournalType $type, array $lines, ?string $memo, ?Model $source, ?User $by): JournalEntry
    {
        return $this->poster->post($company, new EntryData($date, $type, $lines, $memo, $source, $by));
    }
}

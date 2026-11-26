<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Enums\CategoryKind;
use App\Enums\ScheduleLine;
use App\Ledger\FiscalYears;
use App\Ledger\Lines;
use App\Ledger\Period;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chart of accounts (D158): every account under its Schedule III heading, with its code,
 * role and balance today (income and expenses for the year so far). The codes stay as they
 * were given, so exports and notes keep pointing at the same accounts. Admins (the Settings
 * page) choose which Schedule III line each income and expense category reports under.
 */
final class ChartOfAccountsController extends Controller
{
    private const array ROLE_LABELS = [
        'money' => 'Money account',
        'partner_current' => 'Partner current',
        'loans_payable' => 'Loan from partner',
        'reimbursements_payable' => 'Reimbursement owed',
        'capital' => 'Capital',
        'income' => 'Income',
        'expense' => 'Expense',
        'gst_input' => 'GST input credit',
        'tds_receivable' => 'TDS receivable',
        'gst_output' => 'GST output',
        'rcm_payable' => 'GST payable (reverse charge)',
        'tds_payable' => 'TDS payable',
    ];

    public function index(Request $request, CurrentCompany $current): Response
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $today = CarbonImmutable::today();
        $fyStart = FiscalYears::startOf($company, $today);

        $balances = Lines::in($company, Period::asOf($today))
            ->selectRaw('l.account_id, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as balance')
            ->groupBy('l.account_id')->pluck('balance', 'account_id');
        $yearToDate = Lines::profitAndLoss($company, Period::between($fyStart, $today))
            ->selectRaw('l.account_id, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as balance')
            ->groupBy('l.account_id')->pluck('balance', 'account_id');

        $accounts = Account::query()->with('category:id,kind,name,system,schedule_line')->get()
            ->sortBy('code', SORT_NATURAL)->values();

        $row = function (Account $a) use ($balances, $yearToDate): array {
            $pnl = in_array($a->role, [AccountRole::Income, AccountRole::Expense], true);
            $category = $a->category;

            return [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'role' => self::ROLE_LABELS[$a->role->value],
                'balancePaise' => (int) ($pnl ? ($yearToDate[$a->id] ?? 0) : ($balances[$a->id] ?? 0)),
                'categoryId' => $category?->id,
                'line' => $category === null ? null : $category->scheduleLine()->value,
            ];
        };

        $of = fn (AccountRole ...$roles) => $accounts->filter(fn (Account $a) => in_array($a->role, $roles, true));
        $lineOf = fn (ScheduleLine $line) => $accounts->filter(fn (Account $a) => $a->category !== null && $a->category->scheduleLine() === $line);

        $groups = [
            ['key' => 'capital', 'title' => "Partners' funds", 'heading' => "Equity and liabilities › Partners' funds", 'note' => null, 'accounts' => $of(AccountRole::Capital)],
            ['key' => 'borrowings', 'title' => 'Short-term borrowings', 'heading' => 'Current liabilities', 'note' => 'Loans from partners, repayable on demand.', 'accounts' => $of(AccountRole::LoansPayable)],
            ['key' => 'other_liabilities', 'title' => 'Other current liabilities', 'heading' => 'Current liabilities', 'note' => 'Money owed to members who paid personally.', 'accounts' => $of(AccountRole::ReimbursementsPayable)],
            ['key' => 'partner_current', 'title' => 'Partner current accounts', 'heading' => 'Current assets or current liabilities, by balance', 'note' => 'A debit balance means the partner holds company money (an advance); a credit balance means the company owes the partner.', 'accounts' => $of(AccountRole::PartnerCurrent)],
            ['key' => 'cash', 'title' => 'Cash and cash equivalents', 'heading' => 'Current assets', 'note' => null, 'accounts' => $of(AccountRole::Money)],
            ['key' => 'taxes', 'title' => 'Duties and taxes', 'heading' => 'Current assets (credits) and current liabilities (dues)', 'note' => 'GST input credit and TDS receivable are claimed back; GST output, reverse-charge GST and TDS payable are owed to the government.', 'accounts' => $of(...AccountRole::TAX)],
        ];

        foreach (ScheduleLine::cases() as $line) {
            $groups[] = [
                'key' => $line->value,
                'title' => $line->label(),
                'heading' => $line->kind() === CategoryKind::Income ? 'Statement of profit and loss › Income' : 'Statement of profit and loss › Expenses',
                'note' => null,
                'accounts' => $lineOf($line),
            ];
        }

        return Inertia::render('reports/chart-of-accounts', [
            'asOf' => $today->toDateString(),
            'fyLabel' => FiscalYears::label($company, $fyStart),
            'groups' => array_values(array_filter(array_map(fn (array $g) => [...$g, 'accounts' => $g['accounts']->map($row)->values()->all()], $groups),
                fn (array $g) => $g['accounts'] !== [] || ! in_array($g['key'], [ScheduleLine::FinanceCosts->value, ScheduleLine::Depreciation->value], true))),
            'lines' => [
                'income' => array_map(fn (ScheduleLine $l) => ['value' => $l->value, 'label' => $l->label()], ScheduleLine::for(CategoryKind::Income)),
                'expense' => array_map(fn (ScheduleLine $l) => ['value' => $l->value, 'label' => $l->label()], ScheduleLine::for(CategoryKind::Expense)),
            ],
            'canEdit' => $user->can('page.settings'),
        ]);
    }

    /** Admins pick the Schedule III line a category reports under (audited on the category). */
    public function updateLine(Request $request, Category $category): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('page.settings'), 403);

        $kind = $category->kind;
        $data = $request->validate([
            'schedule_line' => ['required', Rule::in(array_map(fn (ScheduleLine $l) => $l->value, ScheduleLine::for($kind)))],
        ]);

        $category->update(['schedule_line' => $data['schedule_line']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$category->name} now reports under ".ScheduleLine::from($data['schedule_line'])->label()]);

        return back();
    }
}

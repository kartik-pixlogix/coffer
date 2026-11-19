<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Income\RecordIncome;
use App\Enums\AttributionMode;
use App\Enums\CategoryKind;
use App\Enums\JournalType;
use App\Enums\ProjectStatus;
use App\Http\Requests\Finance\IncomeRequest;
use App\Ledger\Reports;
use App\Models\Category;
use App\Models\Income;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use App\Services\Finance\Attachments;
use App\Services\Finance\FiscalYearFilter;
use App\Services\Finance\LedgerCounts;
use App\Support\Csv;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Income (Income.dc.html, spec §9): the fiscal year's KPIs and monthly trend from the
 * books, the entries (sortable, by category, 25 a page), and add / edit / delete, each
 * posting through the ledger.
 */
final class IncomeController extends Controller
{
    public const int PER_PAGE = 25;

    /** Sortable columns → their SQL. */
    private const array SORTS = [
        'date' => 'incomes.received_on',
        'amount' => 'incomes.amount_paise',
        'source' => 'incomes.source',
        'partner' => 'bp.name',
        'category' => 'c.name',
    ];

    public function index(Request $request, CurrentCompany $current, Reports $reports, LedgerCounts $counts): Response
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $fy = FiscalYearFilter::for($company, $request->query('fy'));

        $months = collect($reports->profitAndLossByMonth($company, $fy->period))->pluck('income', 'month');
        $total = (int) $months->sum();
        $previous = $reports->profitAndLoss($company, $fy->previous())['income'];
        $last = $fy->isCurrent() ? $fy->toDate()->to : $fy->period->to;
        $trend = [];

        for ($m = $fy->start; $m->lessThanOrEqualTo($last); $m = $m->addMonth()) {
            $trend[] = ['month' => $m->format('Y-m'), 'paise' => (int) ($months[$m->format('Y-m')] ?? 0)];
        }

        $category = $request->integer('category') ?: null;
        [$sort, $dir] = $this->sort($request);
        $inYear = fn (): Builder => Income::query()
            ->whereBetween('received_on', [$fy->period->from?->toDateString(), $fy->period->to->toDateString()]);

        $chipCounts = $inYear()->groupBy('category_id')->selectRaw('category_id, COUNT(*) as n')->pluck('n', 'category_id');

        $incomes = $inYear()
            ->select('incomes.*')
            ->leftJoin('partners as bp', 'bp.id', '=', 'incomes.brought_in_by')
            ->join('categories as c', 'c.id', '=', 'incomes.category_id')
            ->with(['moneyAccount:id,name', 'receivedBy:id,name', 'project:id,name'])
            ->when($category !== null, fn (Builder $q) => $q->where('incomes.category_id', $category))
            ->orderBy(self::SORTS[$sort], $dir)
            ->orderBy('incomes.id', $dir)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $categories = Category::query()->where('kind', CategoryKind::Income->value)->orderBy('position')->orderBy('name')->get();

        return Inertia::render('income/index', [
            'company' => [
                'name' => $company->name,
                'jv' => $company->isJv(),
                'pooled' => $company->attribution_mode === AttributionMode::JvPooled,
            ],
            'today' => now()->toDateString(),
            'fy' => $fy->props(),
            'filters' => (object) array_filter(['category' => $category, 'sort' => $sort === 'date' ? null : $sort, 'dir' => $dir === 'desc' ? null : $dir]),
            'kpis' => [
                'totalPaise' => $total,
                'previousPaise' => $previous,
                'entries' => $counts->live($company, $fy->period, [JournalType::Income]),
                'activeMonths' => $months->filter(fn ($v) => (int) $v !== 0)->count(),
            ],
            'trend' => $trend,
            'chips' => $categories
                ->filter(fn (Category $c) => $c->archived_at === null || isset($chipCounts[$c->id]))
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'count' => (int) ($chipCounts[$c->id] ?? 0)])
                ->values(),
            'incomes' => $incomes->through(fn (Income $i) => $this->row($i)),
            'options' => [
                'categories' => $categories->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'active' => $c->archived_at === null])->values(),
                'partners' => Partner::query()->orderBy('position')->orderBy('id')->get()
                    ->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'colour' => $p->colour, 'sideId' => $p->side_id, 'active' => $p->archived_at === null])->values(),
                'accounts' => MoneyAccount::query()->orderBy('position')->orderBy('id')->get()
                    ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name, 'sideId' => $a->side_id, 'active' => $a->archived_at === null])->values(),
                'projects' => Project::query()->orderBy('name')->get()
                    ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'active' => $p->status === ProjectStatus::Active])->values(),
                'sources' => Income::query()->select('source')->groupBy('source')->orderByRaw('MAX(received_on) DESC')->limit(50)->pluck('source'),
                // P1 (D116): "Brought in by" starts on the signed-in person's own partner, if any.
                'myPartnerId' => Partner::query()->active()->where('user_id', $user->id)->value('id'),
            ],
            'abilities' => ['manage' => $user->can('action.income.manage')],
        ]);
    }

    public function store(IncomeRequest $request, CurrentCompany $current, RecordIncome $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $income = $record->create($current->require(), $request->incomeData(), $user);

        activity('income')->performedOn($income)->causedBy($user)
            ->withProperties(['amount_paise' => $income->amount_paise])
            ->log('added income');
        Inertia::flash('toast', ['type' => 'success', 'message' => Money::format($income->amount_paise).' added to income']);

        return back();
    }

    public function update(IncomeRequest $request, Income $income, CurrentCompany $current, RecordIncome $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record->update($current->require(), $income, $request->incomeData(), $user);

        activity('income')->performedOn($income)->causedBy($user)
            ->withProperties(['amount_paise' => $income->amount_paise])
            ->log('edited income');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Income updated']);

        return back();
    }

    public function destroy(Request $request, Income $income, CurrentCompany $current, RecordIncome $record): RedirectResponse
    {
        Gate::authorize('delete', $income);
        /** @var User $user */
        $user = $request->user();
        $record->delete($current->require(), $income, $user);

        activity('income')->performedOn($income)->causedBy($user)->log('deleted income');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Income deleted']);

        return back();
    }

    /** The invoice, inline for a preview or with `?download=1`. */
    public function attachment(Request $request, Income $income, Attachments $attachments): StreamedResponse
    {
        Gate::authorize('view', $income);
        abort_if($income->attachment_path === null, 404);

        return $attachments->response($income->attachment_path, (string) $income->attachment_name, $income->attachment_mime, $request->boolean('download'));
    }

    /** CSV of the fiscal year's entries (with the category filter). */
    public function export(Request $request, CurrentCompany $current): StreamedResponse
    {
        $company = $current->require();
        $fy = FiscalYearFilter::for($company, $request->query('fy'));
        $category = $request->integer('category') ?: null;

        $rows = Income::query()
            ->with(['category:id,name', 'broughtInBy:id,name', 'receivedBy:id,name', 'moneyAccount:id,name', 'project:id,name'])
            ->whereBetween('received_on', [$fy->period->from?->toDateString(), $fy->period->to->toDateString()])
            ->when($category !== null, fn (Builder $q) => $q->where('category_id', $category))
            ->orderBy('received_on')->orderBy('id')
            ->get();

        activity('income')->causedBy($request->user())->withProperties(['rows' => $rows->count()])->log('exported income');

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            Csv::put($out, ['Date', 'Source', 'Amount (INR)', 'Category', 'Brought in by', 'Received into', 'Project', 'Notes']);

            foreach ($rows as $i) {
                Csv::put($out, [
                    $i->received_on->toDateString(),
                    $i->source,
                    Money::plain($i->amount_paise),
                    $i->category->name,
                    $i->broughtInBy?->name,
                    $i->moneyAccount->name ?? ($i->receivedBy !== null ? $i->receivedBy->name.' (personally)' : ''),
                    $i->project?->name,
                    $i->notes,
                ]);
            }

            fclose($out);
        }, 'income-'.str_replace(' ', '-', strtolower((string) $fy->props()['label'])).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function row(Income $i): array
    {
        return [
            'id' => $i->id,
            'source' => $i->source,
            'amountPaise' => $i->amount_paise,
            'date' => $i->received_on->toDateString(),
            'categoryId' => $i->category_id,
            'broughtInBy' => $i->brought_in_by,
            'receivedInto' => $i->money_account_id !== null ? 'account:'.$i->money_account_id : 'partner:'.$i->received_by,
            'intoLabel' => $i->moneyAccount !== null ? $i->moneyAccount->name : ($i->receivedBy !== null ? $i->receivedBy->name.' personally' : ''),
            'projectId' => $i->project_id,
            'projectName' => $i->project?->name,
            'notes' => $i->notes,
            'attachment' => $i->attachment_path === null ? null : [
                'name' => (string) $i->attachment_name,
                'size' => (int) $i->attachment_size,
                'url' => route('income.attachment', $i),
            ],
        ];
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    private function sort(Request $request): array
    {
        $sort = $request->string('sort')->toString();
        $sort = array_key_exists($sort, self::SORTS) ? $sort : 'date';

        return [$sort, $request->query('dir') === 'asc' ? 'asc' : 'desc'];
    }
}

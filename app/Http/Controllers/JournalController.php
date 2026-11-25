<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JournalType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Audit\AuditTrail;
use App\Services\Reports\Exports\Pdf;
use App\Services\Reports\StatementDate;
use App\Support\Csv;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Journal, or day book (D154): every voucher in a period with its lines, filtered by
 * type, account (the account's ledger, linked from the trial balance and the chart of
 * accounts) or a search over voucher numbers and memos. 25 a page; CSV and PDF exports
 * (audited). No design board covers it (v2), so it reuses the Statements look.
 */
final class JournalController extends Controller
{
    public const int PER_PAGE = 25;

    public const int PDF_MAX = 3000;

    public function index(Request $request, CurrentCompany $current): Response
    {
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());
        $filters = $this->filters($request);
        $query = $this->query($date, $filters);
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($filters['page'], $pages);

        $entries = $query->with($this->relations())->orderByDesc('date')->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $account = $filters['account'] === null ? null : Account::query()->find($filters['account']);

        return Inertia::render('reports/journal', [
            'date' => $date->props(),
            'query' => $date->query(),
            'filters' => [...$filters, 'page' => $page],
            'account' => $account === null ? null : ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'accounts' => Account::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->sortBy('code', SORT_NATURAL)->values()
                ->map(fn (Account $a) => ['value' => (string) $a->id, 'label' => "{$a->code} · {$a->name}"])->all(),
            'types' => array_map(fn (JournalType $t) => ['value' => $t->value, 'label' => $t->label()], JournalType::cases()),
            'entries' => $entries->map(fn (JournalEntry $e) => $this->entry($e))->values()->all(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
        ]);
    }

    public function export(Request $request, CurrentCompany $current, AuditTrail $audit, Pdf $pdf): StreamedResponse
    {
        $format = $request->validate(['format' => ['required', 'in:csv,pdf']])['format'];
        $company = $current->require();
        $date = StatementDate::fromQuery($company, $request->query());
        $filters = $this->filters($request);
        $query = $this->query($date, $filters)->with($this->relations())->orderBy('date')->orderBy('id');
        $count = (clone $query)->count();

        abort_if($format === 'pdf' && $count > self::PDF_MAX, 422, 'That’s more than '.number_format(self::PDF_MAX).' vouchers for a PDF. Narrow the dates, or export CSV.');

        $audit->record($company->id, 'exported', label: 'Journal ('.strtoupper($format).')', changes: [
            'vouchers' => $count, 'from' => $date->from->toDateString(), 'to' => $date->asOf->toDateString(),
        ]);

        $name = str($company->name)->slug().'-journal-'.$date->from->toDateString().'-to-'.$date->asOf->toDateString().'.'.$format;
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

        if ($format === 'pdf') {
            $bytes = $pdf->render('exports.journal', [
                'entries' => $query->get()->map(fn (JournalEntry $e) => $this->entry($e))->all(),
                'company' => $company->name,
                'logo' => Pdf::logo($company),
                'kicker' => 'Statements',
                'title' => 'Journal',
                'meta' => $date->from->format('j M Y').' – '.$date->asOf->format('j M Y').' · '.$date->fy->props()['label'],
            ]);

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $name, ['Content-Type' => 'application/pdf', ...$headers]);
        }

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            Csv::put($out, ['Voucher', 'Date', 'Type', 'Memo', 'Account code', 'Account', 'Debit', 'Credit', 'Reverses', 'Reversed by']);

            // Eager loads still apply, 500 vouchers at a time.
            foreach ($query->lazy(500) as $entry) {
                foreach ($entry->lines as $line) {
                    Csv::put($out, [
                        $entry->voucher_no ?? '',
                        $entry->date->toDateString(),
                        $entry->type->label(),
                        $entry->memo ?? '',
                        $line->account->code,
                        $line->account->name,
                        $line->debit_paise === 0 ? '' : number_format($line->debit_paise / 100, 2, '.', ''),
                        $line->credit_paise === 0 ? '' : number_format($line->credit_paise / 100, 2, '.', ''),
                        $entry->reverses->voucher_no ?? '',
                        $entry->reversedBy->voucher_no ?? '',
                    ]);
                }
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', ...$headers]);
    }

    /** @return array{type: string|null, account: int|null, q: string, page: int} */
    private function filters(Request $request): array
    {
        $type = $request->query('type');
        $q = $request->query('q');

        return [
            'type' => is_string($type) && JournalType::tryFrom($type) !== null ? $type : null,
            'account' => $request->integer('account') ?: null,
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
            'page' => max(1, $request->integer('page', 1)),
        ];
    }

    /**
     * @param  array{type: string|null, account: int|null, q: string, page: int}  $f
     * @return Builder<JournalEntry>
     */
    private function query(StatementDate $date, array $f): Builder
    {
        return JournalEntry::query()
            ->whereBetween('date', [$date->from->toDateString(), $date->asOf->toDateString()])
            ->when($f['type'] !== null, fn (Builder $q) => $q->where('type', $f['type']))
            ->when($f['account'] !== null, fn (Builder $q) => $q->whereHas('lines', fn (Builder $l) => $l->where('account_id', $f['account'])))
            ->when($f['q'] !== '', function (Builder $q) use ($f): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
                $q->where(fn (Builder $w) => $w->where('voucher_no', 'like', $like)->orWhere('memo', 'like', $like));
            });
    }

    /** @return array<int|string, mixed> */
    private function relations(): array
    {
        return [
            'lines' => fn ($q) => $q->orderByDesc('debit_paise')->orderBy('id'),
            'lines.account:id,code,name',
            'reverses:id,voucher_no',
            'reversedBy:id,voucher_no',
        ];
    }

    /** @return array<string, mixed> */
    private function entry(JournalEntry $e): array
    {
        return [
            'id' => $e->id,
            'voucher' => $e->voucher_no,
            'date' => $e->date->toDateString(),
            'type' => $e->type->value,
            'typeLabel' => $e->type->label(),
            'memo' => $e->memo,
            'reverses' => $e->reverses?->voucher_no,
            'reversedBy' => $e->reversedBy?->voucher_no,
            'totalPaise' => (int) $e->lines->sum('debit_paise'),
            'lines' => $e->lines->map(fn (JournalLine $l) => [
                'id' => $l->id,
                'accountId' => $l->account_id,
                'code' => $l->account->code,
                'name' => $l->account->name,
                'debitPaise' => $l->debit_paise,
                'creditPaise' => $l->credit_paise,
            ])->values()->all(),
        ];
    }
}

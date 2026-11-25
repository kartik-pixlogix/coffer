<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\DeviceLabel;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditTrail;
use App\Services\Reports\Exports\Pdf;
use App\Support\Csv;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Audit trail page (D153): the company's edit log with filters by date, person, action,
 * record type and a search over labels and vouchers; 50 a page; each row opens into its
 * before → after table. The banner shows whether the hash chain is intact (checked at most
 * every ten minutes, or now with "Verify now"). CSV and PDF exports are themselves audited.
 * No design board covers it (v2), so it reuses the vault audit log's layout.
 */
final class AuditTrailController extends Controller
{
    public const int PER_PAGE = 50;

    public const int CSV_MAX = 100000;

    public const int PDF_MAX = 5000;

    /** Action chips: key → [label, actions]. */
    public const array GROUPS = [
        'created' => ['Created', ['created']],
        'updated' => ['Edited', ['updated']],
        'deleted' => ['Deleted', ['deleted', 'restored']],
        'posted' => ['Posted', ['posted']],
        'reversed' => ['Reversed', ['reversed']],
        'decided' => ['Approvals', ['approved', 'rejected', 'reopened']],
        'year' => ['Year end', ['closed']],
        'settings' => ['Permissions', ['permissions changed', 'permissions reset']],
        'exported' => ['Exports', ['exported']],
    ];

    public function index(Request $request, CurrentCompany $current, AuditChain $chain): Response
    {
        $company = $current->require();
        $filters = $this->filters($request);
        $list = $this->query($filters, withAction: true);
        $total = (clone $list)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($filters['page'], $pages);

        $byAction = $this->query($filters, withAction: false)->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action');
        $counts = ['all' => (int) $byAction->sum()];

        foreach (self::GROUPS as $key => [, $actions]) {
            $counts[$key] = (int) collect($actions)->sum(fn (string $a) => (int) ($byAction[$a] ?? 0));
        }

        $events = $list->with('user:id,name')->orderByDesc('seq')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        return Inertia::render('audit/index', [
            'filters' => [...$filters, 'page' => $page],
            'groups' => array_map(fn (array $g, string $key) => ['key' => $key, 'label' => $g[0]], self::GROUPS, array_keys(self::GROUPS)),
            'types' => $this->types(),
            'people' => $this->people($company),
            'counts' => $counts,
            'rows' => $events->map(fn (AuditEvent $e) => $this->row($e))->values()->all(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'chain' => $this->chainStatus($company, $chain, force: false),
        ]);
    }

    /** "Verify now": walks the chain again, past the ten-minute cache. */
    public function verify(CurrentCompany $current, AuditChain $chain): RedirectResponse
    {
        $status = $this->chainStatus($current->require(), $chain, force: true);

        Inertia::flash('toast', $status['ok']
            ? ['type' => 'success', 'message' => 'Audit trail verified: all '.number_format($status['events']).' events are intact']
            : ['type' => 'error', 'message' => 'The audit trail is broken: '.$status['problem']]);

        return back();
    }

    public function export(Request $request, CurrentCompany $current, AuditTrail $audit, Pdf $pdf): StreamedResponse
    {
        $format = $request->validate(['format' => ['required', 'in:csv,pdf']])['format'];
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $filters = $this->filters($request);
        $query = $this->query($filters, withAction: true)->with('user:id,name')->orderBy('seq');
        $count = (clone $query)->count();

        if ($format === 'pdf' && $count > self::PDF_MAX) {
            abort(422, 'That’s more than '.number_format(self::PDF_MAX).' events for a PDF. Narrow the dates, or export CSV.');
        }

        $audit->record($company->id, 'exported', label: 'Audit trail ('.strtoupper($format).')', changes: [
            'rows' => min($count, self::CSV_MAX), 'from' => $filters['from'], 'to' => $filters['to'],
        ]);

        $name = str($company->name)->slug().'-audit-trail-'.$filters['from'].'-to-'.$filters['to'].'.'.$format;
        $timezone = $user->timezone;
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

        if ($format === 'pdf') {
            $bytes = $pdf->render('exports.audit-trail', [
                'events' => $query->get()->map(fn (AuditEvent $e) => $this->row($e, $timezone))->all(),
                'company' => $company->name,
                'logo' => Pdf::logo($company),
                'kicker' => 'Statements',
                'title' => 'Audit trail',
                'meta' => CarbonImmutable::parse($filters['from'])->format('j M Y').' – '.CarbonImmutable::parse($filters['to'])->format('j M Y'),
            ], 'landscape');

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $name, ['Content-Type' => 'application/pdf', ...$headers]);
        }

        return response()->streamDownload(function () use ($query, $timezone): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            Csv::put($out, ['#', 'Time', 'Person', 'Acting as', 'Action', 'Record', 'Voucher', 'Changes', 'IP address', 'Device', 'Hash']);

            $query->limit(self::CSV_MAX)->chunk(1000, function ($events) use ($out, $timezone): void {
                foreach ($events as $event) {
                    /** @var AuditEvent $event */
                    $row = $this->row($event, $timezone);
                    Csv::put($out, [
                        $event->seq,
                        $event->occurred_at->setTimezone($timezone)->format('Y-m-d H:i:s'),
                        $row['person'],
                        $event->impersonator === null ? '' : 'Support: '.$event->impersonator,
                        $row['actionLabel'],
                        $event->subject_label ?? '',
                        $event->voucher_no ?? '',
                        implode('; ', array_map(fn (array $c) => $c['field'].': '.$c['text'], $row['changes'])),
                        $event->ip ?? '',
                        $row['device'] ?? '',
                        $event->hash,
                    ]);
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', ...$headers]);
    }

    /** @return array{from: string, to: string, user: int|null, action: string|null, type: string|null, q: string, page: int} */
    private function filters(Request $request): array
    {
        $today = CarbonImmutable::today();
        $date = fn (string $key): ?CarbonImmutable => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1
            ? CarbonImmutable::parse((string) $request->query($key)) : null;
        $from = $date('from') ?? $today->subDays(29);
        $to = $date('to') ?? $today;

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $action = $request->query('action');
        $type = $request->query('type');
        $q = $request->query('q');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'user' => $request->integer('user') ?: null,
            'action' => is_string($action) && isset(self::GROUPS[$action]) ? $action : null,
            'type' => is_string($type) && $type !== '' ? mb_substr($type, 0, 64) : null,
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
            'page' => max(1, $request->integer('page', 1)),
        ];
    }

    /**
     * @param  array{from: string, to: string, user: int|null, action: string|null, type: string|null, q: string, page: int}  $f
     * @return Builder<AuditEvent>
     */
    private function query(array $f, bool $withAction): Builder
    {
        return AuditEvent::query()
            ->whereBetween('occurred_at', [CarbonImmutable::parse($f['from'])->startOfDay(), CarbonImmutable::parse($f['to'])->endOfDay()])
            ->when($f['user'] !== null, fn (Builder $q) => $q->where('user_id', $f['user']))
            ->when($f['type'] !== null, fn (Builder $q) => $q->where('subject_type', $f['type']))
            ->when($f['q'] !== '', function (Builder $q) use ($f): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
                $q->where(fn (Builder $w) => $w->where('subject_label', 'like', $like)->orWhere('voucher_no', 'like', $like)->orWhere('ip', 'like', $like));
            })
            ->when($withAction && $f['action'] !== null, fn (Builder $q) => $q->whereIn('action', self::GROUPS[(string) $f['action']][1]));
    }

    /** @return array<string, mixed> */
    private function row(AuditEvent $e, string $timezone = 'UTC'): array
    {
        $changes = [];

        foreach ($e->changeSet() as $field => $change) {
            $changes[] = [
                'field' => str_replace('_', ' ', (string) $field),
                'changed' => is_array($change) && ($change['changed'] ?? false) === true,
                'from' => is_array($change) && array_key_exists('from', $change) ? $this->text($change['from']) : null,
                'to' => is_array($change) && array_key_exists('to', $change) ? $this->text($change['to']) : (is_array($change) ? null : $this->text($change)),
                'text' => $this->describe($change),
            ];
        }

        return [
            'id' => $e->id,
            'seq' => $e->seq,
            'at' => $e->occurred_at->toIso8601String(),
            'time' => $e->occurred_at->setTimezone($timezone)->format('j M Y, H:i'),
            'person' => $e->user->name ?? ($e->actor === 'console' ? 'Coffer (scheduled)' : 'Coffer'),
            'impersonator' => $e->impersonator,
            'action' => $e->action,
            'actionLabel' => ucfirst($e->action),
            'label' => $e->subject_label,
            'type' => $e->subject_type === null ? null : str(class_basename($e->subject_type))->headline()->toString(),
            'voucher' => $e->voucher_no,
            'ip' => $e->ip,
            'device' => $e->user_agent === null ? null : DeviceLabel::from($e->user_agent),
            'changes' => $changes,
            'hash' => substr($e->hash, 0, 12),
        ];
    }

    private function describe(mixed $change): string
    {
        if (! is_array($change)) {
            return $this->text($change);
        }

        if (($change['changed'] ?? false) === true) {
            return 'changed (encrypted)';
        }

        $from = array_key_exists('from', $change) ? $this->text($change['from']) : null;
        $to = array_key_exists('to', $change) ? $this->text($change['to']) : null;

        return match (true) {
            $from !== null && $to !== null => "{$from} → {$to}",
            $to !== null => $to,
            $from !== null => "was {$from}",
            default => '—',
        };
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /** @return array{ok: bool, events: int, problem: string|null, checkedAt: string} */
    private function chainStatus(Company $company, AuditChain $chain, bool $force): array
    {
        $key = 'audit:chain:'.$company->id;

        if ($force) {
            Cache::forget($key);
        }

        /** @var array{ok: bool, events: int, problem: string|null, checkedAt: string} */
        return Cache::remember($key, now()->addMinutes(10), function () use ($company, $chain): array {
            $problem = $chain->verify($company->id);

            return [
                'ok' => $problem === null,
                'events' => AuditEvent::query()->withoutGlobalScopes()->where('company_id', $company->id)->count(),
                'problem' => $problem === null ? null : "event {$problem['seq']} {$problem['problem']}.",
                'checkedAt' => CarbonImmutable::now()->toIso8601String(),
            ];
        });
    }

    /** @return list<array{value: string, label: string}> */
    private function types(): array
    {
        return array_values(AuditEvent::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')
            ->map(fn (string $type) => ['value' => $type, 'label' => str(class_basename($type))->headline()->toString()])
            ->sortBy('label')->values()->all());
    }

    /** @return list<array{id: int, name: string}> everyone in the company plus anyone who appears in the trail */
    private function people(Company $company): array
    {
        $ids = Membership::query()->where('company_id', $company->id)->pluck('user_id')
            ->merge(AuditEvent::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->unique();

        return array_values(User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all());
    }
}

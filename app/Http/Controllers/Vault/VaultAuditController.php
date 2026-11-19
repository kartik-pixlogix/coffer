<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Auth\DeviceLabel;
use App\Enums\VaultAuditAction;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Partner;
use App\Models\User;
use App\Models\VaultAuditEvent;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultSession;
use App\Support\Csv;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The vault audit log (VaultAudit.dc.html, spec §14.4, D75): append-only events with
 * time, person, action, item, IP and device; KPIs for the range; filters by range,
 * person, action and a search over item, IP and device; 25 a page; CSV export (itself
 * audited). Admins, or roles given "Export and view audit log". Rows hold names and
 * labels only: the audit table never has a secret value.
 */
final class VaultAuditController extends Controller
{
    public const int PER_PAGE = 25;

    public const int EXPORT_MAX = 50000;

    /** Action chips: the board's five plus the spec's other events, grouped. */
    public const array GROUPS = [
        'viewed' => ['Viewed', ['viewed']],
        'revealed' => ['Revealed', ['revealed']],
        'copied' => ['Copied', ['copied', 'downloaded']],
        'used_code' => ['Used code', ['used_code']],
        'edited' => ['Edited', ['created', 'edited', 'rotated', 'restored', 'deleted']],
        'shared' => ['Shared', ['shared', 'unshared']],
        'exported' => ['Exported', ['exported']],
        'imported' => ['Imported', ['imported']],
        'policies' => ['Policies', ['policies_changed']],
        'unlocked' => ['Unlocked', ['unlocked', 'locked']],
        'failed_unlock' => ['Failed unlock', ['failed_unlock']],
    ];

    public function index(Request $request, VaultSession $session, VaultAccess $access): Response
    {
        $user = $this->authorizeAudit($request, $access);
        $filters = $this->filters($request);
        $open = $session->opensFor($user);

        return Inertia::render('vault/audit', [
            'vaultState' => $session->pageState($user),
            'filters' => $filters,
            'log' => $open ? $this->log($filters) : null,
            'groups' => array_map(fn (array $g, string $key) => ['key' => $key, 'label' => $g[0]], self::GROUPS, array_keys(self::GROUPS)),
        ]);
    }

    public function export(Request $request, VaultAccess $access, VaultAudit $audit): StreamedResponse
    {
        $user = $this->authorizeAudit($request, $access);
        $filters = $this->filters($request);
        $query = $this->query($filters, withAction: true)->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id');
        $count = min(self::EXPORT_MAX, (clone $query)->count());

        $audit->record(VaultAuditAction::Exported, $user, null, null, [
            'what' => 'audit log',
            'rows' => $count,
            'from' => $filters['from'],
            'to' => $filters['to'],
        ]);

        $name = 'vault-audit-'.$filters['from'].'-to-'.$filters['to'].'.csv';
        $timezone = $user->timezone;

        return response()->streamDownload(function () use ($query, $timezone): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            Csv::put($out, ['Time', 'User', 'Action', 'Field', 'Item', 'IP address', 'Device', 'New device']);

            $query->limit(self::EXPORT_MAX)->chunk(1000, function ($events) use ($out, $timezone): void {
                foreach ($events as $event) {
                    /** @var VaultAuditEvent $event */
                    Csv::put($out, [
                        $event->created_at->setTimezone($timezone)->format('Y-m-d H:i:s'),
                        $event->user->name ?? 'System',
                        $event->action->label(),
                        $event->field ?? '',
                        $event->item_name ?? '',
                        $event->ip ?? '',
                        $this->device($event) ?? '',
                        $event->new_device ? 'yes' : '',
                    ]);
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return array{range: string, from: string, to: string, user: int|null, action: string|null, q: string, item: int|null, page: int} */
    private function filters(Request $request): array
    {
        $today = CarbonImmutable::today();
        $range = in_array($request->query('range'), ['today', '7d', '30d', 'custom'], true) ? (string) $request->query('range') : '7d';
        $date = fn (string $key): ?CarbonImmutable => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1
            ? CarbonImmutable::parse((string) $request->query($key)) : null;

        [$from, $to] = match ($range) {
            'today' => [$today, $today],
            '30d' => [$today->subDays(29), $today],
            'custom' => [$date('from') ?? $today->subDays(6), $date('to') ?? $today],
            default => [$today->subDays(6), $today],
        };

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $action = $request->query('action');
        $q = $request->query('q');

        return [
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'user' => $request->integer('user') ?: null,
            'action' => is_string($action) && isset(self::GROUPS[$action]) ? $action : null,
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
            'item' => $request->integer('item') ?: null,
            'page' => max(1, $request->integer('page', 1)),
        ];
    }

    /**
     * @param  array{range: string, from: string, to: string, user: int|null, action: string|null, q: string, item: int|null, page: int}  $f
     * @return Builder<VaultAuditEvent>
     */
    private function query(array $f, bool $withAction): Builder
    {
        $from = CarbonImmutable::parse($f['from'])->startOfDay();
        $to = CarbonImmutable::parse($f['to'])->endOfDay();

        return VaultAuditEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($f['user'] !== null, fn (Builder $q) => $q->where('user_id', $f['user']))
            ->when($f['item'] !== null, fn (Builder $q) => $q->where('vault_item_id', $f['item']))
            ->when($f['q'] !== '', function (Builder $q) use ($f): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
                $q->where(fn (Builder $w) => $w->where('item_name', 'like', $like)->orWhere('ip', 'like', $like)
                    ->orWhere('device', 'like', $like)->orWhere('user_agent', 'like', $like));
            })
            ->when($withAction && $f['action'] !== null, fn (Builder $q) => $q->whereIn('action', self::GROUPS[(string) $f['action']][1]));
    }

    /**
     * @param  array{range: string, from: string, to: string, user: int|null, action: string|null, q: string, item: int|null, page: int}  $f
     * @return array<string, mixed>
     */
    private function log(array $f): array
    {
        $range = VaultAuditEvent::query()->whereBetween('created_at', [CarbonImmutable::parse($f['from'])->startOfDay(), CarbonImmutable::parse($f['to'])->endOfDay()]);
        $base = $this->query($f, withAction: false);

        $byAction = (clone $base)->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action');
        $counts = ['all' => (int) $byAction->sum()];

        foreach (self::GROUPS as $key => [, $actions]) {
            $counts[$key] = (int) collect($actions)->sum(fn (string $a) => (int) ($byAction[$a] ?? 0));
        }

        $list = $this->query($f, withAction: true);
        $total = (clone $list)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($f['page'], $pages);
        $events = $list->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();
        $colours = $this->colours();

        return [
            'kpis' => [
                'events' => (clone $range)->count(),
                'reveals' => (clone $range)->where('action', VaultAuditAction::Revealed->value)->count(),
                'shares' => (clone $range)->where('action', VaultAuditAction::Shared->value)->count(),
                'newDevices' => (clone $range)->where('new_device', true)->count(),
            ],
            'counts' => $counts,
            'rows' => $events->map(fn (VaultAuditEvent $e) => [
                'id' => $e->id,
                'at' => $e->created_at->toIso8601String(),
                'user' => $e->user?->name,
                'colour' => $e->user_id === null ? null : ($colours[$e->user_id] ?? null),
                'action' => $e->action->value,
                'label' => $e->action->label(),
                'field' => $e->field,
                'item' => $e->item_name,
                'itemId' => $e->vault_item_id,
                'ip' => $e->ip,
                'device' => $this->device($e),
                'newDevice' => $e->new_device,
            ])->values()->all(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'people' => $this->people(),
        ];
    }

    private function device(VaultAuditEvent $event): ?string
    {
        return $event->device ?? ($event->user_agent === null ? null : DeviceLabel::from($event->user_agent));
    }

    /** @return list<array{id: int, name: string}> everyone in the company plus anyone who appears in the log */
    private function people(): array
    {
        $ids = Membership::query()->where('company_id', app(CurrentCompany::class)->require()->id)->pluck('user_id')
            ->merge(VaultAuditEvent::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->unique();

        return array_values(User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all());
    }

    /** @return array<int, int|null> */
    private function colours(): array
    {
        $map = [];

        foreach (Partner::query()->whereNotNull('user_id')->get(['user_id', 'colour']) as $p) {
            $map[(int) $p->user_id] = $p->colour;
        }

        foreach (Member::query()->with('partner:id,colour')->whereNotNull('user_id')->get(['user_id', 'partner_id']) as $m) {
            $map[(int) $m->user_id] ??= $m->partner->colour;
        }

        return $map;
    }

    private function authorizeAudit(Request $request, VaultAccess $access): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $access->mayAudit($user), 403);

        return $user;
    }
}

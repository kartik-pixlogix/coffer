import { Head, router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { Avatar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { DateRangePicker } from '@/components/ui/date-picker';
import { FilterChip } from '@/components/ui/filter-chips';
import { Icon } from '@/components/ui/icon';
import { Pagination } from '@/components/ui/pagination';
import { SearchField } from '@/components/ui/search-field';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { StatusPill } from '@/components/ui/status-pill';
import type { StatusTone } from '@/components/ui/status-pill';
import { VaultGate } from '@/components/vault/vault-gate';
import { formatIndianNumber } from '@/lib/format';
import { audit, index as vaultIndex } from '@/routes/vault';
import { exportMethod as auditExport } from '@/routes/vault/audit';
import type {
    VaultAuditFilters,
    VaultAuditLog,
    VaultAuditProps,
} from '@/types/vault';
import { TableWrap } from '@/components/ui/table-wrap';

const RANGES: { value: VaultAuditFilters['range']; label: string }[] = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: 'custom', label: 'Custom' },
];

/** Action pill tones (VaultAudit.dc.html), with the spec's other events. */
const TONE: Record<string, StatusTone> = {
    viewed: 'outline',
    revealed: 'warn',
    copied: 'info',
    downloaded: 'info',
    used_code: 'info',
    created: 'graphite',
    edited: 'graphite',
    rotated: 'graphite',
    restored: 'graphite',
    deleted: 'neg',
    shared: 'lime',
    unshared: 'outline',
    exported: 'warn',
    unlocked: 'outline',
    locked: 'outline',
    failed_unlock: 'neg',
};

const partnerOf = (colour: number | null): PartnerColour | undefined =>
    colour !== null && colour >= 1 && colour <= 6
        ? (colour as PartnerColour)
        : undefined;

/** "15 Nov" / "09:41" in the viewer's zone. */
function dayAndTime(iso: string, timeZone: string): [string, string] {
    const d = new Date(iso);
    const day = new Intl.DateTimeFormat('en-IN', {
        day: 'numeric',
        month: 'short',
        timeZone,
    }).format(d);
    const time = new Intl.DateTimeFormat('en-IN', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone,
    }).format(d);

    return [day, time];
}

function rangeText(f: VaultAuditFilters): string {
    const fmt = (iso: string, year: boolean) =>
        new Intl.DateTimeFormat('en-IN', {
            day: 'numeric',
            month: 'short',
            ...(year ? { year: 'numeric' } : {}),
            timeZone: 'UTC',
        }).format(new Date(`${iso}T00:00:00Z`));

    if (f.range === 'today') {
        return 'Today';
    }

    return f.from === f.to
        ? fmt(f.from, true)
        : `${fmt(f.from, false)} – ${fmt(f.to, true)}`;
}

/** Query string for the current filters (without defaults). */
function queryOf(f: VaultAuditFilters): Record<string, string | number> {
    const q: Record<string, string | number> = { range: f.range };

    if (f.range === 'custom') {
        q.from = f.from;
        q.to = f.to;
    }

    if (f.user !== null) {
        q.user = f.user;
    }

    if (f.action !== null) {
        q.action = f.action;
    }

    if (f.q !== '') {
        q.q = f.q;
    }

    if (f.item !== null) {
        q.item = f.item;
    }

    if (f.page > 1) {
        q.page = f.page;
    }

    return q;
}

/**
 * The vault audit log (VaultAudit.dc.html, spec §14.4): KPIs for the range, filters by
 * date, person, action and a search over item / IP / device, 25 a page, CSV export.
 * Events are append-only; they carry names and labels, never a secret value.
 */
export default function VaultAuditPage({
    vaultState,
    filters,
    log,
    groups,
}: VaultAuditProps) {
    const go = (next: Partial<VaultAuditFilters>) =>
        router.get(audit().url, queryOf({ ...filters, page: 1, ...next }), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });

    return (
        <>
            <Head title="Audit log" />
            <PageHeader
                kicker="Vault"
                title="Audit log"
                crumbs={[
                    { title: 'Credentials Vault', href: vaultIndex().url },
                    { title: 'Audit log' },
                ]}
                actions={
                    log && (
                        <>
                            <SegmentedControl
                                aria-label="Date range"
                                options={RANGES}
                                value={filters.range}
                                onValueChange={(range) => go({ range })}
                            />
                            <a
                                className="cf-btn cf-btn--primary cf-btn--lg cf-btn--disc"
                                href={
                                    auditExport({
                                        query: queryOf({ ...filters, page: 1 }),
                                    }).url
                                }
                                download
                            >
                                Export CSV
                                <span className="cf-disc">
                                    <Icon
                                        icon={Download}
                                        size={18}
                                        strokeWidth={1.75}
                                    />
                                </span>
                            </a>
                        </>
                    )
                }
            />
            <VaultGate vaultState={vaultState}>
                {log && (
                    <AuditBody
                        filters={filters}
                        log={log}
                        groups={groups}
                        go={go}
                    />
                )}
            </VaultGate>
        </>
    );
}

function AuditBody({
    filters,
    log,
    groups,
    go,
}: {
    filters: VaultAuditFilters;
    log: VaultAuditLog;
    groups: VaultAuditProps['groups'];
    go: (next: Partial<VaultAuditFilters>) => void;
}) {
    const { auth } = usePage().props;
    const timeZone = auth.user.timezone;
    const [q, setQ] = useState(filters.q);
    const first = useRef(true);

    // Search as you type, a moment after the last key.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const t = window.setTimeout(() => {
            if (q.trim() !== filters.q) {
                go({ q: q.trim() });
            }
        }, 300);

        return () => window.clearTimeout(t);
    }, [q, filters.q, go]);

    const start = (log.page - 1) * log.perPage + 1;
    const end = Math.min(log.page * log.perPage, log.total);

    return (
        <div className="cf-stack va-page">
            <section className="va-kpis">
                <div className="cf-kpi">
                    <h2 className="cf-kpi-title">Events</h2>
                    <span className="t-money-lg">
                        {formatIndianNumber(log.kpis.events)}
                    </span>
                    <span className="t-caption t-muted">
                        {rangeText(filters)}
                    </span>
                </div>
                <div className="cf-kpi">
                    <h2 className="cf-kpi-title">Passwords revealed</h2>
                    <span className="t-money-lg">
                        {formatIndianNumber(log.kpis.reveals)}
                    </span>
                    <span className="t-caption t-muted">
                        Each one needed 2FA
                    </span>
                </div>
                <div className="cf-kpi">
                    <h2 className="cf-kpi-title">Shares</h2>
                    <span className="t-money-lg">
                        {formatIndianNumber(log.kpis.shares)}
                    </span>
                    <span className="t-caption t-muted">New access given</span>
                </div>
                <div className="cf-kpi">
                    <h2 className="cf-kpi-title">New devices</h2>
                    <span
                        className={
                            log.kpis.newDevices > 0
                                ? 't-money-lg t-warn'
                                : 't-money-lg'
                        }
                    >
                        {formatIndianNumber(log.kpis.newDevices)}
                    </span>
                    <span className="t-caption t-muted">First time seen</span>
                </div>
            </section>

            <section
                className="cf-card cf-stack va-card"
                aria-labelledby="va-h"
            >
                <div className="cf-kv va-head">
                    <h2 id="va-h" className="cf-dot-title t-title">
                        Activity
                    </h2>
                    <div className="cf-row va-filters">
                        <SearchField
                            aria-label="Search items, IPs or devices"
                            placeholder="Item, IP or device"
                            value={q}
                            onValueChange={setQ}
                            wrapClassName="va-search"
                        />
                        <Select
                            pill
                            aria-label="Person"
                            value={
                                filters.user === null
                                    ? ''
                                    : String(filters.user)
                            }
                            onChange={(e) =>
                                go({
                                    user:
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                })
                            }
                            options={[
                                { value: '', label: 'Everyone' },
                                ...log.people.map((p) => ({
                                    value: String(p.id),
                                    label: p.name,
                                })),
                            ]}
                        />
                    </div>
                </div>
                {filters.range === 'custom' && (
                    <DateRangePicker
                        label="Dates"
                        value={{ start: filters.from, end: filters.to }}
                        onValueChange={(r) => {
                            if (r.start && r.end) {
                                go({ from: r.start, to: r.end });
                            }
                        }}
                        fieldClassName="va-dates"
                    />
                )}
                <div
                    className="cf-row va-chips"
                    role="group"
                    aria-label="Action"
                >
                    <FilterChip
                        pressed={filters.action === null}
                        count={log.counts.all ?? 0}
                        onClick={() => go({ action: null })}
                    >
                        All
                    </FilterChip>
                    {groups.map((g) => (
                        <FilterChip
                            key={g.key}
                            pressed={filters.action === g.key}
                            count={log.counts[g.key] ?? 0}
                            onClick={() =>
                                go({
                                    action:
                                        filters.action === g.key ? null : g.key,
                                })
                            }
                        >
                            {g.label}
                        </FilterChip>
                    ))}
                </div>

                <TableWrap label="Vault audit log">
                    <div
                        className="cf-table va-table"
                        role="table"
                        aria-label="Vault audit log"
                    >
                        <div className="cf-tr cf-thead va-cols" role="row">
                            <span role="columnheader">Time</span>
                            <span role="columnheader">User</span>
                            <span role="columnheader">Action</span>
                            <span role="columnheader">Item</span>
                            <span role="columnheader">IP address</span>
                            <span role="columnheader">Device</span>
                        </div>
                        {log.rows.map((r) => {
                            const [day, time] = dayAndTime(r.at, timeZone);

                            return (
                                <div
                                    key={r.id}
                                    className="cf-tr va-cols"
                                    role="row"
                                >
                                    <span role="cell" className="cf-stack">
                                        <span className="t-body">{day}</span>
                                        <span className="t-money-sm t-muted">
                                            {time}
                                        </span>
                                    </span>
                                    <span
                                        role="cell"
                                        className="cf-row va-user"
                                    >
                                        <Avatar
                                            name={r.user ?? 'System'}
                                            size="sm"
                                            graphite={r.colour === null}
                                            partner={partnerOf(r.colour)}
                                            label={false}
                                        />
                                        <span className="t-body">
                                            {r.user ?? 'System'}
                                        </span>
                                    </span>
                                    <span role="cell">
                                        <StatusPill
                                            tone={TONE[r.action] ?? 'outline'}
                                            size="sm"
                                        >
                                            {r.label}
                                        </StatusPill>
                                    </span>
                                    <span
                                        role="cell"
                                        className="t-label-strong va-item"
                                    >
                                        {r.item ?? '—'}
                                        {r.field && (
                                            <span className="t-caption t-muted">
                                                {' '}
                                                · {r.field.replace(/_/g, ' ')}
                                            </span>
                                        )}
                                    </span>
                                    <span
                                        role="cell"
                                        className="t-money-sm t-muted"
                                    >
                                        {r.ip ?? '—'}
                                    </span>
                                    <span
                                        role="cell"
                                        className="cf-row va-device"
                                    >
                                        <span className="t-body t-muted">
                                            {r.device ?? '—'}
                                        </span>
                                        {r.newDevice && (
                                            <StatusPill tone="warn" size="sm">
                                                New device
                                            </StatusPill>
                                        )}
                                    </span>
                                </div>
                            );
                        })}
                        {log.rows.length === 0 && (
                            <div className="cf-empty-row t-body t-muted">
                                No events match these filters.
                            </div>
                        )}
                    </div>
                </TableWrap>
                <Pagination
                    page={log.page}
                    pageCount={log.pages}
                    onPageChange={(page) => go({ page })}
                    summary={`${
                        log.total === 0
                            ? 'No events'
                            : `Showing ${start}–${end} of ${formatIndianNumber(log.total)}`
                    } · Events are kept for 12 months and can’t be edited.`}
                />
            </section>
        </div>
    );
}

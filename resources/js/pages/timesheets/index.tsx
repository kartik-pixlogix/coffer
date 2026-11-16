import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    Check,
    Clock,
    Minus,
    Pencil,
    Plus,
    Trash2,
    Upload,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { LogHoursModal } from '@/components/timesheets/log-hours-modal';
import { LogHoursSheet } from '@/components/timesheets/log-hours-sheet';
import { initialsFrom, partnerColourVar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { Button, ButtonLink } from '@/components/ui/button';
import { ButtonGroupSelect } from '@/components/ui/button-group';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { IconButton } from '@/components/ui/icon-button';
import { KpiTile } from '@/components/ui/kpi-tile';
import { Modal } from '@/components/ui/modal';
import { MonthPicker } from '@/components/ui/month-picker';
import { Pager, PaginationBar } from '@/components/ui/pagination';
import { SearchField } from '@/components/ui/search-field';
import { Select } from '@/components/ui/select';
import { StatusPill } from '@/components/ui/status-pill';
import { PHONE_QUERY, useMediaQuery } from '@/hooks/use-media-query';
import { formatDate, formatInr, toDate, toIsoDate } from '@/lib/format';
import { formatCenti } from '@/lib/hours';
import { firstName, monthLong } from '@/lib/team';
import {
    destroy as deleteEntry,
    importMethod as importPage,
    index as timesheetsIndex,
} from '@/routes/timesheets';
import type { PartnerOption } from '@/types/team';
import type { TimeEntry, TimesheetOptions } from '@/types/timesheets';

type TeamRow = {
    id: number;
    name: string;
    partnerId: number;
    active: boolean;
    hoursCenti: number;
    fixed: boolean;
    earningsPaise: number;
};

type Filters = {
    member?: string;
    partner?: string;
    project?: string;
    billable?: 'billable' | 'non';
    q?: string;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    company: { name: string; workingDays: number };
    today: string;
    /** First day of the month shown. */
    month: string;
    filters: Filters;
    kpis: {
        totalCenti: number;
        billableCenti: number;
        members: number;
        todayCenti: number;
        todayEntries: number;
        todayNames: string[];
    };
    team: TeamRow[];
    entries: Paginated<TimeEntry>;
    week: { day: string; start: string; entries: TimeEntry[] };
    options: TimesheetOptions;
    can: { log: boolean; import: boolean };
};

/** A full month is the bar's 100% in "Team this month" (Timesheets.dc.html uses 80 h at mid-month). */
const BAR_FULL_CENTI = 16000;

const dotFor = (partners: PartnerOption[], id: number) => {
    const p = partners.find((x) => x.id === id);

    return p
        ? partnerColourVar(p.colour as PartnerColour)
        : 'var(--line-strong)';
};

/**
 * Timesheets (Timesheets.dc.html, spec §8.1): KPIs, the team's month, entries with
 * filters (25 a page), edit and delete; Log hours by day or by month. At phone width
 * the page follows MobileLogHours.dc.html: a week strip and a bottom sheet.
 */
export default function Timesheets(props: Props) {
    const phone = useMediaQuery(PHONE_QUERY);
    const [logOpen, setLogOpen] = useState(false);
    const [editing, setEditing] = useState<TimeEntry | null>(null);
    const [removing, setRemoving] = useState<TimeEntry | null>(null);

    const openLog = (entry: TimeEntry | null) => {
        setEditing(entry);
        setLogOpen(true);
    };

    return (
        <>
            <Head title="Timesheets" />
            {phone ? (
                <PhoneTimesheets {...props} onLog={() => openLog(null)} />
            ) : (
                <DesktopTimesheets
                    {...props}
                    onLog={openLog}
                    onDelete={setRemoving}
                />
            )}

            {props.can.log &&
                (phone ? (
                    <LogHoursSheet
                        open={logOpen}
                        onOpenChange={setLogOpen}
                        options={props.options}
                        today={props.today}
                        day={props.week.day}
                        workingDays={props.company.workingDays}
                    />
                ) : (
                    <LogHoursModal
                        open={logOpen}
                        onOpenChange={setLogOpen}
                        entry={editing}
                        options={props.options}
                        companyName={props.company.name}
                        today={props.today}
                        workingDays={props.company.workingDays}
                    />
                ))}

            <DeleteEntry entry={removing} onClose={() => setRemoving(null)} />
        </>
    );
}

function visit(
    filters: Filters & { month?: string; page?: number; day?: string },
) {
    const query = Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== undefined && v !== ''),
    );

    router.get(timesheetsIndex().url, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function DesktopTimesheets({
    today,
    month,
    filters,
    kpis,
    team,
    entries,
    options,
    can,
    onLog,
    onDelete,
}: Props & {
    onLog: (entry: TimeEntry | null) => void;
    onDelete: (entry: TimeEntry) => void;
}) {
    const ym = month.slice(0, 7);
    const isThisMonth = today.startsWith(ym);
    const lastDay = isThisMonth
        ? toDate(today).getDate()
        : new Date(
              toDate(month).getFullYear(),
              toDate(month).getMonth() + 1,
              0,
          ).getDate();
    const monthShort = formatDate(month).replace(/^\d+ /, '');
    const query = { ...filters, month: ym };
    const billablePct =
        kpis.totalCenti > 0
            ? (kpis.billableCenti / kpis.totalCenti) * 100
            : null;

    const [search, setSearch] = useState(filters.q ?? '');
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const onSearch = (q: string) => {
        setSearch(q);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () => visit({ ...query, q: q.trim() || undefined }),
            300,
        );
    };

    const projectName = (id: number) =>
        options.projects.find((p) => p.id === id)?.name ?? '';
    const partnerName = (id: number) =>
        firstName(options.partners.find((p) => p.id === id)?.name ?? '');

    const columns: DataTableColumn<TimeEntry>[] = [
        {
            key: 'date',
            header: 'Date',
            width: '110px',
            cell: (e) => (
                <span className="t-body t-muted">{formatDate(e.date)}</span>
            ),
        },
        {
            key: 'member',
            header: 'Member',
            width: 'minmax(170px,1.3fr)',
            cell: (e) => (
                <span className="cf-row t-label-strong">
                    <span
                        className="cf-avatar cf-avatar--sm"
                        aria-hidden="true"
                    >
                        {initialsFrom(e.memberName)}
                        <span
                            className="cf-avatar-dot"
                            style={{
                                background: dotFor(
                                    options.partners,
                                    e.partnerId,
                                ),
                            }}
                        />
                    </span>
                    {e.memberName}
                </span>
            ),
        },
        {
            key: 'hours',
            header: 'Hours',
            width: '80px',
            numeric: true,
            cell: (e) => formatCenti(e.hoursCenti),
        },
        {
            key: 'type',
            header: 'Type',
            width: '130px',
            skeleton: 'pill',
            cell: (e) => (
                <StatusPill status={e.billable ? 'billable' : 'nonBillable'} />
            ),
        },
        {
            key: 'project',
            header: 'Project',
            width: 'minmax(150px,1fr)',
            cell: (e) => (
                <span className="t-body">{projectName(e.projectId)}</span>
            ),
        },
        {
            key: 'partner',
            header: 'Partner',
            width: '120px',
            cell: (e) => (
                <span className="t-body t-muted">
                    {partnerName(e.partnerId)}
                </span>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                kicker="People"
                title="Timesheets"
                crumbs={[{ title: 'Timesheets' }]}
                actions={
                    <>
                        {can.import && (
                            <ButtonLink
                                href={importPage().url}
                                variant="secondary"
                                size="lg"
                                icon={Upload}
                            >
                                Bulk import
                            </ButtonLink>
                        )}
                        {can.log && (
                            <Button
                                variant="primary"
                                size="lg"
                                disc={Plus}
                                onClick={() => onLog(null)}
                            >
                                Log hours
                            </Button>
                        )}
                    </>
                }
            />

            <section className="ts-kpis" aria-label="This month">
                <KpiTile
                    headingLevel="h2"
                    title={
                        isThisMonth
                            ? 'Hours this month'
                            : `Hours in ${monthShort}`
                    }
                    icon={Clock}
                    hours={kpis.totalCenti / 100}
                    caption={`1–${lastDay} ${monthShort} · ${kpis.members} ${kpis.members === 1 ? 'member' : 'members'}`}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Billable"
                    icon={Check}
                    hours={kpis.billableCenti / 100}
                    aside={
                        billablePct !== null && (
                            <span className="cf-pill cf-pill--info">
                                {billablePct.toFixed(1)}%
                            </span>
                        )
                    }
                    caption="Charged to client projects"
                />
                <KpiTile
                    headingLevel="h2"
                    title="Non-billable"
                    icon={Minus}
                    hours={(kpis.totalCenti - kpis.billableCenti) / 100}
                    caption="Internal work and QA"
                />
                <KpiTile
                    headingLevel="h2"
                    title="Logged today"
                    icon={CalendarDays}
                    hours={kpis.todayCenti / 100}
                    caption={
                        kpis.todayEntries === 0
                            ? 'Nothing yet today'
                            : `${kpis.todayEntries} ${kpis.todayEntries === 1 ? 'entry' : 'entries'} · ${kpis.todayNames.join(', ')}`
                    }
                />
            </section>

            <section className="ts-body">
                <article className="cf-card ts-team">
                    <h2 className="cf-dot-title t-title">
                        {isThisMonth
                            ? 'Team this month'
                            : `Team in ${monthShort}`}
                    </h2>
                    {team.length === 0 && (
                        <p className="t-caption t-muted m-0">
                            No team members yet.
                        </p>
                    )}
                    {team.map((m) => (
                        <div key={m.id} className="cf-inset cf-row gap-2.5">
                            <span
                                className="cf-avatar cf-avatar--sm"
                                aria-hidden="true"
                            >
                                {initialsFrom(m.name)}
                                <span
                                    className="cf-avatar-dot"
                                    style={{
                                        background: dotFor(
                                            options.partners,
                                            m.partnerId,
                                        ),
                                    }}
                                />
                            </span>
                            <span className="cf-stack min-w-0 flex-1">
                                <span className="t-body font-medium">
                                    {m.name}
                                </span>
                                <span
                                    className="cf-progress cf-progress--thin mt-[5px]"
                                    aria-hidden="true"
                                >
                                    <span
                                        style={{
                                            width: `${Math.min(100, (m.hoursCenti / BAR_FULL_CENTI) * 100)}%`,
                                        }}
                                    />
                                </span>
                            </span>
                            <span className="cf-stack text-right">
                                <span className="t-money-sm">
                                    {formatCenti(m.hoursCenti)} h
                                </span>
                                <span className="t-caption t-muted">
                                    {!m.active
                                        ? 'Inactive'
                                        : m.fixed
                                          ? `${formatInr(m.earningsPaise, { decimals: 'never' })} accrued`
                                          : formatInr(m.earningsPaise, {
                                                decimals: 'never',
                                            })}
                                </span>
                            </span>
                        </div>
                    ))}
                </article>

                <article className="cf-card ts-entries">
                    <div className="cf-kv flex-wrap gap-2">
                        <h2 className="cf-dot-title t-title">Entries</h2>
                        <div className="cf-actions flex-wrap">
                            <SearchField
                                variant="inline"
                                aria-label="Search entries"
                                placeholder="Search entries"
                                value={search}
                                onValueChange={onSearch}
                                wrapClassName="cf-inwrap--sm ts-search"
                            />
                            <MonthPicker
                                aria-label="Month"
                                value={ym}
                                max={today.slice(0, 7)}
                                onValueChange={(m) =>
                                    visit({ ...filters, month: m })
                                }
                            />
                            <ButtonGroupSelect
                                aria-label="Billable filter"
                                value={filters.billable ?? 'all'}
                                onValueChange={(billable) =>
                                    visit({
                                        ...query,
                                        billable:
                                            billable === 'all'
                                                ? undefined
                                                : billable,
                                    })
                                }
                                options={[
                                    { value: 'all', label: 'All' },
                                    { value: 'billable', label: 'Billable' },
                                    { value: 'non', label: 'Non-billable' },
                                ]}
                            />
                        </div>
                    </div>
                    <div className="cf-row flex-wrap gap-2">
                        {options.members.length > 1 && (
                            <Select
                                aria-label="Member"
                                pill
                                value={filters.member ?? ''}
                                onChange={(e) =>
                                    visit({
                                        ...query,
                                        member: e.target.value || undefined,
                                    })
                                }
                                options={[
                                    { value: '', label: 'All members' },
                                    ...options.members.map((m) => ({
                                        value: String(m.id),
                                        label: m.name,
                                    })),
                                ]}
                            />
                        )}
                        <Select
                            aria-label="Partner"
                            pill
                            value={filters.partner ?? ''}
                            onChange={(e) =>
                                visit({
                                    ...query,
                                    partner: e.target.value || undefined,
                                })
                            }
                            options={[
                                { value: '', label: 'All partners' },
                                ...options.partners.map((p) => ({
                                    value: String(p.id),
                                    label: p.name,
                                })),
                            ]}
                        />
                        <Select
                            aria-label="Project"
                            pill
                            value={filters.project ?? ''}
                            onChange={(e) =>
                                visit({
                                    ...query,
                                    project: e.target.value || undefined,
                                })
                            }
                            options={[
                                { value: '', label: 'All projects' },
                                ...options.projects.map((p) => ({
                                    value: String(p.id),
                                    label: p.name,
                                })),
                            ]}
                        />
                    </div>
                    <DataTable
                        aria-label={`Timesheet entries, ${monthLong(month)}`}
                        rows={entries.data}
                        columns={columns}
                        getRowId={(e) => String(e.id)}
                        minWidth={860}
                        actionsWidth="90px"
                        rowActions={(e) => (
                            <span className="cf-rowacts">
                                <IconButton
                                    icon={Pencil}
                                    size="sm"
                                    aria-label={`Edit ${e.memberName} on ${formatDate(e.date)}`}
                                    disabled={!can.log}
                                    onClick={() => onLog(e)}
                                />
                                <IconButton
                                    icon={Trash2}
                                    size="sm"
                                    aria-label={`Delete ${e.memberName} on ${formatDate(e.date)}`}
                                    disabled={!can.log}
                                    onClick={() => onDelete(e)}
                                />
                            </span>
                        )}
                        empty={{
                            title:
                                Object.keys(filters).length > 0
                                    ? 'No entries match these filters'
                                    : `No hours logged in ${monthLong(month)}`,
                            icon: Clock,
                            action: can.log ? (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={Plus}
                                    onClick={() => onLog(null)}
                                >
                                    Log hours
                                </Button>
                            ) : undefined,
                        }}
                    />
                    <PaginationBar
                        summary={`Showing ${entries.data.length} of ${entries.total} ${entries.total === 1 ? 'entry' : 'entries'} in ${monthLong(month)}`}
                    >
                        {entries.last_page > 1 && (
                            <Pager
                                page={entries.current_page}
                                pageCount={entries.last_page}
                                onPageChange={(page) =>
                                    visit({ ...query, page })
                                }
                            />
                        )}
                    </PaginationBar>
                </article>
            </section>
        </>
    );
}

const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function PhoneTimesheets({
    today,
    week,
    can,
    onLog,
}: Props & { onLog: () => void }) {
    const [day, setDay] = useState(week.day);
    const [lastWeek, setLastWeek] = useState(week);

    if (lastWeek !== week) {
        setLastWeek(week);
        setDay(week.day);
    }

    const start = toDate(week.start);
    const days = DOW.map((dow, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);

        return { dow, iso: toIsoDate(d), date: d };
    });
    const end = days[6].date;
    const range = `${start.getDate()} – ${formatDate(end)}`;
    const weekCenti = week.entries.reduce((s, e) => s + e.hoursCenti, 0);
    const shown = week.entries.filter((e) => e.date === day);
    const monthName = (d: Date) =>
        d.toLocaleDateString('en-IN', { month: 'long' });

    return (
        <>
            <div className="ts-mtop">
                <h1 className="t-heading">Timesheets</h1>
                {can.log && (
                    <Button variant="primary" size="sm" onClick={onLog}>
                        Log hours
                    </Button>
                )}
            </div>
            <div className="cf-kv">
                <span className="t-label-strong">{range}</span>
                <span className="t-money-sm t-muted">
                    {formatCenti(weekCenti)} h this week
                </span>
            </div>
            <div className="wk" role="group" aria-label="Days">
                {days.map((d) => (
                    <button
                        key={d.iso}
                        type="button"
                        aria-pressed={d.iso === day}
                        aria-label={`${d.dow} ${d.date.getDate()} ${monthName(d.date)}`}
                        disabled={d.iso > today}
                        onClick={() => setDay(d.iso)}
                    >
                        <span className="t-caption opacity-70">{d.dow}</span>
                        <span className="t-label-strong">
                            {d.date.getDate()}
                        </span>
                    </button>
                ))}
            </div>
            {shown.map((e) => {
                const d = toDate(e.date);

                return (
                    <div key={e.id} className="cf-card ts-mcard">
                        <span
                            className="cf-avatar cf-avatar--graphite"
                            aria-hidden="true"
                        >
                            {initialsFrom(e.memberName)}
                        </span>
                        <span className="cf-stack min-w-0">
                            <span className="t-label-strong">
                                {e.memberName}
                            </span>
                            <span className="t-caption t-muted">
                                {e.projectName} · {DOW[(d.getDay() + 6) % 7]}{' '}
                                {d.getDate()}{' '}
                                {d.toLocaleDateString('en-IN', {
                                    month: 'short',
                                })}
                            </span>
                        </span>
                        <span className="cf-stack items-end">
                            <span className="t-money">
                                {formatCenti(e.hoursCenti)} h
                            </span>
                            <span className="t-caption t-muted">
                                {e.billable ? 'Billable' : 'Internal'}
                            </span>
                        </span>
                    </div>
                );
            })}
            {shown.length === 0 && (
                <div className="cf-empty">
                    <span className="t-label-strong">No hours on this day</span>
                    <span className="t-caption t-muted">
                        {can.log
                            ? 'Tap Log hours to add some.'
                            : 'Nothing was logged.'}
                    </span>
                </div>
            )}
        </>
    );
}

/** Delete one entry, or every entry logged with it in month mode (spec §8.1). */
function DeleteEntry({
    entry,
    onClose,
}: {
    entry: TimeEntry | null;
    onClose: () => void;
}) {
    const [busy, setBusy] = useState<'one' | 'batch' | null>(null);

    const remove = (batch: boolean) => {
        if (!entry) {
            return;
        }

        router.delete(deleteEntry(entry.id).url, {
            data: batch ? { batch: 1 } : {},
            preserveScroll: true,
            onStart: () => setBusy(batch ? 'batch' : 'one'),
            onFinish: () => {
                setBusy(null);
                onClose();
            },
        });
    };

    return (
        <Modal
            open={entry !== null}
            onOpenChange={(o) => !o && onClose()}
            title="Delete hours?"
            subtitle={
                entry
                    ? `${entry.memberName} · ${formatCenti(entry.hoursCenti)} h on ${formatDate(entry.date)}`
                    : undefined
            }
            icon={Trash2}
            size="sm"
            footer={
                <>
                    <Button variant="secondary" size="lg" onClick={onClose}>
                        Cancel
                    </Button>
                    {entry && entry.batchSize > 1 && (
                        <Button
                            variant="danger"
                            size="lg"
                            loading={busy === 'batch'}
                            onClick={() => remove(true)}
                        >
                            Delete all {entry.batchSize}
                        </Button>
                    )}
                    <Button
                        variant="danger"
                        size="lg"
                        loading={busy === 'one'}
                        onClick={() => remove(false)}
                    >
                        Delete entry
                    </Button>
                </>
            }
        >
            <p className="t-body m-0">
                {entry && entry.batchSize > 1
                    ? `This entry was logged with ${entry.batchSize - 1} others for the month. Delete just this day, or all ${entry.batchSize}.`
                    : 'The hours come off the timesheet and this month’s earnings.'}
            </p>
        </Modal>
    );
}

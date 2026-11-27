import { router } from '@inertiajs/react';
import {
    CalendarDays,
    Check,
    ChevronDown,
    Download,
    FileText,
    Grid3x3,
    Receipt,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { cleanQuery, FyMenu } from '@/components/finance/fy-menu';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Avatar } from '@/components/ui/avatar';
import { Button, buttonClass } from '@/components/ui/button';
import { Calendar, DateRangePicker } from '@/components/ui/date-picker';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { Popover } from '@/components/ui/popover';
import { formatDate, formatInr } from '@/lib/format';
import type { DriftIssue, StatementDateProps } from '@/types/reports';

/** The statement's controls: As of (a calendar inside the year), the year, Export. */
export function StatementControls({
    date,
    url,
    exportUrl,
}: {
    date: StatementDateProps;
    /** The page's own URL. */
    url: string;
    exportUrl: (format: 'pdf' | 'xlsx') => string;
}) {
    const [open, setOpen] = useState(false);
    const go = (query: Record<string, string | number>) =>
        router.get(url, cleanQuery(query), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });

    return (
        <>
            <Popover
                title="As of"
                open={open}
                onOpenChange={setOpen}
                align="end"
                trigger={
                    <button
                        type="button"
                        className="cf-btn cf-btn--muted cf-btn--lg"
                        aria-label={`As of ${formatDate(date.asOf)}, change the date`}
                    >
                        <Icon icon={CalendarDays} size={18} />
                        As of {formatDate(date.asOf)}
                        <Icon icon={ChevronDown} size={16} />
                    </button>
                }
            >
                <Calendar
                    selected={date.asOf}
                    min={date.min}
                    max={date.max}
                    defaultFocus={date.asOf}
                    autoFocus
                    onSelect={(iso) => {
                        setOpen(false);
                        go({ fy: date.fy.start, asof: iso });
                    }}
                />
            </Popover>
            <FyMenu fy={date.fy} onPick={(start) => go({ fy: start })} />
            <DropdownMenu
                aria-label="Export"
                align="end"
                trigger={
                    <button
                        type="button"
                        className={buttonClass({
                            variant: 'secondary',
                            size: 'lg',
                        })}
                    >
                        <Icon icon={Download} size={18} strokeWidth={1.75} />
                        Export
                    </button>
                }
                entries={[
                    {
                        label: 'PDF',
                        icon: FileText,
                        onSelect: () =>
                            window.location.assign(exportUrl('pdf')),
                    },
                    {
                        label: 'Excel workbook (.xlsx)',
                        icon: Grid3x3,
                        onSelect: () =>
                            window.location.assign(exportUrl('xlsx')),
                    },
                ]}
            />
        </>
    );
}

/**
 * Controls for a statement over a period (D155): From–To inside the year, the year, and
 * Export in the formats given.
 */
export function PeriodControls({
    date,
    url,
    exportUrl,
    formats = ['pdf', 'xlsx'],
}: {
    date: StatementDateProps;
    url: string;
    exportUrl: (format: ExportFormat) => string;
    formats?: ExportFormat[];
}) {
    const [open, setOpen] = useState(false);
    const go = (query: Record<string, string | number>) =>
        router.get(url, cleanQuery(query), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    const range = `${formatDate(date.from)} – ${formatDate(date.asOf)}`;

    return (
        <>
            <Popover
                title="Period"
                open={open}
                onOpenChange={setOpen}
                align="end"
                trigger={
                    <button
                        type="button"
                        className="cf-btn cf-btn--muted cf-btn--lg"
                        aria-label={`Period ${range}, change the dates`}
                    >
                        <Icon icon={CalendarDays} size={18} />
                        {range}
                        <Icon icon={ChevronDown} size={16} />
                    </button>
                }
            >
                <div className="sm-period">
                    <DateRangePicker
                        label="From – to"
                        min={date.min}
                        max={date.max}
                        value={{ start: date.from, end: date.asOf }}
                        onValueChange={(r) => {
                            if (r.start && r.end) {
                                setOpen(false);
                                go({
                                    fy: date.fy.start,
                                    from: r.start,
                                    asof: r.end,
                                });
                            }
                        }}
                    />
                    <button
                        type="button"
                        className="cf-btn cf-btn--ghost cf-btn--sm"
                        onClick={() => {
                            setOpen(false);
                            go({ fy: date.fy.start });
                        }}
                    >
                        Whole year to date
                    </button>
                </div>
            </Popover>
            <FyMenu fy={date.fy} onPick={(start) => go({ fy: start })} />
            <ExportMenu formats={formats} exportUrl={exportUrl} />
        </>
    );
}

export type ExportFormat = 'pdf' | 'xlsx' | 'csv' | 'json';

const EXPORT_LABEL: Record<ExportFormat, string> = {
    pdf: 'PDF',
    xlsx: 'Excel workbook (.xlsx)',
    csv: 'CSV',
    json: 'JSON (for your CA)',
};

/** The statement Export menu. */
export function ExportMenu({
    formats,
    exportUrl,
}: {
    formats: ExportFormat[];
    exportUrl: (format: ExportFormat) => string;
}) {
    return (
        <DropdownMenu
            aria-label="Export"
            align="end"
            trigger={
                <button
                    type="button"
                    className={buttonClass({
                        variant: 'secondary',
                        size: 'lg',
                    })}
                >
                    <Icon icon={Download} size={18} strokeWidth={1.75} />
                    Export
                </button>
            }
            entries={formats.map((format) => ({
                label: EXPORT_LABEL[format],
                icon: format === 'pdf' ? FileText : Grid3x3,
                onSelect: () => window.location.assign(exportUrl(format)),
            }))}
        />
    );
}

/** "Checked just now" / "Checked 3 min ago", ticking. */
function useCheckedText(checkedAt: string): string {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    const minutes = Math.max(
        0,
        Math.floor((now - new Date(checkedAt).getTime()) / 60_000),
    );

    return minutes < 1 ? 'Checked just now' : `Checked ${minutes} min ago`;
}

/**
 * The status banner (Statements.dc.html): "Balanced ✓" with Recheck, or "Out of balance —
 * drift ₹X" with "Find the drift", which loads the diagnostics on demand (D60).
 */
export function BalanceBanner({
    balanced,
    driftPaise,
    totalText,
    detail,
    checkedAt,
    issues,
    what,
}: {
    balanced: boolean;
    driftPaise: number;
    /** "Debits and credits both total ₹73,62,900". */
    totalText: React.ReactNode;
    /** "as of 15 Nov 2026 · FY 2026-27". */
    detail: string;
    checkedAt: string;
    issues: DriftIssue[] | undefined;
    /** "Trial balance" / "Balance sheet". */
    what: string;
}) {
    const checked = useCheckedText(checkedAt);
    const [checking, setChecking] = useState(false);
    const [finding, setFinding] = useState(false);
    const [shown, setShown] = useState(false);

    const recheck = () =>
        router.reload({
            data: { recheck: 1 },
            onStart: () => setChecking(true),
            onFinish: () => setChecking(false),
        });

    const find = () => {
        if (shown) {
            setShown(false);

            return;
        }

        setShown(true);

        if (issues === undefined) {
            router.reload({
                only: ['drift'],
                onStart: () => setFinding(true),
                onFinish: () => setFinding(false),
            });
        }
    };

    if (balanced) {
        return (
            <AlertBanner
                variant="pos"
                icon={Check}
                iconStrokeWidth={2}
                title="Balanced ✓"
                aria-label="Balance check"
                action={
                    <div className="cf-row">
                        <span className="t-caption">{checked}</span>
                        <Button loading={checking} onClick={recheck}>
                            {checking ? 'Checking' : 'Recheck'}
                        </Button>
                    </div>
                }
            >
                {totalText} · {detail}
            </AlertBanner>
        );
    }

    return (
        <>
            <AlertBanner
                variant="warn"
                icon={TriangleAlert}
                role="alert"
                title={
                    <>
                        Out of balance — drift{' '}
                        <span className="t-money">
                            {formatInr(Math.abs(driftPaise))}
                        </span>
                    </>
                }
                action={
                    <Button
                        loading={finding}
                        onClick={find}
                        aria-expanded={shown}
                    >
                        {shown ? 'Hide entries' : 'Find the drift'}
                    </Button>
                }
            >
                {what} {detail}.{' '}
                {driftPaise > 0
                    ? 'Debits are higher than credits.'
                    : 'Credits are higher than debits.'}{' '}
                Books posted through Coffer always balance, so this points to a
                bug or a change made outside the app.
            </AlertBanner>
            {shown && issues !== undefined && <DriftList issues={issues} />}
        </>
    );
}

const TYPE_LABELS: Record<string, string> = {
    opening: 'Opening balance',
    income: 'Income',
    expense: 'Expense',
    reimbursement: 'Reimbursement',
    salary: 'Salary',
    contribution: 'Capital contribution',
    drawing: 'Drawing',
    loan: 'Loan',
    repayment: 'Loan repayment',
    loan_conversion: 'Loan conversion',
    settlement: 'Settlement transfer',
    fy_close: 'Year-end close',
    gst_payment: 'GST payment',
    tds_challan: 'TDS deposit',
};

/** What "Find the drift" found: each entry or account to look at. */
export function DriftList({ issues }: { issues: DriftIssue[] }) {
    if (issues.length === 0) {
        return (
            <section className="cf-card" aria-label="Entries causing the drift">
                <p className="t-body m-0">
                    No single entry or account explains it: every entry balances
                    and every account is the right kind. Recheck, and if it
                    stays, report it with the date above.
                </p>
            </section>
        );
    }

    return (
        <section
            className="cf-card sm-drift"
            aria-label="Entries causing the drift"
        >
            <ul className="sm-drift-list">
                {issues.map((issue, i) => (
                    <li
                        key={`${issue.kind}-${issue.entryId ?? issue.accountId}-${i}`}
                        className="sm-drift-item"
                    >
                        <Avatar
                            icon={Receipt}
                            tile
                            size="md"
                            label={false}
                            className="sm-drift-ic"
                        />
                        <div className="cf-stack">
                            <span className="t-label-strong">
                                {issue.entryId !== null
                                    ? `${TYPE_LABELS[issue.type ?? ''] ?? 'Entry'} #${issue.entryId}`
                                    : (issue.account ?? 'Account')}
                                {issue.amount !== 0 && (
                                    <>
                                        {' · '}
                                        <span className="t-money">
                                            {formatInr(issue.amount)}
                                        </span>
                                    </>
                                )}
                            </span>
                            <span className="t-caption t-muted">
                                {[
                                    issue.date ? formatDate(issue.date) : null,
                                    issue.memo,
                                    issue.message,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

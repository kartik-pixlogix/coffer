import { Head, router } from '@inertiajs/react';
import { cleanQuery } from '@/components/finance/fy-menu';
import {
    BalanceBanner,
    StatementControls,
} from '@/components/reports/statement-parts';
import {
    figure,
    periodLabel,
    ScheduleNotes,
} from '@/components/reports/schedule-three';
import { StatementTabs } from '@/components/reports/statement-tabs';
import { PageHeader } from '@/components/shell/page-header';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { TableWrap } from '@/components/ui/table-wrap';
import { formatDate, formatInr } from '@/lib/format';
import { balanceSheet as balanceSheetRoute } from '@/routes/reports';
import { exportMethod as exportBalanceSheet } from '@/routes/reports/balance-sheet';
import type {
    BalanceSheetLine,
    DriftIssue,
    ScheduleBalanceSheet,
    StatementDateProps,
    StatementQuery,
} from '@/types/reports';

type Props = {
    company: { name: string };
    date: StatementDateProps;
    query: StatementQuery;
    sheet: {
        assets: BalanceSheetLine[];
        liabilities: BalanceSheetLine[];
        equity: BalanceSheetLine[];
        totalAssetsPaise: number;
        totalLiabilitiesPaise: number;
        totalEquityPaise: number;
        currentYearProfitPaise: number;
        driftPaise: number;
    };
    /** Closing capital on Capital accounts for the year to this date (invariant 6). */
    capitalClosingPaise: number;
    checkedAt: string;
    drift?: DriftIssue[];
    /** Standard, or Schedule III (Division I, D156). */
    layout: 'standard' | 'schedule3';
    schedule: ScheduleBalanceSheet | null;
};

const LAYOUTS: { value: Props['layout']; label: string }[] = [
    { value: 'standard', label: 'Standard' },
    { value: 'schedule3', label: 'Schedule III' },
];

function Line({ line }: { line: BalanceSheetLine }) {
    return (
        <div className="bsl">
            <span>{line.name}</span>
            <span className="t-money">{formatInr(line.amountPaise)}</span>
        </div>
    );
}

/**
 * Balance sheet (Statements.dc.html, spec §15): liabilities and equity (loans,
 * reimbursements, partner current credits, capital per partner, current-year profit)
 * beside assets (money accounts, partner current debits), as of a date, with the status
 * banner and the cross-check against Capital accounts.
 */
export default function BalanceSheet({
    date,
    query,
    sheet,
    capitalClosingPaise,
    checkedAt,
    drift,
    layout,
    schedule,
}: Props) {
    const balanced = sheet.driftPaise === 0;
    const detail = `as of ${formatDate(date.asOf)} · ${date.fy.label}`;
    const le = sheet.totalLiabilitiesPaise + sheet.totalEquityPaise;
    const matchesCapital = capitalClosingPaise === sheet.totalEquityPaise;

    return (
        <>
            <Head title="Balance sheet" />
            <PageHeader
                kicker="Statements"
                title="Balance sheet"
                crumbs={[{ title: 'Statements' }, { title: 'Balance sheet' }]}
                actions={
                    <StatementControls
                        date={date}
                        url={balanceSheetRoute().url}
                        exportUrl={(format) =>
                            exportBalanceSheet({
                                query: {
                                    ...cleanQuery(query),
                                    format,
                                    ...(layout === 'schedule3'
                                        ? { layout }
                                        : {}),
                                },
                            }).url
                        }
                    />
                }
            />

            <StatementTabs current="balance_sheet" query={query} />
            <div className="cf-row sm-layout">
                <SegmentedControl
                    aria-label="Layout"
                    options={LAYOUTS}
                    value={layout}
                    onValueChange={(next) =>
                        router.get(
                            balanceSheetRoute().url,
                            cleanQuery({
                                ...query,
                                layout: next === 'schedule3' ? next : null,
                            }),
                            { preserveScroll: true, replace: true },
                        )
                    }
                />
            </div>

            <BalanceBanner
                balanced={balanced}
                driftPaise={sheet.driftPaise}
                totalText={
                    <>
                        Assets and liabilities plus equity both total{' '}
                        <span className="t-money">
                            {formatInr(sheet.totalAssetsPaise)}
                        </span>
                    </>
                }
                detail={detail}
                checkedAt={checkedAt}
                issues={drift}
                what="Balance sheet"
            />

            {layout === 'schedule3' && schedule !== null ? (
                <ScheduleSheet schedule={schedule} />
            ) : (
                <section className="cf-card sm-card" aria-labelledby="bs-h">
                    <div className="cf-kv flex-wrap">
                        <h2 id="bs-h" className="cf-dot-title t-title">
                            Balance sheet
                        </h2>
                        <span
                            className={`cf-pill ${balanced ? 'cf-pill--pos' : 'cf-pill--warn'}`}
                        >
                            {balanced
                                ? 'Assets = liabilities + equity'
                                : `Off by ${formatInr(Math.abs(sheet.driftPaise))}`}
                        </span>
                    </div>
                    <div className="sm-bs">
                        <div
                            className="sm-bs-col"
                            role="group"
                            aria-label="Liabilities and equity"
                        >
                            <h3 className="t-label t-muted sm-bs-h">
                                Liabilities &amp; equity
                            </h3>
                            {sheet.liabilities.map((l) => (
                                <Line key={`l-${l.name}`} line={l} />
                            ))}
                            {sheet.liabilities.length === 0 && (
                                <div className="bsl t-muted">
                                    <span>No liabilities</span>
                                    <span className="t-money">
                                        {formatInr(0)}
                                    </span>
                                </div>
                            )}
                            <div className="bsl sub">
                                <span>Total liabilities</span>
                                <span className="t-money">
                                    {formatInr(sheet.totalLiabilitiesPaise)}
                                </span>
                            </div>
                            <div className="sm-bs-gap" />
                            {sheet.equity.map((l) => (
                                <Line key={`e-${l.name}`} line={l} />
                            ))}
                            <div className="bsl sub">
                                <span>Total equity</span>
                                <span className="t-money">
                                    {formatInr(sheet.totalEquityPaise)}
                                </span>
                            </div>
                            <div className="bsl grand">
                                <span>Total liabilities &amp; equity</span>
                                <span className="t-money">{formatInr(le)}</span>
                            </div>
                        </div>
                        <div
                            className="sm-bs-col"
                            role="group"
                            aria-label="Assets"
                        >
                            <h3 className="t-label t-muted sm-bs-h">Assets</h3>
                            {sheet.assets.map((l) => (
                                <Line key={`a-${l.name}`} line={l} />
                            ))}
                            <div className="sm-bs-fill" aria-hidden="true" />
                            <div className="bsl grand">
                                <span>Total assets</span>
                                <span className="t-money">
                                    {formatInr(sheet.totalAssetsPaise)}
                                </span>
                            </div>
                        </div>
                    </div>
                    <p className="t-caption t-muted m-0">
                        {matchesCapital
                            ? `Partner capital plus profit matches the ${formatInr(capitalClosingPaise)} closing total on Capital accounts.`
                            : `Capital accounts close at ${formatInr(capitalClosingPaise)}; equity here includes profit of earlier years that aren’t closed yet.`}{' '}
                        Partner loans sit under liabilities, not equity.
                    </p>
                </section>
            )}
        </>
    );
}

/** The balance sheet in the Schedule III format, with its notes (D156). */
function ScheduleSheet({ schedule }: { schedule: ScheduleBalanceSheet }) {
    const current = periodLabel(null, schedule.asOf);
    const previous =
        schedule.previousAsOf === null
            ? null
            : periodLabel(null, schedule.previousAsOf);

    return (
        <>
            <section className="cf-card sm-card" aria-labelledby="s3-h">
                <div className="cf-kv">
                    <h2 id="s3-h" className="cf-dot-title t-title">
                        Balance sheet
                    </h2>
                    <span className="t-caption t-muted">
                        Schedule III, Division I · as at {current}
                    </span>
                </div>
                <TableWrap label="Balance sheet, Schedule III">
                    <table className="s3-table s3-statement">
                        <thead>
                            <tr>
                                <th scope="col">Particulars</th>
                                <th scope="col" className="s3-note-col">
                                    Note
                                </th>
                                <th scope="col" className="s3-num">
                                    {current}
                                </th>
                                {previous !== null && (
                                    <th scope="col" className="s3-num">
                                        {previous}
                                    </th>
                                )}
                            </tr>
                        </thead>
                        {schedule.sections.map((section, s) => (
                            <tbody key={section.title}>
                                <tr className="s3-head">
                                    <th
                                        scope="rowgroup"
                                        colSpan={previous === null ? 3 : 4}
                                    >
                                        {s === 0 ? 'I.' : 'II.'} {section.title}
                                    </th>
                                </tr>
                                {section.groups.map((group, g) => (
                                    <GroupRows
                                        key={group.title ?? g}
                                        group={group}
                                        index={g}
                                        withPrevious={previous !== null}
                                    />
                                ))}
                                <tr className="s3-strong">
                                    <th scope="row">Total</th>
                                    <td className="s3-note-col" />
                                    <td className="s3-num t-money-sm">
                                        {figure(section.total)}
                                    </td>
                                    {previous !== null && (
                                        <td className="s3-num t-money-sm">
                                            {figure(section.previousTotal)}
                                        </td>
                                    )}
                                </tr>
                            </tbody>
                        ))}
                    </table>
                </TableWrap>
                <p className="t-caption t-muted m-0">
                    A firm’s balance sheet: partners’ funds take the place of
                    shareholders’ funds. Loans from partners are repayable on
                    demand, so they sit under current liabilities.
                </p>
            </section>
            <ScheduleNotes
                notes={schedule.notes}
                current={current}
                previous={previous}
            />
        </>
    );
}

function GroupRows({
    group,
    index,
    withPrevious,
}: {
    group: ScheduleBalanceSheet['sections'][number]['groups'][number];
    index: number;
    withPrevious: boolean;
}) {
    return (
        <>
            {group.title && (
                <tr>
                    <th
                        scope="rowgroup"
                        colSpan={withPrevious ? 4 : 3}
                        className="s3-sub"
                    >
                        ({index + 1}) {group.title}
                    </th>
                </tr>
            )}
            {group.lines.map((line, i) => (
                <tr key={line.label}>
                    <th scope="row" className="s3-indent">
                        ({String.fromCharCode(97 + i)}) {line.label}
                    </th>
                    <td className="s3-note-col t-muted">{line.note ?? ''}</td>
                    <td className="s3-num t-money-sm">{figure(line.amount)}</td>
                    {withPrevious && (
                        <td className="s3-num t-money-sm">
                            {figure(line.previous)}
                        </td>
                    )}
                </tr>
            ))}
        </>
    );
}

import { Head } from '@inertiajs/react';
import { Check, TriangleAlert } from 'lucide-react';
import { cleanQuery } from '@/components/finance/fy-menu';
import { figure } from '@/components/reports/schedule-three';
import { PeriodControls } from '@/components/reports/statement-parts';
import { StatementTabs } from '@/components/reports/statement-tabs';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { TableWrap } from '@/components/ui/table-wrap';
import { formatDate, formatInr } from '@/lib/format';
import { cashFlow as cashFlowRoute } from '@/routes/reports';
import { exportMethod as exportCashFlow } from '@/routes/reports/cash-flow';
import type {
    CashFlowLine,
    CashFlowStatement,
    StatementDateProps,
    StatementQuery,
} from '@/types/reports';

type Props = {
    company: { name: string };
    date: StatementDateProps;
    query: StatementQuery;
    flow: CashFlowStatement;
};

function Section({
    title,
    lines,
    total,
    totalLabel,
    empty,
}: {
    title: string;
    lines: CashFlowLine[];
    total: number;
    totalLabel: string;
    empty?: string;
}) {
    return (
        <>
            <tr className="s3-head">
                <th scope="rowgroup" colSpan={2}>
                    {title}
                </th>
            </tr>
            {lines.length === 0 && empty && (
                <tr>
                    <td className="t-muted">{empty}</td>
                    <td className="s3-num t-money-sm">—</td>
                </tr>
            )}
            {lines.map((line) => (
                <tr key={line.key}>
                    <th scope="row" className="s3-indent">
                        {line.label}
                    </th>
                    <td className="s3-num t-money-sm">{figure(line.amount)}</td>
                </tr>
            ))}
            <tr className="s3-strong">
                <th scope="row">{totalLabel}</th>
                <td className="s3-num t-money-sm">{figure(total)}</td>
            </tr>
        </>
    );
}

/**
 * Cash flow statement, indirect method (AS 3, D157): from net profit to the change in cash
 * through working capital, investing and financing, reconciled to the money accounts'
 * opening and closing balances.
 */
export default function CashFlow({ date, query, flow }: Props) {
    const detail = `${formatDate(date.from)} – ${formatDate(date.asOf)} · ${date.fy.label}`;

    return (
        <>
            <Head title="Cash flow" />
            <PageHeader
                kicker="Statements"
                title="Cash flow"
                crumbs={[{ title: 'Statements' }, { title: 'Cash flow' }]}
                actions={
                    <PeriodControls
                        date={date}
                        url={cashFlowRoute().url}
                        exportUrl={(format) =>
                            exportCashFlow({
                                query: { ...cleanQuery(query), format },
                            }).url
                        }
                    />
                }
            />
            <StatementTabs current="cash_flow" query={query} />

            {flow.reconciled ? (
                <AlertBanner
                    variant="pos"
                    icon={Check}
                    iconStrokeWidth={2}
                    title="Reconciled ✓"
                    aria-label="Cash check"
                >
                    Opening cash{' '}
                    <span className="t-money">
                        {formatInr(flow.openingCash)}
                    </span>{' '}
                    plus the net change{' '}
                    <span className="t-money">{formatInr(flow.netChange)}</span>{' '}
                    equals the closing cash in the money accounts,{' '}
                    <span className="t-money">
                        {formatInr(flow.closingCash)}
                    </span>
                    .
                </AlertBanner>
            ) : (
                <AlertBanner
                    variant="warn"
                    icon={TriangleAlert}
                    role="alert"
                    title={
                        <>
                            Doesn’t reconcile — off by{' '}
                            <span className="t-money">
                                {formatInr(Math.abs(flow.drift))}
                            </span>
                        </>
                    }
                >
                    The statement and the money accounts disagree for {detail}.
                    Books posted through Coffer always reconcile, so this points
                    to a bug or a change made outside the app.
                </AlertBanner>
            )}

            <section className="cf-card sm-card" aria-labelledby="cf-h">
                <div className="cf-kv">
                    <h2 id="cf-h" className="cf-dot-title t-title">
                        Cash flow statement
                    </h2>
                    <span className="t-caption t-muted">
                        Indirect method · {detail}
                    </span>
                </div>
                <TableWrap label="Cash flow statement">
                    <table className="s3-table s3-statement">
                        <thead>
                            <tr>
                                <th scope="col">Particulars</th>
                                <th scope="col" className="s3-num">
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <Section
                                title="A. Cash flow from operating activities"
                                lines={flow.operating}
                                total={flow.operatingTotal}
                                totalLabel="Net cash from operating activities (A)"
                            />
                            <Section
                                title="B. Cash flow from investing activities"
                                lines={flow.investing}
                                total={flow.investingTotal}
                                totalLabel="Net cash from investing activities (B)"
                                empty="No investing activity: equipment is expensed when bought"
                            />
                            <Section
                                title="C. Cash flow from financing activities"
                                lines={flow.financing}
                                total={flow.financingTotal}
                                totalLabel="Net cash from financing activities (C)"
                            />
                            <tr className="s3-strong">
                                <th scope="row">
                                    Net increase / (decrease) in cash (A + B +
                                    C)
                                </th>
                                <td className="s3-num t-money-sm">
                                    {figure(flow.netChange)}
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    Cash and cash equivalents at the start
                                </th>
                                <td className="s3-num t-money-sm">
                                    {figure(flow.openingCash)}
                                </td>
                            </tr>
                            <tr className="s3-strong">
                                <th scope="row">
                                    Cash and cash equivalents at the end
                                </th>
                                <td className="s3-num t-money-sm">
                                    {figure(flow.closingCash)}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </TableWrap>
            </section>

            <section className="cf-card sm-card" aria-labelledby="cash-h">
                <h2 id="cash-h" className="cf-dot-title t-title">
                    Cash and cash equivalents
                </h2>
                <TableWrap label="Cash and cash equivalents">
                    <table className="s3-table">
                        <thead>
                            <tr>
                                <th scope="col">Account</th>
                                <th scope="col" className="s3-num">
                                    At the start
                                </th>
                                <th scope="col" className="s3-num">
                                    At the end
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {flow.cash.map((c) => (
                                <tr key={c.code}>
                                    <td>
                                        <span className="t-caption sm-code">
                                            {c.code}{' '}
                                        </span>
                                        {c.name}
                                    </td>
                                    <td className="s3-num t-money-sm">
                                        {figure(c.opening)}
                                    </td>
                                    <td className="s3-num t-money-sm">
                                        {figure(c.closing)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrap>
                <p className="t-caption t-muted m-0">
                    Left out because no cash moves: the opening balance (it is
                    the opening cash), the year-end close, personal-card
                    balances converted to loans, and transfers between the
                    sides’ accounts.
                </p>
            </section>
        </>
    );
}

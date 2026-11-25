import { Head } from '@inertiajs/react';
import { Check, TriangleAlert } from 'lucide-react';
import { cleanQuery } from '@/components/finance/fy-menu';
import {
    figure,
    periodLabel,
    ScheduleNotes,
} from '@/components/reports/schedule-three';
import { PeriodControls } from '@/components/reports/statement-parts';
import { StatementTabs } from '@/components/reports/statement-tabs';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { TableWrap } from '@/components/ui/table-wrap';
import { formatInr } from '@/lib/format';
import { profitAndLoss as profitAndLossRoute } from '@/routes/reports';
import { exportMethod as exportProfitAndLoss } from '@/routes/reports/profit-and-loss';
import type {
    ScheduleProfitAndLoss,
    StatementDateProps,
    StatementQuery,
} from '@/types/reports';

type Props = {
    company: { name: string };
    date: StatementDateProps;
    query: StatementQuery;
    pnl: ScheduleProfitAndLoss;
    /** The statement's totals agree with the ledger's profit and loss (Reports). */
    ties: boolean;
};

/**
 * Statement of profit and loss in the Schedule III format, Division I (D156): revenue,
 * other income, expenses by nature, profit before and after tax, with numbered notes and
 * the same period a year earlier when there is one. Ties to the ledger's own figures.
 */
export default function ProfitAndLoss({ date, query, pnl, ties }: Props) {
    const current = periodLabel(pnl.from, pnl.to);
    const previous =
        pnl.previousFrom === null
            ? null
            : periodLabel(pnl.previousFrom, pnl.previousTo);

    return (
        <>
            <Head title="Profit and loss" />
            <PageHeader
                kicker="Statements"
                title="Profit and loss"
                crumbs={[{ title: 'Statements' }, { title: 'Profit and loss' }]}
                actions={
                    <PeriodControls
                        date={date}
                        url={profitAndLossRoute().url}
                        exportUrl={(format) =>
                            exportProfitAndLoss({
                                query: { ...cleanQuery(query), format },
                            }).url
                        }
                    />
                }
            />
            <StatementTabs current="profit_and_loss" query={query} />

            {ties ? (
                <AlertBanner
                    variant="pos"
                    icon={Check}
                    iconStrokeWidth={2}
                    title="Ties to the ledger ✓"
                    aria-label="Ledger check"
                >
                    Total income{' '}
                    <span className="t-money">
                        {formatInr(pnl.totalIncome)}
                    </span>{' '}
                    and profit{' '}
                    <span className="t-money">{formatInr(pnl.profit)}</span>{' '}
                    match the books for {current}.
                </AlertBanner>
            ) : (
                <AlertBanner
                    variant="warn"
                    icon={TriangleAlert}
                    role="alert"
                    title="Doesn’t tie to the ledger"
                >
                    This statement and the ledger’s profit and loss differ.
                    Books posted through Coffer always agree, so this points to
                    a bug. Please report it with the dates above.
                </AlertBanner>
            )}

            <section className="cf-card sm-card" aria-labelledby="pl-h">
                <div className="cf-kv">
                    <h2 id="pl-h" className="cf-dot-title t-title">
                        Statement of profit and loss
                    </h2>
                    <span className="t-caption t-muted">
                        Schedule III, Division I · {date.fy.label}
                    </span>
                </div>
                <TableWrap label="Statement of profit and loss">
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
                        <tbody>
                            {pnl.lines.map((line) => (
                                <tr
                                    key={line.key}
                                    className={
                                        line.strong ? 's3-strong' : undefined
                                    }
                                >
                                    <th scope="row">{line.label}</th>
                                    <td className="s3-note-col t-muted">
                                        {line.note ?? ''}
                                    </td>
                                    <td className="s3-num t-money-sm">
                                        {line.amount === null
                                            ? ''
                                            : figure(line.amount)}
                                    </td>
                                    {previous !== null && (
                                        <td className="s3-num t-money-sm">
                                            {line.amount === null
                                                ? ''
                                                : figure(line.previous)}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrap>
                <p className="t-caption t-muted m-0">
                    A firm’s statement: income-tax on the partners’ shares is
                    outside these books, so the tax lines are nil. Categories
                    report under the Schedule III line set on the Chart of
                    accounts.
                </p>
            </section>

            <ScheduleNotes
                notes={pnl.notes}
                current={current}
                previous={previous}
            />
        </>
    );
}

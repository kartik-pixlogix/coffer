import { Head } from '@inertiajs/react';
import { cleanQuery } from '@/components/finance/fy-menu';
import {
    BalanceBanner,
    StatementControls,
} from '@/components/reports/statement-parts';
import { PageHeader } from '@/components/shell/page-header';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { formatDate, formatInr } from '@/lib/format';
import { trialBalance as trialBalanceRoute } from '@/routes/reports';
import { exportMethod as exportTrialBalance } from '@/routes/reports/trial-balance';
import type {
    DriftIssue,
    StatementDateProps,
    TrialBalanceAccount,
} from '@/types/reports';

type Props = {
    company: { name: string };
    date: StatementDateProps;
    query: { fy: number; asof: string };
    trial: {
        accounts: TrialBalanceAccount[];
        debitPaise: number;
        creditPaise: number;
        driftPaise: number;
    };
    checkedAt: string;
    /** Loaded on demand by "Find the drift" (D60). */
    drift?: DriftIssue[];
};

const TYPE_LABEL: Record<TrialBalanceAccount['type'], string> = {
    asset: 'Asset',
    liability: 'Liability',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expense',
};

const amount = (paise: number) => (paise === 0 ? '—' : formatInr(paise));

/**
 * Trial balance (Statements.dc.html, spec §15): every account's balance as of a date in
 * the debit or credit column, the totals, and the status banner with "Find the drift".
 */
export default function TrialBalance({
    date,
    query,
    trial,
    checkedAt,
    drift,
}: Props) {
    const balanced = trial.driftPaise === 0;
    const flagged = new Set(
        (drift ?? []).map((d) => d.accountId).filter((id) => id !== null),
    );
    const detail = `as of ${formatDate(date.asOf)} · ${date.fy.label}`;

    const columns: DataTableColumn<TrialBalanceAccount>[] = [
        {
            key: 'account',
            header: 'Account',
            width: 'minmax(260px,2fr)',
            className: 'cf-stack',
            cell: (a) => (
                <>
                    <span className="t-label-strong">{a.name}</span>
                    <span className="t-caption sm-code">{a.code}</span>
                </>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            width: '130px',
            skeleton: 'pill',
            cell: (a) => (
                <span className="cf-pill cf-pill--outline cf-pill--sm">
                    {TYPE_LABEL[a.type] ?? a.type}
                </span>
            ),
        },
        {
            key: 'debit',
            header: 'Debit',
            width: '160px',
            numeric: true,
            cell: (a) => amount(a.debitPaise),
        },
        {
            key: 'credit',
            header: 'Credit',
            width: '160px',
            numeric: true,
            cell: (a) => amount(a.creditPaise),
        },
    ];

    return (
        <>
            <Head title="Trial balance" />
            <PageHeader
                kicker="Statements"
                title="Trial balance"
                crumbs={[{ title: 'Statements' }, { title: 'Trial balance' }]}
                actions={
                    <StatementControls
                        date={date}
                        url={trialBalanceRoute().url}
                        exportUrl={(format) =>
                            exportTrialBalance({
                                query: { ...cleanQuery(query), format },
                            }).url
                        }
                    />
                }
            />

            <BalanceBanner
                balanced={balanced}
                driftPaise={trial.driftPaise}
                totalText={
                    <>
                        Debits and credits both total{' '}
                        <span className="t-money">
                            {formatInr(trial.debitPaise)}
                        </span>
                    </>
                }
                detail={detail}
                checkedAt={checkedAt}
                issues={drift}
                what="Trial balance"
            />

            <section className="cf-card sm-card" aria-labelledby="tb-h">
                <div className="cf-kv">
                    <h2 id="tb-h" className="cf-dot-title t-title">
                        Trial balance
                    </h2>
                    <span className="t-caption t-muted">
                        {trial.accounts.length} accounts · {detail}
                    </span>
                </div>
                <DataTable
                    aria-label="Trial balance"
                    rows={trial.accounts}
                    columns={columns}
                    getRowId={(a) => `${a.code}-${a.id ?? 'x'}`}
                    rowError={(a) => a.id !== null && flagged.has(a.id)}
                    minWidth={760}
                    totals={{
                        account: 'Total',
                        debit: formatInr(trial.debitPaise),
                        credit: formatInr(trial.creditPaise),
                    }}
                    empty={{ title: 'No entries up to this date' }}
                />
                {!balanced && (
                    <div className="cf-tr is-error sm-diff" role="row">
                        <span role="cell" className="t-label-strong">
                            Difference
                        </span>
                        <span role="cell" className="cf-num font-semibold">
                            {formatInr(Math.abs(trial.driftPaise))}{' '}
                            {trial.driftPaise > 0
                                ? 'more in debits'
                                : 'more in credits'}
                        </span>
                    </div>
                )}
                <p className="t-caption t-muted m-0">
                    Balance-sheet accounts run from the beginning; income and
                    expenses from the start of {date.fy.label}, before closing.
                </p>
            </section>
        </>
    );
}

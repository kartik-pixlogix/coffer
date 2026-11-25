import { Head, Link } from '@inertiajs/react';
import { cleanQuery } from '@/components/finance/fy-menu';
import {
    BalanceBanner,
    PeriodControls,
} from '@/components/reports/statement-parts';
import { StatementTabs } from '@/components/reports/statement-tabs';
import { PageHeader } from '@/components/shell/page-header';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { formatDate, formatInr } from '@/lib/format';
import {
    journal as journalRoute,
    trialBalance as trialBalanceRoute,
} from '@/routes/reports';
import { exportMethod as exportTrialBalance } from '@/routes/reports/trial-balance';
import type {
    DriftIssue,
    StatementDateProps,
    StatementQuery,
    TrialBalanceAccount,
} from '@/types/reports';

type Props = {
    company: { name: string };
    date: StatementDateProps;
    query: StatementQuery;
    trial: {
        accounts: TrialBalanceAccount[];
        openingDebitPaise: number;
        openingCreditPaise: number;
        debitPaise: number;
        creditPaise: number;
        closingDebitPaise: number;
        closingCreditPaise: number;
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

/** A signed balance as "₹12,000 Dr" / "₹4,500 Cr". */
const balance = (paise: number) =>
    paise === 0
        ? '—'
        : `${formatInr(Math.abs(paise))} ${paise > 0 ? 'Dr' : 'Cr'}`;

/**
 * Trial balance (Statements.dc.html, spec §15, D155): every account's opening balance,
 * the period's debits and credits, and its closing balance, so opening + movement =
 * closing on each line. The banner checks that all three column pairs agree; each
 * account opens its ledger in the Journal.
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
    const detail = `${formatDate(date.from)} – ${formatDate(date.asOf)} · ${date.fy.label}`;

    const columns: DataTableColumn<TrialBalanceAccount>[] = [
        {
            key: 'account',
            header: 'Account',
            width: 'minmax(240px,2fr)',
            className: 'cf-stack',
            cell: (a) => (
                <>
                    {a.id === null ? (
                        <span className="t-label-strong">{a.name}</span>
                    ) : (
                        <Link
                            className="t-label-strong sm-ledger-link"
                            href={
                                journalRoute({
                                    query: cleanQuery({
                                        ...query,
                                        account: a.id,
                                    }),
                                }).url
                            }
                        >
                            {a.name}
                        </Link>
                    )}
                    <span className="t-caption sm-code">{a.code}</span>
                </>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            width: '110px',
            skeleton: 'pill',
            cell: (a) => (
                <span className="cf-pill cf-pill--outline cf-pill--sm">
                    {TYPE_LABEL[a.type] ?? a.type}
                </span>
            ),
        },
        {
            key: 'opening',
            header: 'Opening',
            width: '150px',
            numeric: true,
            cell: (a) => balance(a.openingPaise),
        },
        {
            key: 'debit',
            header: 'Debit',
            width: '140px',
            numeric: true,
            cell: (a) => amount(a.debitPaise),
        },
        {
            key: 'credit',
            header: 'Credit',
            width: '140px',
            numeric: true,
            cell: (a) => amount(a.creditPaise),
        },
        {
            key: 'closing',
            header: 'Closing',
            width: '150px',
            numeric: true,
            cell: (a) => balance(a.closingPaise),
        },
    ];

    const pair = (dr: number, cr: number) => (
        <span className="cf-stack sm-total">
            <span>{formatInr(dr)} Dr</span>
            <span>{formatInr(cr)} Cr</span>
        </span>
    );

    return (
        <>
            <Head title="Trial balance" />
            <PageHeader
                kicker="Statements"
                title="Trial balance"
                crumbs={[{ title: 'Statements' }, { title: 'Trial balance' }]}
                actions={
                    <PeriodControls
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
            <StatementTabs current="trial_balance" query={query} />

            <BalanceBanner
                balanced={balanced}
                driftPaise={trial.driftPaise}
                totalText={
                    <>
                        Closing debits and credits both total{' '}
                        <span className="t-money">
                            {formatInr(trial.closingDebitPaise)}
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
                    minWidth={940}
                    totals={{
                        account: 'Total',
                        opening: pair(
                            trial.openingDebitPaise,
                            trial.openingCreditPaise,
                        ),
                        debit: formatInr(trial.debitPaise),
                        credit: formatInr(trial.creditPaise),
                        closing: pair(
                            trial.closingDebitPaise,
                            trial.closingCreditPaise,
                        ),
                    }}
                    empty={{ title: 'No entries in this period' }}
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
                    Opening + debits − credits = closing on every line.
                    Balance-sheet accounts run from the beginning; income and
                    expenses from the start of {date.fy.label}, before closing.
                    Open an account to see its ledger.
                </p>
            </section>
        </>
    );
}

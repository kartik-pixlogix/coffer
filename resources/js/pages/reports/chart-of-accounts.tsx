import { Head, Link, router } from '@inertiajs/react';
import { StatementTabs } from '@/components/reports/statement-tabs';
import { PageHeader } from '@/components/shell/page-header';
import { Select } from '@/components/ui/select';
import { TableWrap } from '@/components/ui/table-wrap';
import { formatDate, formatInr } from '@/lib/format';
import { journal as journalRoute } from '@/routes/reports';
import { scheduleLine as scheduleLineRoute } from '@/routes/reports/chart-of-accounts';

type Account = {
    id: number;
    code: string;
    name: string;
    role: string;
    /** Debit positive. */
    balancePaise: number;
    categoryId: number | null;
    line: string | null;
};

type Group = {
    key: string;
    title: string;
    heading: string;
    note: string | null;
    accounts: Account[];
};

type Props = {
    asOf: string;
    fyLabel: string;
    groups: Group[];
    lines: {
        income: { value: string; label: string }[];
        expense: { value: string; label: string }[];
    };
    canEdit: boolean;
};

const balance = (paise: number) =>
    paise === 0
        ? '—'
        : `${formatInr(Math.abs(paise))} ${paise > 0 ? 'Dr' : 'Cr'}`;

/**
 * Chart of accounts (D158): every account under its Schedule III heading, with its code,
 * what it is for and its balance today; each opens its ledger in the Journal. Admins pick
 * the Schedule III line each income and expense category reports under.
 */
export default function ChartOfAccounts({
    asOf,
    fyLabel,
    groups,
    lines,
    canEdit,
}: Props) {
    const setLine = (account: Account, line: string) => {
        if (account.categoryId === null) {
            return;
        }

        router.patch(
            scheduleLineRoute(account.categoryId).url,
            { schedule_line: line },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Chart of accounts" />
            <PageHeader
                kicker="Statements"
                title="Chart of accounts"
                crumbs={[
                    { title: 'Statements' },
                    { title: 'Chart of accounts' },
                ]}
            />
            <StatementTabs current="chart_of_accounts" />

            {groups.map((group) => (
                <section
                    key={group.key}
                    className="cf-card sm-card"
                    aria-labelledby={`coa-${group.key}`}
                >
                    <div className="cf-kv">
                        <h2
                            id={`coa-${group.key}`}
                            className="cf-dot-title t-title"
                        >
                            {group.title}
                        </h2>
                        <span className="t-caption t-muted">
                            {group.heading}
                        </span>
                    </div>
                    {group.note && (
                        <p className="t-caption t-muted m-0">{group.note}</p>
                    )}
                    <TableWrap label={group.title}>
                        <table className="s3-table coa-table">
                            <thead>
                                <tr>
                                    <th scope="col">Code</th>
                                    <th scope="col">Account</th>
                                    {group.accounts.some(
                                        (a) => a.categoryId !== null,
                                    ) && <th scope="col">Schedule III line</th>}
                                    <th scope="col" className="s3-num">
                                        Balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {group.accounts.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="t-muted">
                                            No accounts yet.
                                        </td>
                                    </tr>
                                )}
                                {group.accounts.map((a) => (
                                    <tr key={a.id}>
                                        <td className="sm-code t-money-sm">
                                            {a.code}
                                        </td>
                                        <td>
                                            <Link
                                                className="t-label-strong sm-ledger-link"
                                                href={
                                                    journalRoute({
                                                        query: {
                                                            account: a.id,
                                                        },
                                                    }).url
                                                }
                                            >
                                                {a.name}
                                            </Link>
                                            <span className="t-caption t-muted">
                                                {' '}
                                                · {a.role}
                                            </span>
                                        </td>
                                        {group.accounts.some(
                                            (x) => x.categoryId !== null,
                                        ) && (
                                            <td>
                                                {a.categoryId !== null &&
                                                a.line !== null ? (
                                                    canEdit ? (
                                                        <Select
                                                            pill
                                                            aria-label={`Schedule III line for ${a.name}`}
                                                            value={a.line}
                                                            onChange={(e) =>
                                                                setLine(
                                                                    a,
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            options={
                                                                a.role ===
                                                                'Income'
                                                                    ? lines.income
                                                                    : lines.expense
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="t-body">
                                                            {[
                                                                ...lines.income,
                                                                ...lines.expense,
                                                            ].find(
                                                                (l) =>
                                                                    l.value ===
                                                                    a.line,
                                                            )?.label ?? a.line}
                                                        </span>
                                                    )
                                                ) : null}
                                            </td>
                                        )}
                                        <td className="s3-num t-money-sm">
                                            {balance(a.balancePaise)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableWrap>
                </section>
            ))}

            <p className="t-caption t-muted m-0">
                Balances as of {formatDate(asOf)}; income and expenses for{' '}
                {fyLabel} so far. Codes stay the same once given, so exports and
                notes keep pointing at the same accounts.
            </p>
        </>
    );
}

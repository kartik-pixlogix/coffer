import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { cleanQuery } from '@/components/finance/fy-menu';
import { PeriodControls } from '@/components/reports/statement-parts';
import { PageHeader } from '@/components/shell/page-header';
import { Pagination } from '@/components/ui/pagination';
import { SearchField } from '@/components/ui/search-field';
import { Select } from '@/components/ui/select';
import { TableWrap } from '@/components/ui/table-wrap';
import { formatDate, formatIndianNumber, formatInr } from '@/lib/format';
import { journal as journalRoute } from '@/routes/reports';
import { exportMethod as exportJournal } from '@/routes/reports/journal';
import type {
    JournalEntryRow,
    StatementDateProps,
    StatementQuery,
} from '@/types/reports';

type Filters = {
    type: string | null;
    account: number | null;
    q: string;
    page: number;
};

type Props = {
    date: StatementDateProps;
    query: StatementQuery;
    filters: Filters;
    account: { id: number; code: string; name: string } | null;
    accounts: { value: string; label: string }[];
    types: { value: string; label: string }[];
    entries: JournalEntryRow[];
    total: number;
    page: number;
    pages: number;
    perPage: number;
};

const amount = (paise: number) => (paise === 0 ? '' : formatInr(paise));

/**
 * The Journal, or day book (D154): every voucher in the period with its debit and credit
 * lines, newest first. Filter by type or account (an account's ledger, opened from the
 * trial balance and the chart of accounts) or search voucher numbers and memos.
 */
export default function Journal({
    date,
    query,
    filters,
    account,
    accounts,
    types,
    entries,
    total,
    page,
    pages,
    perPage,
}: Props) {
    const go = (next: Partial<Filters>) => {
        const f = { ...filters, page: 1, ...next };

        router.get(
            journalRoute().url,
            cleanQuery({
                ...query,
                type: f.type,
                account: f.account,
                q: f.q,
                page: f.page > 1 ? f.page : null,
            }),
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };
    const [q, setQ] = useState(filters.q);
    const first = useRef(true);

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

    const start = (page - 1) * perPage + 1;
    const end = Math.min(page * perPage, total);

    return (
        <>
            <Head title="Journal" />
            <PageHeader
                kicker="Statements"
                title={account ? `Ledger · ${account.name}` : 'Journal'}
                crumbs={[{ title: 'Statements' }, { title: 'Journal' }]}
                actions={
                    <PeriodControls
                        date={date}
                        url={journalRoute().url}
                        formats={['pdf', 'csv']}
                        exportUrl={(format) =>
                            exportJournal({
                                query: cleanQuery({
                                    ...query,
                                    type: filters.type,
                                    account: filters.account,
                                    q: filters.q,
                                    format,
                                }),
                            }).url
                        }
                    />
                }
            />

            <section
                className="cf-card cf-stack jn-card"
                aria-labelledby="jn-h"
            >
                <div className="cf-kv jn-head">
                    <h2 id="jn-h" className="cf-dot-title t-title">
                        Vouchers
                    </h2>
                    <div className="cf-row jn-filters">
                        <SearchField
                            aria-label="Search vouchers and memos"
                            placeholder="Voucher or memo"
                            value={q}
                            onValueChange={setQ}
                            wrapClassName="jn-search"
                        />
                        <Select
                            pill
                            aria-label="Type"
                            value={filters.type ?? ''}
                            onChange={(e) =>
                                go({
                                    type:
                                        e.target.value === ''
                                            ? null
                                            : e.target.value,
                                })
                            }
                            options={[
                                { value: '', label: 'All types' },
                                ...types,
                            ]}
                        />
                        <Select
                            pill
                            aria-label="Account"
                            value={
                                filters.account === null
                                    ? ''
                                    : String(filters.account)
                            }
                            onChange={(e) =>
                                go({
                                    account:
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                })
                            }
                            options={[
                                { value: '', label: 'All accounts' },
                                ...accounts,
                            ]}
                        />
                    </div>
                </div>

                <TableWrap label="Journal">
                    <div
                        className="cf-table jn-table"
                        role="table"
                        aria-label="Journal"
                    >
                        <div className="cf-tr cf-thead jn-cols" role="row">
                            <span role="columnheader">Voucher</span>
                            <span role="columnheader">Date</span>
                            <span role="columnheader">Account</span>
                            <span role="columnheader" className="cf-num">
                                Debit
                            </span>
                            <span role="columnheader" className="cf-num">
                                Credit
                            </span>
                        </div>
                        {entries.map((e) => (
                            <div
                                key={e.id}
                                role="rowgroup"
                                className="jn-entry"
                            >
                                <div
                                    className="cf-tr jn-cols jn-entry-head"
                                    role="row"
                                >
                                    <span
                                        role="cell"
                                        className="t-money-sm t-label-strong"
                                    >
                                        {e.voucher ?? '—'}
                                    </span>
                                    <span role="cell" className="t-body">
                                        {formatDate(e.date)}
                                    </span>
                                    <span role="cell" className="cf-stack">
                                        <span className="t-label-strong">
                                            {e.typeLabel}
                                        </span>
                                        <span className="t-caption t-muted">
                                            {[
                                                e.memo,
                                                e.reverses
                                                    ? `Reverses ${e.reverses}`
                                                    : null,
                                                e.reversedBy
                                                    ? `Reversed by ${e.reversedBy}`
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                    <span role="cell" />
                                    <span role="cell" />
                                </div>
                                {e.lines.map((l) => (
                                    <div
                                        key={l.id}
                                        className={
                                            account?.id === l.accountId
                                                ? 'cf-tr jn-cols jn-line is-mine'
                                                : 'cf-tr jn-cols jn-line'
                                        }
                                        role="row"
                                    >
                                        <span role="cell" />
                                        <span role="cell" />
                                        <span
                                            role="cell"
                                            className={
                                                l.creditPaise > 0
                                                    ? 'jn-acct is-credit'
                                                    : 'jn-acct'
                                            }
                                        >
                                            <span className="t-caption sm-code">
                                                {l.code}
                                            </span>{' '}
                                            <span className="t-body">
                                                {l.name}
                                            </span>
                                        </span>
                                        <span
                                            role="cell"
                                            className="cf-num t-money-sm"
                                        >
                                            {amount(l.debitPaise)}
                                        </span>
                                        <span
                                            role="cell"
                                            className="cf-num t-money-sm"
                                        >
                                            {amount(l.creditPaise)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        ))}
                        {entries.length === 0 && (
                            <div className="cf-empty-row t-body t-muted">
                                No vouchers match these filters.
                            </div>
                        )}
                    </div>
                </TableWrap>
                <Pagination
                    page={page}
                    pageCount={pages}
                    onPageChange={(p) => go({ page: p })}
                    summary={
                        total === 0
                            ? 'No vouchers'
                            : `Showing ${start}–${end} of ${formatIndianNumber(total)} vouchers · numbered without gaps per type and year`
                    }
                />
            </section>
        </>
    );
}

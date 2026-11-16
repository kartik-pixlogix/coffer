import { Head } from '@inertiajs/react';
import { cleanQuery } from '@/components/finance/fy-menu';
import {
    BalanceBanner,
    StatementControls,
} from '@/components/reports/statement-parts';
import { PageHeader } from '@/components/shell/page-header';
import { formatDate, formatInr } from '@/lib/format';
import { balanceSheet as balanceSheetRoute } from '@/routes/reports';
import { exportMethod as exportBalanceSheet } from '@/routes/reports/balance-sheet';
import type {
    BalanceSheetLine,
    DriftIssue,
    StatementDateProps,
} from '@/types/reports';

type Props = {
    company: { name: string };
    date: StatementDateProps;
    query: { fy: number; asof: string };
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
};

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
                                query: { ...cleanQuery(query), format },
                            }).url
                        }
                    />
                }
            />

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
                                <span className="t-money">{formatInr(0)}</span>
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
                    <div className="sm-bs-col" role="group" aria-label="Assets">
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
        </>
    );
}

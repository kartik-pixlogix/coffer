import { Fragment } from 'react';
import { formatInr } from '@/lib/format';

export type BalanceTerms = {
    openingPaise: number;
    incomePaise: number;
    expensesPaise: number;
    /** Contributions, drawings, loans and repayments. */
    capitalPaise: number;
    /** Owed to partners and members for personal cards, less what partners hold. */
    personalPaise: number;
    /** Anything else that moved cash (zero in sound books). */
    otherPaise?: number;
};

/**
 * The formula strip under a balance (spec §4.3, D58): opening + income − expenses, then
 * every other term the balance contains, each only when it isn't zero, so the strip
 * always adds up to the figure above it. Shared by Dashboard and Settlements.
 */
export function BalanceStrip({
    terms,
    className = 'st-strip',
}: {
    terms: BalanceTerms;
    className?: string;
}) {
    const extra: { key: string; paise: number; label: string }[] = [
        { key: 'capital', paise: terms.capitalPaise, label: 'capital & loans' },
        {
            key: 'personal',
            paise: terms.personalPaise,
            label:
                terms.personalPaise >= 0
                    ? 'owed for personal cards'
                    : 'held by partners',
        },
        {
            key: 'other',
            paise: terms.otherPaise ?? 0,
            label: 'other movements',
        },
    ].filter((t) => t.paise !== 0);

    const sum =
        terms.openingPaise +
        terms.incomePaise -
        terms.expensesPaise +
        extra.reduce((s, t) => s + t.paise, 0);

    return (
        <div className={`t-body ${className}`}>
            <span className="cf-sr">{`Balance ${formatInr(sum)}: ${formatInr(terms.openingPaise)} opening, plus ${formatInr(terms.incomePaise)} income, minus ${formatInr(terms.expensesPaise)} expenses${extra.map((t) => `, ${t.paise < 0 ? 'minus' : 'plus'} ${formatInr(Math.abs(t.paise))} ${t.label}`).join('')}`}</span>
            <span className="contents" aria-hidden="true">
                <span>
                    <span className="t-money">
                        {formatInr(terms.openingPaise)}
                    </span>{' '}
                    opening
                </span>
                <span aria-hidden="true">+</span>
                <span>
                    <span className="t-money">
                        {formatInr(terms.incomePaise)}
                    </span>{' '}
                    income
                </span>
                <span aria-hidden="true">−</span>
                <span>
                    <span className="t-money">
                        {formatInr(terms.expensesPaise)}
                    </span>{' '}
                    expenses
                </span>
                {extra.map((t) => (
                    <Fragment key={t.key}>
                        <span aria-hidden="true">
                            {t.paise < 0 ? '−' : '+'}
                        </span>
                        <span>
                            <span className="t-money">
                                {formatInr(Math.abs(t.paise))}
                            </span>{' '}
                            {t.label}
                        </span>
                    </Fragment>
                ))}
            </span>
        </div>
    );
}

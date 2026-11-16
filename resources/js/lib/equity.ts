/*
 * Equity in basis points of a percent (10000 = 100.00%), exact to two decimals.
 * Mirrors app/Support/Equity.php: the wizard and partner settings check live here,
 * and the server checks again (spec §3.1, §16).
 */

export const WHOLE = 10000;

/** "60", "60.5", "60.00 %" → 6000 / 6050 / 6000; null when not a 0–100 number with ≤ 2 decimals. */
export function parseEquity(value: string): number | null {
    const clean = value.replace(/[^0-9.]/g, '');

    if (clean === '' || !/^\d{1,3}(\.\d{0,2})?$/.test(clean)) {
        return null;
    }

    const [whole, fraction = ''] = clean.split('.');
    const bps = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));

    return bps <= WHOLE ? bps : null;
}

/** 6000 → "60.00" */
export function formatEquity(bps: number): string {
    const sign = bps < 0 ? '−' : '';
    const abs = Math.abs(Math.round(bps));

    return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** 6000 → "60%", 3333 → "33.33%" (review lines, partner cards). */
export function formatEquityShort(bps: number): string {
    return `${formatEquity(bps)
        .replace(/\.00$/, '')
        .replace(/(\.\d)0$/, '$1')}%`;
}

/** Sum of the rows' shares; rows that don't parse count as 0. */
export function totalEquity(values: string[]): number {
    return values.reduce((sum, v) => sum + (parseEquity(v) ?? 0), 0);
}

/** Within ±0.005% of 100, i.e. exactly 10000 bps when every row parses. */
export function isWhole(values: string[]): boolean {
    return (
        values.length > 0 &&
        values.every((v) => parseEquity(v) !== null) &&
        totalEquity(values) === WHOLE
    );
}

/** "Even split": equal shares with the rounding remainder on the first row. */
export function evenSplit(count: number): number[] {
    if (count < 1) {
        return [];
    }

    const each = Math.floor(WHOLE / count);
    const shares = Array<number>(count).fill(each);
    shares[0] += WHOLE - each * count;

    return shares;
}

/**
 * The fix in words. Single: "Equity adds up to 95.00%. Add 5.00% so it totals 100%."
 * JV side: "Vertex Labs partners add up to 90.00%. Add 10.00% so this side totals 100%."
 */
export function gapMessage(
    total: number,
    subject = 'Equity',
    target = 'it totals 100%',
): string {
    const gap = WHOLE - total;
    const verb = gap > 0 ? 'Add' : 'Remove';
    const adds = subject === 'Equity' ? 'adds' : 'add';

    return `${subject} ${adds} up to ${formatEquity(total)}%. ${verb} ${formatEquity(Math.abs(gap))}% so ${target}.`;
}

/** JV overall equity in bps: side share × share within the side (may be fractional). */
export function overallEquity(sideBps: number, withinBps: number): number {
    return (sideBps * withinBps) / WHOLE;
}

import { formatDate } from '@/lib/format';

/** A voucher number (D154) in the mono caption style, or nothing before it posts. */
export function VoucherNo({ value }: { value: string | null }) {
    return value === null ? null : (
        <span className="t-caption sm-code">{value}</span>
    );
}

/** A date with its voucher underneath, for list rows. */
export function DateWithVoucher({
    date,
    voucher,
}: {
    date: string;
    voucher: string | null;
}) {
    return (
        <span className="cf-stack">
            <span className="t-body t-muted">{formatDate(date)}</span>
            <VoucherNo value={voucher} />
        </span>
    );
}

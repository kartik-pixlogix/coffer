import { AlertTriangle, Check, ChevronDown, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { formatEquity, parseEquity } from '@/lib/equity';
import { cn } from '@/lib/utils';

/** "Total: 100.00%" pill in a card head: positive when whole, negative otherwise. */
export function TotalPill({
    ok,
    label,
    total,
}: {
    ok: boolean;
    label: string;
    total: number;
}) {
    return (
        <span
            role="status"
            className={cn(
                'cf-pill wiz-total',
                ok ? 'cf-pill--pos' : 'cf-pill--neg',
            )}
        >
            <Icon icon={ok ? Check : AlertTriangle} size={14} strokeWidth={2} />
            {label}{' '}
            <span className="t-money-sm wiz-total-figure">
                {formatEquity(total)}%
            </span>
        </span>
    );
}

/** A % cell (`cf-inwrap--sm`, right-aligned mono, "%" after); tidies to 2 decimals on blur. */
export function PercentInput({
    value,
    onChange,
    invalid,
    'aria-label': ariaLabel,
}: {
    value: string;
    onChange: (value: string) => void;
    invalid: boolean;
    'aria-label': string;
}) {
    return (
        <span className={cn('cf-inwrap cf-inwrap--sm', invalid && 'is-error')}>
            <input
                type="text"
                inputMode="decimal"
                value={value}
                onChange={(e) =>
                    onChange(e.target.value.replace(/[^0-9.]/g, ''))
                }
                onBlur={() => {
                    const bps = parseEquity(value);

                    if (bps !== null) {
                        onChange(formatEquity(bps));
                    }
                }}
                aria-label={ariaLabel}
                aria-invalid={invalid || undefined}
                className="wiz-num"
            />
            <span className="cf-prefix wiz-suffix" aria-hidden="true">
                %
            </span>
        </span>
    );
}

/** A compact select in a row (`cf-select`, 40px). */
export function RowSelect<T extends string>({
    value,
    options,
    onChange,
    'aria-label': ariaLabel,
}: {
    value: T;
    options: { value: T; label: string }[];
    onChange: (value: T) => void;
    'aria-label': string;
}) {
    return (
        <span className="cf-select">
            <select
                value={value}
                onChange={(e) => onChange(e.target.value as T)}
                aria-label={ariaLabel}
                className="wiz-select"
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            <Icon icon={ChevronDown} size={16} />
        </span>
    );
}

/** Bare trash button at the end of a row. */
export function RemoveRow({
    label,
    disabled,
    onClick,
}: {
    label: string;
    disabled?: boolean;
    onClick: () => void;
}) {
    return (
        <IconButton
            icon={Trash2}
            variant="bare"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
        />
    );
}

/** The stacked equity bar in the aside (`role="img"` with the split in words). */
export function EquityBar({
    segments,
    label,
}: {
    segments: { key: string; bps: number; colour: number }[];
    label: string;
}) {
    return (
        <div className="wiz-bar" role="img" aria-label={label}>
            {segments.map((s) => (
                <span
                    key={s.key}
                    style={{
                        width: `${Math.max(0, s.bps) / 100}%`,
                        background: `var(--partner-${s.colour})`,
                    }}
                />
            ))}
        </div>
    );
}

/** Error line under a group (`cf-alert--neg cf-alert--slim`, role alert). */
export function GroupError({ children }: { children: ReactNode }) {
    return (
        <div className="cf-alert cf-alert--neg cf-alert--slim" role="alert">
            <div className="cf-alert-main" style={{ flexBasis: 260 }}>
                <span className="cf-alert-ic">
                    <Icon icon={AlertTriangle} size={16} />
                </span>
                <span className="t-body">{children}</span>
            </div>
        </div>
    );
}

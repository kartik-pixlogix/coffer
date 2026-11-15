import { X } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { formatIndianNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type FilterChipProps = {
    pressed: boolean;
    /** Live count shown after the label in `t-money-sm`. */
    count?: number;
    children: ReactNode;
} & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children' | 'aria-pressed'>;

/** One-tap filter (`cf-chip` + `aria-pressed`); the pressed chip is lime. */
export function FilterChip({
    pressed,
    count,
    className,
    children,
    type = 'button',
    ...rest
}: FilterChipProps) {
    return (
        <button
            type={type}
            className={cn('cf-chip', className)}
            aria-pressed={pressed}
            {...rest}
        >
            {children}
            {count !== undefined && (
                <span className="t-money-sm">{formatIndianNumber(count)}</span>
            )}
        </button>
    );
}

export type FilterChipGroupProps = {
    'aria-label': string;
    children: ReactNode;
    className?: string;
};

/** Wrapping row of chips (`cf-row`, `role="group"`) as on the screens. */
export function FilterChipGroup({
    'aria-label': ariaLabel,
    children,
    className,
}: FilterChipGroupProps) {
    return (
        <div
            className={cn('cf-row', className)}
            style={{ flexWrap: 'wrap' }}
            role="group"
            aria-label={ariaLabel}
        >
            {children}
        </div>
    );
}

export type FilterChipOption<T extends string> = {
    value: T;
    label: ReactNode;
    count?: number;
    /** Disable a chip with no results. */
    disabled?: boolean;
    /** Doc-only forced state (`is-hover`, `is-focus`). */
    className?: string;
};

export type FilterChipsProps<T extends string> = {
    'aria-label': string;
    options: FilterChipOption<T>[];
    value: T;
    onValueChange: (value: T) => void;
    className?: string;
};

/** Single-choice status filters above a table: All / Pending / Approved / Rejected. */
export function FilterChips<T extends string>({
    'aria-label': ariaLabel,
    options,
    value,
    onValueChange,
    className,
}: FilterChipsProps<T>) {
    return (
        <FilterChipGroup aria-label={ariaLabel} className={className}>
            {options.map((option) => (
                <FilterChip
                    key={option.value}
                    pressed={option.value === value}
                    count={option.count}
                    disabled={option.disabled}
                    className={option.className}
                    onClick={() => onValueChange(option.value)}
                >
                    {option.label}
                </FilterChip>
            ))}
        </FilterChipGroup>
    );
}

export type AppliedFilterProps = {
    /** Filter name, e.g. "Paid by"; also names the remove button. */
    name: string;
    /** Applied value, e.g. "Kabir Shah". */
    value: ReactNode;
    onRemove: () => void;
    className?: string;
};

/** An applied filter token: "Paid by: Kabir Shah" with a remove (×) button. */
export function AppliedFilter({
    name,
    value,
    onRemove,
    className,
}: AppliedFilterProps) {
    return (
        <span
            className={cn('cf-chip', className)}
            style={{ color: 'var(--ink)' }}
        >
            {name}: {value}
            <button
                type="button"
                className="cf-chip-x"
                aria-label={`Remove ${name} filter`}
                onClick={onRemove}
            >
                <Icon icon={X} size={12} strokeWidth={2} />
            </button>
        </span>
    );
}

/** "Clear all" link after the applied filters. */
export function ClearFilters({
    onClick,
    children = 'Clear all',
}: {
    onClick: () => void;
    children?: ReactNode;
}) {
    return (
        <button type="button" className="cf-link" onClick={onClick}>
            {children}
        </button>
    );
}

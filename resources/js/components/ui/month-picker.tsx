import * as Popover from '@radix-ui/react-popover';
import {
    Calendar as CalendarIcon,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { buttonClass } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import {
    addMonthsToValue,
    buildFiscalYearMonths,
    fiscalYearOf,
    fiscalYearTitle,
    monthValueToDate,
    toMonthValue,
} from '@/components/ui/month-picker.utils';
import { formatMonth } from '@/lib/format';
import { cn } from '@/lib/utils';

const thisMonth = (): string => {
    const now = new Date();

    return toMonthValue(now.getFullYear(), now.getMonth());
};

const MONTH_KEYS: Record<string, number> = {
    ArrowLeft: -1,
    ArrowRight: 1,
    ArrowUp: -3,
    ArrowDown: 3,
    PageUp: -12,
    PageDown: 12,
};

export type MonthGridProps = {
    /** "YYYY-MM" */
    selected?: string | null;
    onSelect?: (value: string) => void;
    /** This month, "YYYY-MM"; defaults to the real one. */
    current?: string;
    /** Earliest and latest months that can hold entries (inclusive), "YYYY-MM". */
    min?: string;
    max?: string;
    /** First month of the financial year, 1–12 (April = 4). */
    startMonth?: number;
    autoFocus?: boolean;
    className?: string;
};

/**
 * The twelve months of one financial year (`cf-cal-head` + `cf-monthgrid` of
 * `cf-month` buttons); the year step moves by financial year. Arrows move by month
 * (up/down by a row), PageUp/PageDown by year, Home/End to April/March.
 */
export function MonthGrid({
    selected = null,
    onSelect,
    current = thisMonth(),
    min,
    max,
    startMonth = 4,
    autoFocus = false,
    className,
}: MonthGridProps) {
    const initial = selected ?? current;
    const [focused, setFocused] = useState(initial);
    const [lastSelected, setLastSelected] = useState(selected);

    // A new selection from outside: show its financial year.
    if (selected !== lastSelected) {
        setLastSelected(selected);

        if (selected !== null) {
            setFocused(selected);
        }
    }
    const [focusRequest, setFocusRequest] = useState<{ value: string } | null>(
        autoFocus ? { value: initial } : null,
    );
    const gridRef = useRef<HTMLDivElement | null>(null);
    const fyStartYear = fiscalYearOf(focused, startMonth);
    const months = buildFiscalYearMonths({
        fyStartYear,
        startMonth,
        selected,
        current,
        min,
        max,
    });
    const prevDisabled =
        min !== undefined && addMonthsToValue(months[0].value, -1) < min;
    const nextDisabled =
        max !== undefined && addMonthsToValue(months[11].value, 1) > max;

    useEffect(() => {
        if (focusRequest) {
            gridRef.current
                ?.querySelector<HTMLButtonElement>(
                    `[data-month="${focusRequest.value}"]`,
                )
                ?.focus();
        }
    }, [focusRequest]);

    const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        let next: string | null = null;

        if (event.key in MONTH_KEYS) {
            next = addMonthsToValue(focused, MONTH_KEYS[event.key]);
        } else if (event.key === 'Home') {
            next = months[0].value;
        } else if (event.key === 'End') {
            next = months[11].value;
        }

        if (next === null) {
            return;
        }

        event.preventDefault();
        setFocused(next);
        setFocusRequest({ value: next });
    };

    return (
        <div className={className}>
            <div className="cf-cal-head">
                <IconButton
                    icon={ChevronLeft}
                    size="sm"
                    iconSize={14}
                    aria-label="Previous year"
                    disabled={prevDisabled}
                    onClick={() => setFocused(addMonthsToValue(focused, -12))}
                />
                <span className="t-title" aria-live="polite">
                    {fiscalYearTitle(fyStartYear, startMonth)}
                </span>
                <IconButton
                    icon={ChevronRight}
                    size="sm"
                    iconSize={14}
                    aria-label="Next year"
                    disabled={nextDisabled}
                    onClick={() => setFocused(addMonthsToValue(focused, 12))}
                />
            </div>
            <div
                ref={gridRef}
                className="cf-monthgrid"
                onKeyDown={handleKeyDown}
            >
                {months.map((month) => (
                    <button
                        key={month.value}
                        type="button"
                        data-month={month.value}
                        className={cn(
                            'cf-month',
                            month.current && 'is-current',
                        )}
                        aria-pressed={month.selected || undefined}
                        aria-current={month.current ? 'date' : undefined}
                        aria-label={formatMonth(monthValueToDate(month.value))}
                        tabIndex={month.value === focused ? 0 : -1}
                        disabled={month.disabled}
                        onClick={() => {
                            setFocused(month.value);
                            onSelect?.(month.value);
                        }}
                    >
                        {month.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

export type MonthPickerProps = Omit<
    MonthGridProps,
    'selected' | 'onSelect' | 'autoFocus'
> & {
    /** "YYYY-MM" or null. Leave undefined for an uncontrolled picker. */
    value?: string | null;
    defaultValue?: string | null;
    onValueChange?: (value: string) => void;
    /** Trigger text with no month chosen. */
    placeholder?: string;
    /** Names the trigger when the month alone isn't enough: "Salary month". */
    'aria-label'?: string;
    disabled?: boolean;
    /** Hidden input name carrying "YYYY-MM", for plain form posts. */
    name?: string;
};

/**
 * For records that belong to a whole month (monthly hours, salary runs, the Expenses
 * filter): a secondary button showing "Oct 2026" that opens the financial-year grid.
 */
export function MonthPicker({
    value,
    defaultValue = null,
    onValueChange,
    placeholder = 'Choose month',
    'aria-label': ariaLabel,
    disabled = false,
    name,
    className,
    ...grid
}: MonthPickerProps) {
    const [uncontrolled, setUncontrolled] = useState(defaultValue);
    const [open, setOpen] = useState(false);
    const selected = value !== undefined ? value : uncontrolled;
    const text = selected
        ? formatMonth(monthValueToDate(selected))
        : placeholder;

    return (
        <Popover.Root open={open} onOpenChange={setOpen}>
            <Popover.Trigger
                className={buttonClass({
                    variant: 'secondary',
                    className: cn('cf-month-trigger', className),
                })}
                aria-label={ariaLabel ? `${ariaLabel}: ${text}` : undefined}
                disabled={disabled}
            >
                <Icon icon={CalendarIcon} size={16} />
                {text}
                <Icon icon={ChevronDown} size={14} />
            </Popover.Trigger>
            {name !== undefined && (
                <input type="hidden" name={name} value={selected ?? ''} />
            )}
            <Popover.Portal>
                <Popover.Content
                    className="cf-pop z-50 animate-[cfFade_var(--duration-panel)_var(--ease-out)]"
                    collisionPadding={16}
                    aria-label="Choose a month"
                    style={{ width: 280, padding: 14 }}
                    side="bottom"
                    align="start"
                    sideOffset={8}
                    onOpenAutoFocus={(event) => event.preventDefault()}
                >
                    <MonthGrid
                        {...grid}
                        selected={selected}
                        autoFocus
                        onSelect={(month) => {
                            setUncontrolled(month);
                            onValueChange?.(month);
                            setOpen(false);
                        }}
                    />
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}

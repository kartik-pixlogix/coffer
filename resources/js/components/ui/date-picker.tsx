import * as Popover from '@radix-ui/react-popover';
import {
    Calendar as CalendarIcon,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import {
    WEEKDAYS,
    addMonths,
    buildCalendarGrid,
    isDateAllowed,
    monthTitle,
    moveFocus,
    parseRangeText,
} from '@/components/ui/date-picker.utils';
import type { DateRange, DateRules } from '@/components/ui/date-picker.utils';
import { Field, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { IconButton } from '@/components/ui/icon-button';
import { formatDate, parseDateLenient, toDate, toIsoDate } from '@/lib/format';
import { cn } from '@/lib/utils';

const todayIso = (): string => toIsoDate(new Date());

const WEEKDAY_NAMES = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

/** "Thursday, 22 Oct 2026" for a day button's accessible name. */
const dayLabel = (iso: string): string =>
    `${WEEKDAY_NAMES[(toDate(iso).getDay() + 6) % 7]}, ${formatDate(iso)}`;

/* ----------------------------------------------------------------- calendar */

export type CalendarProps = DateRules & {
    /** Single selection. */
    selected?: string | null;
    /** Range selection (ends get aria-pressed, days between `.is-range`). */
    range?: DateRange;
    onSelect?: (iso: string) => void;
    /** "YYYY-MM-DD"; defaults to the real today. */
    today?: string;
    /** The date that has focus first (and so the month shown). Defaults to the selection or today. */
    defaultFocus?: string;
    /** Move focus to that day on mount (inside a popover). */
    autoFocus?: boolean;
    className?: string;
};

/**
 * Month grid (`cf-cal-head` + 7-column `cf-cal-grid` of `cf-day` buttons), weeks start
 * on Monday. Arrows move by day/week, PageUp/PageDown by month, Home/End to the ends
 * of the week, Enter picks. Use inside DatePicker, or inline.
 */
export function Calendar({
    selected = null,
    range,
    onSelect,
    today = todayIso(),
    defaultFocus,
    autoFocus = false,
    className,
    ...rules
}: CalendarProps) {
    const initial = defaultFocus ?? range?.start ?? selected ?? today;
    const [focused, setFocused] = useState(initial);
    const [lastSelected, setLastSelected] = useState(selected);

    // A new selection from outside (typed into the field): show its month.
    if (selected !== lastSelected) {
        setLastSelected(selected);

        if (selected !== null) {
            setFocused(selected);
        }
    }
    const [focusRequest, setFocusRequest] = useState<{ iso: string } | null>(
        autoFocus ? { iso: initial } : null,
    );
    const gridRef = useRef<HTMLDivElement | null>(null);
    const view = toDate(focused);
    const year = view.getFullYear();
    const month = view.getMonth();
    const days = buildCalendarGrid({
        year,
        month,
        today,
        selected,
        range,
        ...rules,
    });

    useEffect(() => {
        if (focusRequest) {
            gridRef.current
                ?.querySelector<HTMLButtonElement>(
                    `[data-iso="${focusRequest.iso}"]`,
                )
                ?.focus();
        }
    }, [focusRequest]);

    const lastOfPrevious = toIsoDate(new Date(year, month, 0));
    const firstOfNext = toIsoDate(new Date(year, month + 1, 1));
    const prevDisabled = rules.min !== undefined && lastOfPrevious < rules.min;
    const nextDisabled = rules.max !== undefined && firstOfNext > rules.max;

    const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const next = moveFocus(focused, event.key, event.shiftKey);

        if (next === null) {
            return;
        }

        event.preventDefault();
        setFocused(next);
        setFocusRequest({ iso: next });
    };

    return (
        <div className={className}>
            <div className="cf-cal-head">
                <IconButton
                    icon={ChevronLeft}
                    size="sm"
                    iconSize={14}
                    aria-label="Previous month"
                    disabled={prevDisabled}
                    onClick={() => setFocused(addMonths(focused, -1))}
                />
                <span className="t-title" aria-live="polite">
                    {monthTitle(year, month)}
                </span>
                <IconButton
                    icon={ChevronRight}
                    size="sm"
                    iconSize={14}
                    aria-label="Next month"
                    disabled={nextDisabled}
                    onClick={() => setFocused(addMonths(focused, 1))}
                />
            </div>
            <div
                ref={gridRef}
                className="cf-cal-grid"
                onKeyDown={handleKeyDown}
            >
                {WEEKDAYS.map((weekday) => (
                    <span
                        key={weekday}
                        className="cf-cal-dow"
                        aria-hidden="true"
                    >
                        {weekday}
                    </span>
                ))}
                {days.map((day) => (
                    <button
                        key={day.iso}
                        type="button"
                        data-iso={day.iso}
                        className={cn(
                            'cf-day',
                            !day.inMonth && 'is-muted',
                            day.isToday && 'is-today',
                            day.inRange && 'is-range',
                        )}
                        aria-pressed={day.isSelected || undefined}
                        aria-current={day.isToday ? 'date' : undefined}
                        aria-label={dayLabel(day.iso)}
                        tabIndex={day.iso === focused ? 0 : -1}
                        disabled={day.disabled}
                        onClick={() => {
                            setFocused(day.iso);
                            onSelect?.(day.iso);
                        }}
                    >
                        {day.day}
                    </button>
                ))}
            </div>
        </div>
    );
}

/* --------------------------------------------------------------- the fields */

type DateFieldBaseProps = FieldMessageProps &
    DateRules & {
        label?: ReactNode;
        'aria-label'?: string;
        placeholder?: string;
        /** "YYYY-MM-DD"; defaults to the real today. */
        today?: string;
        /** Shown when the typed text isn't a date. */
        invalidText?: ReactNode;
        /** Shown when the typed date breaks min/max/disabled rules. */
        unavailableText?: ReactNode;
        disabled?: boolean;
        id?: string;
        /** Class for the `cf-inwrap` (doc states: is-focus). */
        wrapClassName?: string;
        fieldClassName?: string;
    };

type DateFieldShellProps = Omit<
    DateFieldBaseProps,
    keyof DateRules | 'today' | 'invalidText' | 'unavailableText'
> & {
    display: string;
    /** Commit typed text; return an error to show, or null. */
    commit: (text: string) => ReactNode | null;
    popoverLabel: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    calendar: ReactNode;
    hidden: ReactNode;
};

function DateFieldShell({
    label,
    'aria-label': ariaLabel,
    placeholder,
    disabled = false,
    help,
    error,
    id: idProp,
    wrapClassName,
    fieldClassName,
    display,
    commit,
    popoverLabel,
    open,
    onOpenChange,
    calendar,
    hidden,
}: DateFieldShellProps) {
    const [text, setText] = useState<string | null>(null);
    const [typedError, setTypedError] = useState<ReactNode | null>(null);
    const shownError = error ?? typedError ?? undefined;
    const ids = useFieldIds(idProp, { help, error: shownError });

    const commitText = () => {
        if (text === null) {
            return;
        }

        const problem = commit(text);
        setTypedError(problem);

        if (problem === null) {
            setText(null);
        }
    };

    return (
        <Field
            ids={ids}
            label={label}
            help={help}
            error={shownError}
            className={fieldClassName}
        >
            <Popover.Root
                open={open}
                onOpenChange={(next) => {
                    if (next) {
                        commitText();
                    }

                    onOpenChange(next);
                }}
            >
                <Popover.Anchor asChild>
                    <span
                        className={cn(
                            'cf-inwrap',
                            ids.invalid && 'is-error',
                            disabled && 'is-disabled',
                            wrapClassName,
                        )}
                    >
                        <input
                            id={ids.id}
                            type="text"
                            autoComplete="off"
                            aria-label={ariaLabel}
                            aria-haspopup="dialog"
                            aria-expanded={open}
                            aria-invalid={ids.invalid || undefined}
                            aria-describedby={ids.describedBy}
                            placeholder={placeholder}
                            disabled={disabled}
                            value={text ?? display}
                            onChange={(event) => {
                                setText(event.currentTarget.value);
                            }}
                            onBlur={commitText}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    commitText();
                                } else if (
                                    event.key === 'ArrowDown' &&
                                    event.altKey
                                ) {
                                    event.preventDefault();
                                    onOpenChange(true);
                                }
                            }}
                        />
                        <Popover.Trigger asChild>
                            <IconButton
                                icon={CalendarIcon}
                                size="sm"
                                variant="bare"
                                iconSize={18}
                                aria-label={popoverLabel}
                                disabled={disabled}
                            />
                        </Popover.Trigger>
                        {hidden}
                    </span>
                </Popover.Anchor>
                {/* Portalled with collision handling, so a scrolling dialog never clips
                    it: it flips above the field when there's no room below (X2, D123). */}
                <Popover.Portal>
                    <Popover.Content
                        className="cf-pop cf-cal z-50 animate-[cfFade_var(--duration-panel)_var(--ease-out)]"
                        collisionPadding={16}
                        aria-label={popoverLabel}
                        side="bottom"
                        align="start"
                        sideOffset={8}
                        onOpenAutoFocus={(event) => event.preventDefault()}
                    >
                        {calendar}
                    </Popover.Content>
                </Popover.Portal>
            </Popover.Root>
        </Field>
    );
}

const DEFAULT_INVALID = 'Enter a date like 22 Oct 2026.';
const DEFAULT_UNAVAILABLE = 'That date can’t be used. Pick another.';

export type DatePickerProps = DateFieldBaseProps & {
    /** "YYYY-MM-DD" or null. Leave undefined for an uncontrolled field. */
    value?: string | null;
    defaultValue?: string | null;
    onValueChange?: (iso: string | null) => void;
    /** Hidden input name carrying "YYYY-MM-DD", for plain form posts. */
    name?: string;
};

/**
 * Single date (expense, income, transfer): typed input parsed leniently (1-Apr-26,
 * 01/04/2026) and echoed as "22 Oct 2026", plus a calendar popover.
 */
export function DatePicker({
    value,
    defaultValue = null,
    onValueChange,
    name,
    min,
    max,
    disabledDates,
    isDateDisabled,
    today,
    invalidText = DEFAULT_INVALID,
    unavailableText = DEFAULT_UNAVAILABLE,
    placeholder = 'e.g. 22 Oct 2026',
    ...rest
}: DatePickerProps) {
    const rules: DateRules = { min, max, disabledDates, isDateDisabled };
    const [uncontrolled, setUncontrolled] = useState(defaultValue);
    const [open, setOpen] = useState(false);
    const selected = value !== undefined ? value : uncontrolled;

    const choose = (iso: string | null) => {
        setUncontrolled(iso);

        if (iso !== selected) {
            onValueChange?.(iso);
        }
    };

    return (
        <DateFieldShell
            {...rest}
            placeholder={placeholder}
            display={selected ? formatDate(selected) : ''}
            popoverLabel="Choose date"
            open={open}
            onOpenChange={setOpen}
            commit={(text) => {
                if (text.trim() === '') {
                    choose(null);

                    return null;
                }

                const iso = parseDateLenient(text);

                if (iso === null) {
                    return invalidText;
                }

                if (!isDateAllowed(iso, rules)) {
                    return unavailableText;
                }

                choose(iso);

                return null;
            }}
            calendar={
                <Calendar
                    {...rules}
                    selected={selected}
                    today={today}
                    autoFocus
                    onSelect={(iso) => {
                        choose(iso);
                        setOpen(false);
                    }}
                />
            }
            hidden={
                name !== undefined && (
                    <input type="hidden" name={name} value={selected ?? ''} />
                )
            }
        />
    );
}

export type DateRangePickerProps = DateFieldBaseProps & {
    value?: DateRange;
    defaultValue?: DateRange;
    /** Fires when both ends are chosen (or the field is cleared). */
    onValueChange?: (range: DateRange) => void;
    /** Hidden inputs `<name>[start]` and `<name>[end]`, for plain form posts. */
    name?: string;
};

const EMPTY_RANGE: DateRange = { start: null, end: null };

/** Date range (reports): "1 Apr 2026 – 31 Mar 2027", picked as two clicks on the calendar. */
export function DateRangePicker({
    value,
    defaultValue = EMPTY_RANGE,
    onValueChange,
    name,
    min,
    max,
    disabledDates,
    isDateDisabled,
    today,
    invalidText = 'Enter dates like 1 Apr 2026 – 31 Mar 2027.',
    unavailableText = DEFAULT_UNAVAILABLE,
    placeholder = 'e.g. 1 Apr 2026 – 31 Mar 2027',
    ...rest
}: DateRangePickerProps) {
    const rules: DateRules = { min, max, disabledDates, isDateDisabled };
    const [uncontrolled, setUncontrolled] = useState(defaultValue);
    const [draft, setDraft] = useState<DateRange | null>(null);
    const [open, setOpen] = useState(false);
    const committed = value ?? uncontrolled;
    const shown = draft ?? committed;

    const choose = (range: DateRange) => {
        setUncontrolled(range);
        setDraft(null);
        onValueChange?.(range);
    };

    const display =
        committed.start && committed.end
            ? `${formatDate(committed.start)} – ${formatDate(committed.end)}`
            : '';

    return (
        <DateFieldShell
            {...rest}
            placeholder={placeholder}
            display={display}
            popoverLabel="Choose dates"
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    setDraft(null);
                }
            }}
            commit={(text) => {
                if (text.trim() === '') {
                    choose(EMPTY_RANGE);

                    return null;
                }

                const range = parseRangeText(text, parseDateLenient);

                if (range === null) {
                    return invalidText;
                }

                if (
                    !isDateAllowed(range.start, rules) ||
                    !isDateAllowed(range.end, rules)
                ) {
                    return unavailableText;
                }

                choose(range);

                return null;
            }}
            calendar={
                <Calendar
                    {...rules}
                    range={shown}
                    today={today}
                    autoFocus
                    onSelect={(iso) => {
                        const start = draft?.start ?? null;

                        if (start === null || draft?.end) {
                            setDraft({ start: iso, end: null });
                        } else if (iso < start) {
                            setDraft({ start: iso, end: null });
                        } else {
                            choose({ start, end: iso });
                            setOpen(false);
                        }
                    }}
                />
            }
            hidden={
                name !== undefined && (
                    <>
                        <input
                            type="hidden"
                            name={`${name}[start]`}
                            value={committed.start ?? ''}
                        />
                        <input
                            type="hidden"
                            name={`${name}[end]`}
                            value={committed.end ?? ''}
                        />
                    </>
                )
            }
        />
    );
}

import { useLayoutEffect, useRef, useState } from 'react';
import type {
    ChangeEvent,
    InputHTMLAttributes,
    KeyboardEvent,
    ReactNode,
    Ref,
} from 'react';
import {
    reformatAmountInput,
    skipGroupingComma,
} from '@/components/ui/amount-input.utils';
import { Field, mergeRefs, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { formatPaiseDigits, parseRupeesToPaise } from '@/lib/format';
import { cn } from '@/lib/utils';

export type AmountInputProps = FieldMessageProps & {
    /** Says what the amount is: "Amount", "Opening balance". */
    label?: ReactNode;
    /** Integer paise, or null when empty. Leave undefined for an uncontrolled field. */
    value?: number | null;
    defaultValue?: number | null;
    /** Fires on every edit with integer paise (null when empty). */
    onValueChange?: (paise: number | null) => void;
    /** 40 px (`cf-inwrap--sm`) for split rows. */
    size?: 'md' | 'sm';
    /** Right-align the figure (split/payer rows). */
    align?: 'left' | 'right';
    /** Trailing caption inside the field: "/ hour", "/ month" (Team.dc.html). */
    suffix?: ReactNode;
    /**
     * Form field name. A hidden input with this name carries the value in paise, so
     * plain form posts never see the grouped text.
     */
    name?: string;
    /** Class for the `cf-inwrap` wrapper (e.g. a max width). */
    wrapClassName?: string;
    fieldClassName?: string;
    ref?: Ref<HTMLInputElement>;
} & Omit<
        InputHTMLAttributes<HTMLInputElement>,
        'value' | 'defaultValue' | 'size' | 'name' | 'type' | 'inputMode'
    >;

const toText = (paise: number | null | undefined): string =>
    paise === null || paise === undefined ? '' : formatPaiseDigits(paise);

const toPaise = (text: string): number | null =>
    text === '' ? null : parseRupeesToPaise(text);

/**
 * Every rupee value: ₹ prefix, IBM Plex Mono with tabular figures, Indian grouping as
 * the person types (caret kept in place). Value in and out is integer paise; a minus
 * can never be typed (a refund or drawing is its own record).
 */
export function AmountInput({
    label,
    help,
    error,
    value,
    defaultValue,
    onValueChange,
    size = 'md',
    align = 'left',
    suffix,
    name,
    wrapClassName,
    fieldClassName,
    className,
    style,
    id: idProp,
    disabled,
    readOnly,
    onChange,
    onKeyDown,
    'aria-describedby': describedByProp,
    ref,
    ...rest
}: AmountInputProps) {
    const ids = useFieldIds(idProp, { help, error }, describedByProp);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [draft, setDraft] = useState<{ text: string; caret: number | null }>(
        () => ({ text: toText(value ?? defaultValue), caret: null }),
    );
    const [lastValue, setLastValue] = useState(value);

    // A new value from outside (reset, recalculated split): show it, unless it is
    // what the current text already means (so "1,234." isn't rewritten to "1,234").
    if (value !== lastValue) {
        setLastValue(value);

        if (value !== undefined && value !== toPaise(draft.text)) {
            setDraft({ text: toText(value), caret: null });
        }
    }

    useLayoutEffect(() => {
        const input = inputRef.current;

        if (
            draft.caret !== null &&
            input &&
            input.ownerDocument.activeElement === input
        ) {
            input.setSelectionRange(draft.caret, draft.caret);
        }
    }, [draft]);

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        const input = event.currentTarget;
        const next = reformatAmountInput(
            input.value,
            input.selectionStart ?? input.value.length,
        );

        // Always a new object, so a rejected character (a minus, a letter) still
        // re-renders and the caret is put back where it was.
        setDraft(next);
        onChange?.(event);

        const paise = toPaise(next.text);

        if (paise !== toPaise(draft.text)) {
            onValueChange?.(paise);
        }
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        onKeyDown?.(event);

        const input = event.currentTarget;

        if (
            event.defaultPrevented ||
            (event.key !== 'Backspace' && event.key !== 'Delete') ||
            input.selectionStart !== input.selectionEnd
        ) {
            return;
        }

        const moveTo = skipGroupingComma(
            input.value,
            input.selectionStart ?? 0,
            event.key,
        );

        if (moveTo !== null) {
            input.setSelectionRange(moveTo, moveTo);
        }
    };

    const control = (
        <span
            className={cn(
                'cf-inwrap',
                size === 'sm' && 'cf-inwrap--sm',
                ids.invalid && 'is-error',
                disabled && 'is-disabled',
                readOnly && 'is-readonly',
                wrapClassName,
            )}
        >
            <span className="cf-prefix" aria-hidden="true">
                ₹
            </span>
            <input
                ref={mergeRefs(ref, inputRef)}
                id={ids.id}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                className={cn('t-mono', className)}
                style={{
                    fontSize: 15,
                    textAlign: align === 'right' ? 'right' : undefined,
                    ...style,
                }}
                value={draft.text}
                disabled={disabled}
                readOnly={readOnly}
                aria-invalid={ids.invalid || undefined}
                aria-describedby={ids.describedBy}
                onChange={handleChange}
                onKeyDown={handleKeyDown}
                {...rest}
            />
            {suffix !== undefined && (
                <span className="t-caption t-muted whitespace-nowrap">
                    {suffix}
                </span>
            )}
            {name !== undefined && (
                <input
                    type="hidden"
                    name={name}
                    value={toPaise(draft.text) ?? ''}
                    disabled={disabled}
                />
            )}
        </span>
    );

    if (label === undefined && help === undefined && error === undefined) {
        return control;
    }

    return (
        <Field
            ids={ids}
            label={label}
            help={help}
            error={error}
            className={fieldClassName}
        >
            {control}
        </Field>
    );
}

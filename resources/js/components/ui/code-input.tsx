import { Fragment, useEffect, useRef, useState } from 'react';
import type {
    ChangeEvent,
    ClipboardEvent,
    KeyboardEvent,
    ReactNode,
} from 'react';
import {
    codeToDigits,
    fillCode,
    isCodeComplete,
} from '@/components/ui/code-input.utils';
import { FieldMessage, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { cn } from '@/lib/utils';

const LENGTH = 6;

export type CodeInputProps = FieldMessageProps & {
    /** Visible group label (`legend.cf-label`), e.g. "Enter the 6-digit code". */
    legend?: ReactNode;
    /** Set (or reset, with '') the code from outside. */
    value?: string;
    defaultValue?: string;
    /** Every edit, with the digits typed so far. */
    onChange?: (code: string) => void;
    /** Fires once the sixth digit is in: submit here. */
    onComplete?: (code: string) => void;
    /** Checking the code: boxes disabled, spinner line beside them. */
    verifying?: boolean;
    verifyingLabel?: ReactNode;
    /** When an error arrives, clear the boxes and focus the first (default true). */
    clearOnError?: boolean;
    autoFocus?: boolean;
    disabled?: boolean;
    /** Hidden input name carrying the code, for plain form posts. */
    name?: string;
    id?: string;
    className?: string;
};

/**
 * Six-digit code for 2FA sign-in, vault unlock and Reveal checks: one mono box per
 * digit, auto-advance, Backspace goes back, arrows move, paste fills every box.
 */
export function CodeInput({
    legend,
    value,
    defaultValue = '',
    onChange,
    onComplete,
    verifying = false,
    verifyingLabel = 'Verifying…',
    clearOnError = true,
    autoFocus = false,
    disabled = false,
    name,
    help,
    error,
    id: idProp,
    className,
}: CodeInputProps) {
    const ids = useFieldIds(idProp, { help, error });
    const boxes = useRef<(HTMLInputElement | null)[]>([]);
    const [digits, setDigits] = useState(() =>
        codeToDigits(value ?? defaultValue, LENGTH),
    );
    const [lastValue, setLastValue] = useState(value);
    const [lastInvalid, setLastInvalid] = useState(ids.invalid);
    // A new object per request, so the effect runs even when it's the same box again.
    const [focusRequest, setFocusRequest] = useState<{ index: number } | null>(
        autoFocus ? { index: 0 } : null,
    );

    if (value !== lastValue) {
        setLastValue(value);

        if (value !== undefined && value !== digits.join('')) {
            setDigits(codeToDigits(value, LENGTH));
        }
    }

    // A new error: clear the boxes and start again from the first.
    if (ids.invalid !== lastInvalid) {
        setLastInvalid(ids.invalid);

        if (ids.invalid && clearOnError) {
            setDigits(codeToDigits('', LENGTH));
            setFocusRequest({ index: 0 });
        }
    }

    useEffect(() => {
        if (focusRequest !== null) {
            boxes.current[focusRequest.index]?.focus();
            boxes.current[focusRequest.index]?.select();
        }
    }, [focusRequest]);

    const locked = disabled || verifying;

    const commit = (next: string[], focus: number | null) => {
        setDigits(next);

        if (focus !== null) {
            setFocusRequest({ index: focus });
        }

        const code = next.join('');
        onChange?.(code);

        if (isCodeComplete(next) && code !== digits.join('')) {
            onComplete?.(code);
        }
    };

    const focusBox = (index: number) => {
        const box = boxes.current[Math.max(0, Math.min(LENGTH - 1, index))];
        box?.focus();
        box?.select();
    };

    const handleChange = (
        index: number,
        event: ChangeEvent<HTMLInputElement>,
    ) => {
        const typed = event.currentTarget.value;

        if (typed === '') {
            const next = [...digits];
            next[index] = '';
            commit(next, null);

            return;
        }

        const filled = fillCode(digits, index, typed);

        if (filled.digits.join('') === digits.join('')) {
            return; // a letter or symbol: nothing to do
        }

        commit(filled.digits, filled.focus);
    };

    const handlePaste = (
        index: number,
        event: ClipboardEvent<HTMLInputElement>,
    ) => {
        event.preventDefault();
        const filled = fillCode(
            digits,
            index,
            event.clipboardData.getData('text'),
        );
        commit(filled.digits, filled.focus);
    };

    const handleKeyDown = (
        index: number,
        event: KeyboardEvent<HTMLInputElement>,
    ) => {
        switch (event.key) {
            case 'Backspace':
                if (digits[index] === '' && index > 0) {
                    event.preventDefault();
                    const next = [...digits];
                    next[index - 1] = '';
                    commit(next, index - 1);
                }

                break;
            case 'ArrowLeft':
                event.preventDefault();
                focusBox(index - 1);
                break;
            case 'ArrowRight':
                event.preventDefault();
                focusBox(index + 1);
                break;
            case 'Home':
                event.preventDefault();
                focusBox(0);
                break;
            case 'End':
                event.preventDefault();
                focusBox(LENGTH - 1);
                break;
        }
    };

    const code = (
        <div
            className={cn(
                'cf-code',
                ids.invalid && 'is-error',
                locked && 'is-disabled',
            )}
            aria-busy={verifying || undefined}
        >
            {digits.map((digit, index) => (
                <Fragment key={index}>
                    {index === LENGTH / 2 && (
                        <span className="cf-code-sep" aria-hidden="true" />
                    )}
                    <input
                        ref={(node) => {
                            boxes.current[index] = node;
                        }}
                        id={index === 0 ? ids.id : undefined}
                        className="cf-code-box"
                        aria-label={`Digit ${index + 1}`}
                        inputMode="numeric"
                        maxLength={1}
                        autoComplete={index === 0 ? 'one-time-code' : 'off'}
                        value={digit}
                        disabled={locked}
                        aria-invalid={ids.invalid || undefined}
                        onChange={(event) => handleChange(index, event)}
                        onPaste={(event) => handlePaste(index, event)}
                        onKeyDown={(event) => handleKeyDown(index, event)}
                        onFocus={(event) => event.currentTarget.select()}
                    />
                </Fragment>
            ))}
        </div>
    );

    return (
        <fieldset
            className={cn('cf-field', className)}
            style={{ border: 0, padding: 0, margin: 0 }}
            aria-describedby={ids.describedBy}
        >
            {legend !== undefined && (
                <legend className="cf-label" style={{ marginBottom: 6 }}>
                    {legend}
                </legend>
            )}
            {verifying ? (
                <div className="flex flex-wrap items-center gap-3">
                    {code}
                    <span
                        className="t-caption t-muted flex flex-wrap items-center"
                        style={{ gap: 6 }}
                        role="status"
                    >
                        <span
                            className="cf-spinner"
                            style={{ width: 12, height: 12 }}
                            aria-hidden="true"
                        />
                        {verifyingLabel}
                    </span>
                </div>
            ) : (
                code
            )}
            {name !== undefined && (
                <input type="hidden" name={name} value={digits.join('')} />
            )}
            <FieldMessage ids={ids} help={help} error={error} />
        </fieldset>
    );
}

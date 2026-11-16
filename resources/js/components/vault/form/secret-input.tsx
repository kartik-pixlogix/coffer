import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import type { FocusEventHandler, ReactNode, Ref } from 'react';
import { Field, useFieldIds } from '@/components/ui/field';
import { IconButton } from '@/components/ui/icon-button';
import { cn } from '@/lib/utils';

export const SAVED_PLACEHOLDER = 'Saved · type to replace';
export const CLEARED_PLACEHOLDER = 'Removed when you save';

export type SavedSecretProps = {
    /** Edit mode: a value is stored (the form never receives it). */
    saved?: boolean;
    /** The stored value will be removed on save (`clear[]`). */
    cleared?: boolean;
    onClear?: () => void;
    onUndoClear?: () => void;
};

/** "Remove" for a stored secret, or "Removed on save · Undo" once chosen. */
export function SavedSecretAction({
    saved,
    cleared,
    typed,
    label,
    onClear,
    onUndoClear,
}: SavedSecretProps & { typed: boolean; label: string }) {
    if (!saved || typed) {
        return null;
    }

    if (cleared) {
        return (
            <span className="t-caption t-muted">
                Removed on save ·{' '}
                <button
                    type="button"
                    className="cf-link"
                    onClick={onUndoClear}
                    aria-label={`Keep the saved ${label.toLowerCase()}`}
                >
                    Undo
                </button>
            </span>
        );
    }

    return (
        <button
            type="button"
            className="cf-link t-caption"
            onClick={onClear}
            aria-label={`Remove the saved ${label.toLowerCase()}`}
        >
            Remove
        </button>
    );
}

export const savedPlaceholder = (
    { saved, cleared }: SavedSecretProps,
    fallback?: string,
): string | undefined =>
    saved ? (cleared ? CLEARED_PLACEHOLDER : SAVED_PLACEHOLDER) : fallback;

type SecretInputProps = SavedSecretProps & {
    label: string;
    value: string;
    onValueChange: (value: string) => void;
    /** `secret_text`: a monospace textarea (private keys, recovery codes). */
    multiline?: boolean;
    placeholder?: string;
    help?: ReactNode;
    error?: ReactNode;
    /** Right of the label: the strength word. */
    aside?: ReactNode;
    /** After the show/hide button inside the input: the Generate button. */
    addon?: ReactNode;
    /** Under the input, before the help: the strength dots. */
    below?: ReactNode;
    /** Shown in clear text (after "Use this password"). */
    shown?: boolean;
    onShownChange?: (shown: boolean) => void;
    onFocus?: FocusEventHandler<HTMLElement>;
    id?: string;
    fieldClassName?: string;
    ref?: Ref<HTMLInputElement & HTMLTextAreaElement>;
};

/**
 * A secret template field: masked, with Show / Hide, never prefilled. In edit mode an
 * empty field keeps the stored value ("Saved · type to replace"), and Remove clears it.
 */
export function SecretInput({
    label,
    value,
    onValueChange,
    multiline = false,
    placeholder,
    help,
    error,
    aside,
    addon,
    below,
    shown: shownProp,
    onShownChange,
    onFocus,
    id: idProp,
    fieldClassName,
    saved,
    cleared,
    onClear,
    onUndoClear,
    ref,
}: SecretInputProps) {
    const ids = useFieldIds(idProp, { help, error });
    const [shownState, setShownState] = useState(false);
    const shown = shownProp ?? shownState;
    const setShown = (next: boolean) => {
        setShownState(next);
        onShownChange?.(next);
    };
    const typed = value !== '';
    const toggle = (
        <IconButton
            icon={shown ? EyeOff : Eye}
            size="sm"
            variant="bare"
            iconSize={16}
            aria-label={`${shown ? 'Hide' : 'Show'} ${label.toLowerCase()}`}
            pressed={shown}
            onClick={() => setShown(!shown)}
        />
    );
    const action = (
        <SavedSecretAction
            saved={saved}
            cleared={cleared}
            typed={typed}
            label={label}
            onClear={onClear}
            onUndoClear={onUndoClear}
        />
    );
    const shared = {
        id: ids.id,
        value,
        placeholder: savedPlaceholder({ saved, cleared }, placeholder),
        spellCheck: false,
        autoCapitalize: 'off',
        'aria-invalid': ids.invalid || undefined,
        'aria-describedby': ids.describedBy,
        onFocus,
    } as const;

    return (
        <Field
            ids={ids}
            label={label}
            labelAction={
                multiline ? (
                    <span className="cf-row vf-label-actions">
                        {aside}
                        {action}
                        {toggle}
                    </span>
                ) : aside || (saved && !typed) ? (
                    <span className="cf-row vf-label-actions">
                        {aside}
                        {action}
                    </span>
                ) : undefined
            }
            help={help}
            error={error}
            className={cn('vf-secret', fieldClassName)}
        >
            {multiline ? (
                <textarea
                    ref={ref}
                    {...shared}
                    className={cn('cf-textarea vf-mono', !shown && 'vf-masked')}
                    rows={4}
                    autoComplete="off"
                    onChange={(event) =>
                        onValueChange(event.currentTarget.value)
                    }
                />
            ) : (
                <span className={cn('cf-inwrap', ids.invalid && 'is-error')}>
                    <input
                        ref={ref}
                        {...shared}
                        type={shown ? 'text' : 'password'}
                        className="vf-mono"
                        autoComplete="new-password"
                        onChange={(event) =>
                            onValueChange(event.currentTarget.value)
                        }
                    />
                    {toggle}
                    {addon}
                </span>
            )}
            {below}
        </Field>
    );
}

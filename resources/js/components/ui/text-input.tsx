import type { InputHTMLAttributes, ReactNode, Ref } from 'react';
import { Field, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { cn } from '@/lib/utils';

export type TextInputProps = FieldMessageProps & {
    /** Visible label above the input. Leave out only when an aria-label names it (table cells). */
    label?: ReactNode;
    /** 48 px (default) or 40 px for dense forms (`cf-input--sm`). */
    size?: 'md' | 'sm';
    /** An async check is running: wraps the input in `cf-inwrap` with a spinner at the end. */
    loading?: boolean;
    /** Class for the `cf-field` wrapper. */
    fieldClassName?: string;
    ref?: Ref<HTMLInputElement>;
} & Omit<InputHTMLAttributes<HTMLInputElement>, 'size'>;

/** Inline spinner at the end of a `cf-inwrap` (async checks, loading results). */
export function InputSpinner() {
    return (
        <span
            className="cf-spinner"
            style={{ marginRight: 10, color: 'var(--ink-secondary)' }}
            aria-hidden="true"
        />
    );
}

/**
 * Single-line free text (`cf-input`) with a visible label, help text and errors.
 * Spreads native input props, so it works controlled or uncontrolled.
 */
export function TextInput({
    label,
    help,
    error,
    size = 'md',
    loading = false,
    fieldClassName,
    className,
    id: idProp,
    type = 'text',
    'aria-describedby': describedByProp,
    ref,
    ...rest
}: TextInputProps) {
    const ids = useFieldIds(idProp, { help, error }, describedByProp);

    const control = loading ? (
        <span
            className={cn(
                'cf-inwrap',
                size === 'sm' && 'cf-inwrap--sm',
                ids.invalid && 'is-error',
                rest.disabled && 'is-disabled',
                rest.readOnly && 'is-readonly',
            )}
        >
            <input
                ref={ref}
                id={ids.id}
                type={type}
                className={className}
                aria-invalid={ids.invalid || undefined}
                aria-describedby={ids.describedBy}
                aria-busy="true"
                {...rest}
            />
            <InputSpinner />
        </span>
    ) : (
        <input
            ref={ref}
            id={ids.id}
            type={type}
            className={cn(
                'cf-input',
                size === 'sm' && 'cf-input--sm',
                className,
            )}
            aria-invalid={ids.invalid || undefined}
            aria-describedby={ids.describedBy}
            {...rest}
        />
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

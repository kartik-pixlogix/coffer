import { useId, useState } from 'react';
import type { InputHTMLAttributes, ReactNode, Ref } from 'react';
import { FieldMessage, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { cn } from '@/lib/utils';

export type RadioProps = {
    children: ReactNode;
    /** Error outline on the dot (`.is-error` + aria-invalid). */
    invalid?: boolean;
    /** Class for the `cf-radio` label (doc states: is-hover, is-focus). */
    className?: string;
    ref?: Ref<HTMLInputElement>;
} & Omit<
    InputHTMLAttributes<HTMLInputElement>,
    'type' | 'className' | 'children'
>;

/** One radio option (`cf-radio`). Use inside a RadioGroup, or a fieldset of your own. */
export function Radio({
    children,
    invalid = false,
    className,
    disabled,
    ref,
    ...rest
}: RadioProps) {
    return (
        <label className={cn('cf-radio', invalid && 'is-error', className)}>
            <input
                ref={ref}
                className="cf-sr"
                type="radio"
                disabled={disabled}
                aria-invalid={invalid || undefined}
                {...rest}
            />
            <span className="cf-radio-dot" />
            <span className={disabled ? 't-faint' : undefined}>{children}</span>
        </label>
    );
}

export type RadioGroupOption = {
    value: string;
    label: ReactNode;
    disabled?: boolean;
    /** Doc-state class for this option's label. */
    className?: string;
};

export type RadioGroupProps = FieldMessageProps & {
    /** Visible group label (`legend.cf-label`). */
    legend: ReactNode;
    options: RadioGroupOption[];
    /** Form field name; generated when left out. */
    name?: string;
    value?: string | null;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    disabled?: boolean;
    required?: boolean;
    className?: string;
};

/**
 * Two to five mutually exclusive options that each need a sentence:
 * `fieldset.cf-field` › `legend.cf-label` + `cf-radio` options + one error under the group.
 */
export function RadioGroup({
    legend,
    options,
    name: nameProp,
    value,
    defaultValue,
    onValueChange,
    disabled = false,
    required,
    help,
    error,
    className,
}: RadioGroupProps) {
    const generatedName = useId();
    const name = nameProp ?? generatedName;
    const ids = useFieldIds(undefined, { help, error });
    const [uncontrolled, setUncontrolled] = useState(defaultValue ?? null);
    const selected = value !== undefined ? value : uncontrolled;

    return (
        <fieldset
            className={cn('cf-field', className)}
            style={{ border: 0, padding: 0, margin: 0, gap: 10 }}
            aria-describedby={ids.describedBy}
            disabled={disabled || undefined}
        >
            <legend className="cf-label" style={{ marginBottom: 8 }}>
                {legend}
            </legend>
            {options.map((option) => (
                <Radio
                    key={option.value}
                    name={name}
                    value={option.value}
                    checked={selected === option.value}
                    disabled={disabled || option.disabled}
                    required={required}
                    invalid={ids.invalid}
                    className={option.className}
                    onChange={() => {
                        setUncontrolled(option.value);
                        onValueChange?.(option.value);
                    }}
                >
                    {option.label}
                </Radio>
            ))}
            <FieldMessage ids={ids} help={help} error={error} />
        </fieldset>
    );
}

import { ChevronDown } from 'lucide-react';
import type { ReactNode, Ref, SelectHTMLAttributes } from 'react';
import { Field, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type SelectOption = {
    value: string;
    label: string;
    disabled?: boolean;
};

export type SelectProps = FieldMessageProps & {
    label?: ReactNode;
    /** 40 px rounded toolbar filter (`cf-select--pill`); name it with aria-label. */
    pill?: boolean;
    /** Options as data; or pass <option> children. */
    options?: SelectOption[];
    /** A first, empty option such as "Choose a partner". */
    placeholder?: string;
    /** Class for the `cf-select` wrapper. */
    wrapClassName?: string;
    fieldClassName?: string;
    ref?: Ref<HTMLSelectElement>;
} & SelectHTMLAttributes<HTMLSelectElement>;

/**
 * Native select with the Coffer chevron, for short fixed lists (up to ~10 options).
 * Use Combobox when people need to search.
 */
export function Select({
    label,
    help,
    error,
    pill = false,
    options,
    placeholder,
    wrapClassName,
    fieldClassName,
    id: idProp,
    children,
    'aria-describedby': describedByProp,
    ref,
    ...rest
}: SelectProps) {
    const ids = useFieldIds(idProp, { help, error }, describedByProp);

    const control = (
        <span
            className={cn(
                'cf-select',
                pill && 'cf-select--pill',
                ids.invalid && 'is-error',
                wrapClassName,
            )}
        >
            <select
                ref={ref}
                id={ids.id}
                aria-invalid={ids.invalid || undefined}
                aria-describedby={ids.describedBy}
                {...rest}
            >
                {placeholder !== undefined && (
                    <option value="">{placeholder}</option>
                )}
                {options?.map((option) => (
                    <option
                        key={option.value}
                        value={option.value}
                        disabled={option.disabled}
                    >
                        {option.label}
                    </option>
                ))}
                {children}
            </select>
            <Icon icon={ChevronDown} size={pill ? 14 : 16} />
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

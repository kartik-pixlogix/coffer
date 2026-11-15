import { Check, Minus } from 'lucide-react';
import type { InputHTMLAttributes, ReactNode, Ref } from 'react';
import { FieldMessage, mergeRefs, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type CheckboxProps = FieldMessageProps & {
    /** Visible label text. For row-selection checkboxes without text, pass aria-label instead. */
    children?: ReactNode;
    /** Header checkbox when only some rows are selected: shows a dash (`:indeterminate`). */
    indeterminate?: boolean;
    /** Called with the new checked state (alongside the native onChange). */
    onCheckedChange?: (checked: boolean) => void;
    /** Class for the `cf-check` label (doc states: is-hover, is-focus). */
    className?: string;
    fieldClassName?: string;
    ref?: Ref<HTMLInputElement>;
} & Omit<
        InputHTMLAttributes<HTMLInputElement>,
        'type' | 'className' | 'children'
    >;

/** Independent yes/no choice saved with a form, or row selection (`cf-check`). */
export function Checkbox({
    children,
    indeterminate = false,
    onCheckedChange,
    help,
    error,
    className,
    fieldClassName,
    id: idProp,
    onChange,
    'aria-describedby': describedByProp,
    ref,
    ...rest
}: CheckboxProps) {
    const ids = useFieldIds(idProp, { help, error }, describedByProp);

    const setIndeterminate = (node: HTMLInputElement | null) => {
        if (node) {
            node.indeterminate = indeterminate;
        }
    };

    const control = (
        <label className={cn('cf-check', ids.invalid && 'is-error', className)}>
            <input
                ref={mergeRefs(ref, setIndeterminate)}
                id={ids.id}
                className="cf-sr"
                type="checkbox"
                aria-invalid={ids.invalid || undefined}
                aria-describedby={ids.describedBy}
                onChange={(event) => {
                    onChange?.(event);
                    onCheckedChange?.(event.currentTarget.checked);
                }}
                {...rest}
            />
            <span className="cf-check-box">
                {indeterminate ? (
                    <Icon
                        icon={Minus}
                        size={14}
                        strokeWidth={2.5}
                        className="opacity-100!"
                    />
                ) : (
                    <Icon icon={Check} size={14} strokeWidth={2.5} />
                )}
            </span>
            {children !== undefined && <span>{children}</span>}
        </label>
    );

    if (help === undefined && error === undefined) {
        return control;
    }

    return (
        <div className={cn('cf-field', fieldClassName)}>
            {control}
            <FieldMessage ids={ids} help={help} error={error} />
        </div>
    );
}

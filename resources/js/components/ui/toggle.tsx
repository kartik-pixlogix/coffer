import type { InputHTMLAttributes, ReactNode, Ref } from 'react';
import { FieldMessage, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { cn } from '@/lib/utils';

export type ToggleProps = FieldMessageProps & {
    /** Visible label text. */
    children: ReactNode;
    /** The change is being saved: shows `savingLabel` with a spinner (`aria-busy`). */
    saving?: boolean;
    savingLabel?: ReactNode;
    onCheckedChange?: (checked: boolean) => void;
    /** Class for the `cf-switch` label (doc states: is-hover, is-focus). */
    className?: string;
    fieldClassName?: string;
    ref?: Ref<HTMLInputElement>;
} & Omit<
        InputHTMLAttributes<HTMLInputElement>,
        'type' | 'role' | 'className' | 'children'
    >;

/**
 * On/off switch (`cf-switch`) for settings that apply at once. If saving fails,
 * put the old value back and pass `error`.
 */
export function Toggle({
    children,
    saving = false,
    savingLabel = 'Saving',
    onCheckedChange,
    help,
    error,
    className,
    fieldClassName,
    id: idProp,
    disabled,
    onChange,
    'aria-describedby': describedByProp,
    ref,
    ...rest
}: ToggleProps) {
    const ids = useFieldIds(idProp, { help, error }, describedByProp);

    const control = (
        <label
            className={cn('cf-switch', className)}
            aria-busy={saving || undefined}
        >
            <input
                ref={ref}
                id={ids.id}
                className="cf-sr"
                type="checkbox"
                role="switch"
                disabled={disabled}
                aria-invalid={ids.invalid || undefined}
                aria-describedby={ids.describedBy}
                onChange={(event) => {
                    onChange?.(event);
                    onCheckedChange?.(event.currentTarget.checked);
                }}
                {...rest}
            />
            <span
                className="cf-switch-track"
                style={
                    ids.invalid
                        ? { boxShadow: '0 0 0 2px var(--negative)' }
                        : undefined
                }
            />
            {saving ? (
                <span
                    style={{
                        display: 'flex',
                        flexWrap: 'wrap',
                        alignItems: 'center',
                        gap: 6,
                    }}
                >
                    <span className="cf-sr">{children}: </span>
                    {savingLabel}
                    <span
                        className="cf-spinner"
                        style={{ width: 12, height: 12 }}
                        aria-hidden="true"
                    />
                </span>
            ) : (
                <span className={disabled ? 't-faint' : undefined}>
                    {children}
                </span>
            )}
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

import { useState } from 'react';
import type {
    ChangeEvent,
    ReactNode,
    Ref,
    TextareaHTMLAttributes,
} from 'react';
import { Field, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { formatIndianNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type TextareaProps = FieldMessageProps & {
    label?: ReactNode;
    /**
     * Character limit. The count ("512 / 500") appears in the label row once the text
     * reaches `countFrom` (default 80% of the limit); going over shows `limitError`.
     */
    limit?: number;
    countFrom?: number;
    /** Error shown when the text is over `limit` and no other error is set. */
    limitError?: (limit: number) => ReactNode;
    fieldClassName?: string;
    ref?: Ref<HTMLTextAreaElement>;
} & TextareaHTMLAttributes<HTMLTextAreaElement>;

const defaultLimitError = (limit: number) =>
    `Keep notes under ${formatIndianNumber(limit)} characters.`;

/** Multi-line notes (`cf-textarea`, 96 px minimum, resizes vertically). */
export function Textarea({
    label,
    help,
    error,
    limit,
    countFrom,
    limitError = defaultLimitError,
    fieldClassName,
    className,
    id: idProp,
    value,
    defaultValue,
    onChange,
    'aria-describedby': describedByProp,
    ref,
    ...rest
}: TextareaProps) {
    const [uncontrolledLength, setUncontrolledLength] = useState(
        () => String(defaultValue ?? '').length,
    );
    const length =
        value !== undefined ? String(value).length : uncontrolledLength;
    const over = limit !== undefined && length > limit;
    const shownError = error ?? (over ? limitError(limit) : undefined);
    const ids = useFieldIds(
        idProp,
        { help, error: shownError },
        describedByProp,
    );
    const showCount =
        limit !== undefined && length >= (countFrom ?? Math.floor(limit * 0.8));

    const handleChange = (event: ChangeEvent<HTMLTextAreaElement>) => {
        setUncontrolledLength(event.currentTarget.value.length);
        onChange?.(event);
    };

    const control = (
        <textarea
            ref={ref}
            id={ids.id}
            className={cn('cf-textarea', className)}
            value={value}
            defaultValue={defaultValue}
            onChange={handleChange}
            aria-invalid={ids.invalid || undefined}
            aria-describedby={ids.describedBy}
            {...rest}
        />
    );

    if (label === undefined && help === undefined && shownError === undefined) {
        return control;
    }

    return (
        <Field
            ids={ids}
            label={label}
            aside={
                showCount
                    ? `${formatIndianNumber(length)} / ${formatIndianNumber(limit)}`
                    : undefined
            }
            help={help}
            error={shownError}
            className={fieldClassName}
        >
            {control}
        </Field>
    );
}

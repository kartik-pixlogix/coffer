import { TriangleAlert } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode, Ref, RefCallback } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

/*
 * The form-field pattern every input shares (design-system TextInput README):
 *   div.cf-field › label.cf-label (or .cf-label-row with an aside such as a counter)
 *   + the control + span.cf-help / span.cf-errtext (alert icon), linked to the
 *   control with aria-describedby; the control carries aria-invalid on error.
 */

export type FieldMessageProps = {
    /** Help text under the control (`cf-help`). Hidden while there is an error. */
    help?: ReactNode;
    /** Error text (`cf-errtext`). Say how to fix it: "Add a company name." */
    error?: ReactNode;
};

export type FieldIds = {
    /** Id of the control (`for` / `id`). */
    id: string;
    helpId: string;
    errorId: string;
    /** Value for the control's aria-describedby (undefined when there is nothing to describe). */
    describedBy: string | undefined;
    invalid: boolean;
};

const hasContent = (node: ReactNode): boolean =>
    node !== undefined && node !== null && node !== false && node !== '';

/** Stable ids for a field's control, help and error, plus aria-describedby. */
export function useFieldIds(
    idProp: string | undefined,
    { help, error }: FieldMessageProps,
    extraDescribedBy?: string,
): FieldIds {
    const generated = useId();
    const id = idProp ?? generated;
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const invalid = hasContent(error);
    const describedBy =
        [
            extraDescribedBy,
            invalid ? errorId : hasContent(help) ? helpId : undefined,
        ]
            .filter(Boolean)
            .join(' ') || undefined;

    return { id, helpId, errorId, describedBy, invalid };
}

/** `cf-errtext` with the alert icon. */
export function FieldError({
    id,
    children,
    className,
}: {
    id?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <span id={id} className={cn('cf-errtext', className)}>
            <Icon icon={TriangleAlert} size={14} />
            {children}
        </span>
    );
}

/** `cf-help` text. */
export function FieldHelp({
    id,
    children,
    className,
}: {
    id?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <span id={id} className={cn('cf-help', className)}>
            {children}
        </span>
    );
}

/** The help or error line under a control (error wins). */
export function FieldMessage({
    ids,
    help,
    error,
}: FieldMessageProps & { ids: Pick<FieldIds, 'helpId' | 'errorId'> }) {
    if (hasContent(error)) {
        return <FieldError id={ids.errorId}>{error}</FieldError>;
    }

    if (hasContent(help)) {
        return <FieldHelp id={ids.helpId}>{help}</FieldHelp>;
    }

    return null;
}

export type FieldProps = FieldMessageProps & {
    ids: Pick<FieldIds, 'id' | 'helpId' | 'errorId'>;
    label?: ReactNode;
    /**
     * Right side of the label row (`cf-label-row`), e.g. a character count
     * "512 / 500" or "Last changed 28 Sep 2026". Rendered as `cf-help`.
     */
    aside?: ReactNode;
    /** Render the label as a plain span (for controls that aren't labelable, like a masked secret). */
    labelAs?: 'label' | 'span';
    className?: string;
    children: ReactNode;
};

/** `cf-field` column: label (row), control, help or error. */
export function Field({
    ids,
    label,
    aside,
    labelAs = 'label',
    help,
    error,
    className,
    children,
}: FieldProps) {
    const labelNode = !hasContent(label) ? null : labelAs === 'label' ? (
        <label className="cf-label" htmlFor={ids.id}>
            {label}
        </label>
    ) : (
        <span className="cf-label" id={`${ids.id}-label`}>
            {label}
        </span>
    );

    return (
        <div className={cn('cf-field', className)}>
            {hasContent(aside) ? (
                <div className="cf-label-row">
                    {labelNode}
                    <span className="cf-help">{aside}</span>
                </div>
            ) : (
                labelNode
            )}
            {children}
            <FieldMessage ids={ids} help={help} error={error} />
        </div>
    );
}

/** Assign a value to any kind of React ref. */
export function assignRef<T>(ref: Ref<T> | undefined, value: T | null): void {
    if (typeof ref === 'function') {
        ref(value);
    } else if (ref) {
        ref.current = value;
    }
}

/** Combine refs into one callback ref (React 19 refs are plain props). */
export function mergeRefs<T>(...refs: (Ref<T> | undefined)[]): RefCallback<T> {
    return (value) => {
        refs.forEach((ref) => assignRef(ref, value));
    };
}

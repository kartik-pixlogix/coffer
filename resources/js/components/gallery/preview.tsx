import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/*
 * Layout helpers that reproduce the design-system preview page classes
 * (`pv-row`, `pv-grid`, `pv-state`, `pv-cell`, `pv-note` from bundle.css), so a
 * gallery section can be laid out exactly like its preview.html.
 */

export function PvRow({
    children,
    className,
    style,
}: {
    children: ReactNode;
    className?: string;
    style?: CSSProperties;
}) {
    return (
        <div className={cn('pv-row', className)} style={style}>
            {children}
        </div>
    );
}

/** `pv-grid` with the preview's own `grid-template-columns` (its `.m` rule). */
export function PvGrid({
    columns,
    children,
    className,
    style,
}: {
    columns: string;
    children: ReactNode;
    className?: string;
    style?: CSSProperties;
}) {
    return (
        <div
            className={cn('pv-grid', className)}
            style={{ gridTemplateColumns: columns, ...style }}
        >
            {children}
        </div>
    );
}

/** Small caption naming a state ("Default", "Hover", "Focus"…). */
export function PvState({ children }: { children?: ReactNode }) {
    return <span className="pv-state">{children}</span>;
}

/** A labelled cell: state caption above the specimen. */
export function PvCell({
    label,
    children,
    style,
}: {
    label?: ReactNode;
    children: ReactNode;
    style?: CSSProperties;
}) {
    return (
        <div className="pv-cell" style={style}>
            {label !== undefined && <PvState>{label}</PvState>}
            {children}
        </div>
    );
}

export function PvNote({ children }: { children: ReactNode }) {
    return <p className="pv-note">{children}</p>;
}

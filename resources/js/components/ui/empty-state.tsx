import type { LucideIcon } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type HeadingLevel = 2 | 3 | 4;

/** Heading style shared by the empty and error states. */
export const STATE_HEADING_STYLE: CSSProperties = {
    margin: 0,
    fontSize: 20,
    lineHeight: '24px',
};

export function StateHeading({
    level,
    children,
}: {
    level: HeadingLevel;
    children: ReactNode;
}) {
    const Tag = `h${level}` as const;

    return (
        <Tag className="t-heading" style={STATE_HEADING_STYLE}>
            {children}
        </Tag>
    );
}

/** Centred action row under an empty or error state. */
export function StateActions({ children }: { children: ReactNode }) {
    return (
        <div className="cf-actions" style={{ justifyContent: 'center' }}>
            {children}
        </div>
    );
}

export type EmptyStateProps = {
    icon: LucideIcon;
    /** Neutral disc (`cf-empty-ic--muted`) instead of the lime one. */
    muted?: boolean;
    /** What's missing: "No income yet". */
    title: ReactNode;
    /** Default 3 (inside a card under a page h1/h2). */
    headingLevel?: HeadingLevel;
    /** One sentence on what adding it does. */
    description?: ReactNode;
    /** The action that fills it (primary Button), plus an optional secondary. */
    actions?: ReactNode;
    className?: string;
};

/**
 * First-run empty module: lime icon disc, what's missing, one sentence, the action.
 * Never show an empty chart or a table of zeros instead.
 */
export function EmptyState({
    icon,
    muted = false,
    title,
    headingLevel = 3,
    description,
    actions,
    className,
}: EmptyStateProps) {
    return (
        <div className={cn('cf-empty', className)}>
            <span className={cn('cf-empty-ic', muted && 'cf-empty-ic--muted')}>
                <Icon icon={icon} size={24} />
            </span>
            <StateHeading level={headingLevel}>{title}</StateHeading>
            {description !== undefined && <p>{description}</p>}
            {actions !== undefined && <StateActions>{actions}</StateActions>}
        </div>
    );
}

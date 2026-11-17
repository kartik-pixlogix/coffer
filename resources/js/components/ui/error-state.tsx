import { TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { StateActions, StateHeading } from '@/components/ui/empty-state';
import type { HeadingLevel } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type ErrorStateProps = {
    /** What didn't load: "Reports didn’t load". */
    title: ReactNode;
    /** Reassure when data is safe: "Your data is safe; try again in a moment." */
    description?: ReactNode;
    /** Try again (primary) and Contact support (ghost). */
    actions?: ReactNode;
    /** Short reference for support, shown in mono as "Error ref 7F3A-21C9". */
    reference?: string;
    icon?: LucideIcon;
    /** Default 3. */
    headingLevel?: HeadingLevel;
    /** `alert` (default, as the design) announces it; `null` renders it without a live role. */
    role?: 'alert' | null;
    className?: string;
};

/** A panel or page that failed to load (`cf-error`). Keep the shell working around it. */
export function ErrorState({
    title,
    description,
    actions,
    reference,
    icon = TriangleAlert,
    headingLevel = 3,
    role = 'alert',
    className,
}: ErrorStateProps) {
    return (
        <div className={cn('cf-error', className)} role={role ?? undefined}>
            <span className="cf-error-ic">
                <Icon icon={icon} size={24} />
            </span>
            <StateHeading level={headingLevel}>{title}</StateHeading>
            {description !== undefined && <p>{description}</p>}
            {actions !== undefined && <StateActions>{actions}</StateActions>}
            {reference !== undefined && (
                <span className="t-money-sm t-muted">
                    Error ref {reference}
                </span>
            )}
        </div>
    );
}

import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import type { ComponentProps, ReactNode } from 'react';
import { formatIndianNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

/*
 * Pill tabs (`cf-tabs` / `cf-tab`). The selected tab is the ink fill, so it reads
 * differently from lime filter chips. Radix supplies role="tab", aria-selected,
 * aria-controls and arrow-key movement; styling is cf-* only.
 */

/** Tab state root (Radix `Tabs.Root`): `value` / `defaultValue` / `onValueChange`. */
export function Tabs(props: ComponentProps<typeof TabsPrimitive.Root>) {
    return <TabsPrimitive.Root {...props} />;
}

export type TabsListProps = Omit<
    ComponentProps<typeof TabsPrimitive.List>,
    'aria-label'
> & {
    /** Names the tab list ("Settings", "Team by partner"). */
    'aria-label': string;
};

export function TabsList({ className, ...rest }: TabsListProps) {
    return (
        <TabsPrimitive.List className={cn('cf-tabs', className)} {...rest} />
    );
}

function TabCount({ count }: { count?: number }) {
    return count === undefined ? null : (
        <span className="cf-tab-count">{formatIndianNumber(count)}</span>
    );
}

export type TabProps = Omit<
    ComponentProps<typeof TabsPrimitive.Trigger>,
    'children'
> & {
    /** Count badge after the label (`cf-tab-count`). */
    count?: number;
    children: ReactNode;
};

export function Tab({ className, count, children, ...rest }: TabProps) {
    return (
        <TabsPrimitive.Trigger className={cn('cf-tab', className)} {...rest}>
            {children}
            <TabCount count={count} />
        </TabsPrimitive.Trigger>
    );
}

/** The panel for one tab (`role="tabpanel"`). */
export function TabPanel(props: ComponentProps<typeof TabsPrimitive.Content>) {
    return <TabsPrimitive.Content {...props} />;
}

/* ------------------------------------------------------------- link tabs */

export type LinkTabsProps = {
    'aria-label': string;
    children: ReactNode;
    className?: string;
};

/**
 * Tabs that are page links (settings sections): a `nav` holding `cf-tabs`, with the
 * current page marked `aria-current="page"`. Arrow keys don't apply; Tab moves between links.
 */
export function LinkTabs({
    'aria-label': ariaLabel,
    children,
    className,
}: LinkTabsProps) {
    return (
        <nav aria-label={ariaLabel}>
            <div className={cn('cf-tabs', className)}>{children}</div>
        </nav>
    );
}

export type LinkTabProps = Omit<InertiaLinkProps, 'children' | 'className'> & {
    current?: boolean;
    count?: number;
    className?: string;
    children: ReactNode;
};

export function LinkTab({
    current = false,
    count,
    className,
    children,
    ...rest
}: LinkTabProps) {
    return (
        <Link
            className={cn('cf-tab', className)}
            aria-current={current ? 'page' : undefined}
            {...rest}
        >
            {children}
            <TabCount count={count} />
        </Link>
    );
}

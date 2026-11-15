import { Link } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment } from 'react';
import type { CSSProperties } from 'react';
import { Icon } from '@/components/ui/icon';
import { Tooltip } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type NavRailBadge = {
    count: number;
    /** Spoken after the label: "1 due" → "Settlements, 1 due". */
    label: string;
};

export type NavRailItemData = {
    label: string;
    icon: LucideIcon;
    /** Null renders the item disabled (module not available to this person yet). */
    href: string | null;
    current?: boolean;
    badge?: NavRailBadge;
    /** Why a disabled item can't be opened: "admins only", "coming soon". */
    disabledReason?: string;
};

const ICON_SIZE = 20;

export type NavRailItemProps = {
    item: NavRailItemData;
    /** Wrap in a tooltip (off for documentation grids). */
    tooltip?: boolean;
    /** Documentation states: `is-hover`, `is-focus`. */
    className?: string;
};

/** One round rail item: link, current page (lime), with a badge, or disabled. */
export function NavRailItem({
    item,
    tooltip = true,
    className,
}: NavRailItemProps) {
    const glyph = <Icon icon={item.icon} size={ICON_SIZE} />;

    if (item.href === null) {
        const reason = item.disabledReason ?? 'not available';

        return (
            <Tooltip label={`${item.label} · ${reason}`} disabled={!tooltip}>
                <span
                    className={cn('cf-rail-item opacity-45', className)}
                    role="link"
                    tabIndex={0}
                    aria-disabled="true"
                    aria-label={`${item.label}, ${reason}`}
                >
                    {glyph}
                </span>
            </Tooltip>
        );
    }

    const label = item.badge
        ? `${item.label}, ${item.badge.label}`
        : item.label;

    return (
        <Tooltip label={item.label} disabled={!tooltip}>
            <Link
                href={item.href}
                className={cn('cf-rail-item', className)}
                aria-label={label}
                aria-current={item.current ? 'page' : undefined}
                prefetch
            >
                {glyph}
                {item.badge && item.badge.count > 0 && (
                    <span className="cf-badge" aria-hidden="true">
                        {item.badge.count}
                    </span>
                )}
            </Link>
        </Tooltip>
    );
}

export type NavRailProps = {
    /** Module groups, separated by `cf-rail-sep`. Hide items a role can't see. */
    groups: NavRailItemData[][];
    homeHref: string;
    onSignOut: () => void;
    /**
     * 'column' (default) is the desktop rail, which wraps into a row at phone width;
     * 'row' forces the horizontal excerpt (vertical separators).
     */
    direction?: 'column' | 'row';
    /** Separator before Sign out (default true; the preview excerpt has none). */
    signOutSeparator?: boolean;
    'aria-label'?: string;
    className?: string;
    style?: CSSProperties;
};

/** Separator look for a horizontal rail (the preview's excerpt). */
const ROW_SEP = 'w-px h-5 mx-1 my-2.5';

/**
 * The pill-shaped nav rail: lime C home disc, round icon links per module with
 * tooltips, `cf-rail-sep` between groups and graphite Sign out at the end.
 */
export function NavRail({
    groups,
    homeHref,
    onSignOut,
    direction = 'column',
    signOutSeparator = true,
    className,
    style,
    ...rest
}: NavRailProps) {
    const sepClass = cn(
        'cf-rail-sep',
        direction === 'row'
            ? ROW_SEP
            : 'max-sm:mx-1 max-sm:my-2.5 max-sm:h-5 max-sm:w-px',
    );

    return (
        <nav
            className={cn('cf-rail', className)}
            aria-label={rest['aria-label'] ?? 'Main'}
            style={style}
        >
            <Link
                href={homeHref}
                className="cf-rail-logo"
                aria-label="Coffer home"
            >
                C
            </Link>
            {groups.map((group, index) => (
                <Fragment key={index}>
                    <span className={sepClass} aria-hidden="true" />
                    {group.map((item) => (
                        <NavRailItem key={item.label} item={item} />
                    ))}
                </Fragment>
            ))}
            {signOutSeparator && (
                <span className={sepClass} aria-hidden="true" />
            )}
            <Tooltip label="Sign out">
                <button
                    type="button"
                    className="cf-rail-item cf-rail-out"
                    aria-label="Sign out"
                    onClick={onSignOut}
                >
                    <Icon icon={LogOut} size={ICON_SIZE} />
                </button>
            </Tooltip>
        </nav>
    );
}

import { Link, router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { Fragment } from 'react';
import { RailTooltip } from '@/components/shell/rail-tooltip';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { isCurrent, railGroups } from '@/lib/navigation';
import type { RailItem } from '@/lib/navigation';
import { dashboard, logout } from '@/routes';

const ICON = { size: 20, strokeWidth: 1.5, 'aria-hidden': true } as const;

function RailLink({ item, current }: { item: RailItem; current: boolean }) {
    const Icon = item.icon;

    if (item.href === null) {
        const label = `${item.label}, not available yet`;

        return (
            <RailTooltip label={`${item.label} · coming soon`}>
                <span
                    className="cf-rail-item opacity-45"
                    role="link"
                    aria-disabled="true"
                    aria-label={label}
                >
                    <Icon {...ICON} />
                </span>
            </RailTooltip>
        );
    }

    return (
        <RailTooltip label={item.label}>
            <Link
                href={item.href}
                className="cf-rail-item"
                aria-label={item.label}
                aria-current={current ? 'page' : undefined}
                prefetch
            >
                <Icon {...ICON} />
            </Link>
        </RailTooltip>
    );
}

/** Pill-shaped nav rail (design-system NavRail). Wraps into a row at phone width. */
export function NavRail() {
    const { currentUrl: path } = useCurrentUrl();

    return (
        <nav className="cf-rail" aria-label="Main">
            <Link
                href={dashboard()}
                className="cf-rail-logo"
                aria-label="Coffer home"
            >
                C
            </Link>

            {railGroups().map((group, index) => (
                <Fragment key={index}>
                    <span className="cf-rail-sep" aria-hidden="true" />
                    {group.map((item) => (
                        <RailLink
                            key={item.label}
                            item={item}
                            current={isCurrent(item, path)}
                        />
                    ))}
                </Fragment>
            ))}

            <span className="cf-rail-sep" aria-hidden="true" />
            <RailTooltip label="Sign out">
                <button
                    type="button"
                    className="cf-rail-item cf-rail-out"
                    aria-label="Sign out"
                    onClick={() => {
                        router.flushAll();
                        router.post(logout());
                    }}
                >
                    <LogOut {...ICON} />
                </button>
            </RailTooltip>
        </nav>
    );
}

import type { ReactNode } from 'react';
import { ShellProvider } from '@/components/shell/shell-context';
import { signOut } from '@/components/shell/sign-out';
import { TopBar } from '@/components/shell/top-bar';
import { NavRail } from '@/components/ui/nav-rail';
import type { NavRailItemData } from '@/components/ui/nav-rail';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { isCurrent, railGroups } from '@/lib/navigation';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

/** The rail's modules with the current page marked; unreleased modules say so (D18). */
function useRailGroups(): NavRailItemData[][] {
    const { currentUrl } = useCurrentUrl();

    return railGroups().map((group) =>
        group.map((item) => ({
            label: item.label,
            icon: item.icon,
            href: item.href,
            current: isCurrent(item, currentUrl),
            disabledReason: item.href === null ? 'coming soon' : undefined,
        })),
    );
}

/**
 * Authenticated app shell (design/screens/Main.dc.html): the white shell floats
 * on the canvas, nav rail on the left, top bar above the page content.
 * Pages render their own breadcrumb + title with <PageHeader>.
 */
export default function AppLayout({
    children,
}: {
    /** Accepted for the starter kit's settings pages; the shell uses PageHeader. */
    breadcrumbs?: BreadcrumbItem[];
    children: ReactNode;
}) {
    const groups = useRailGroups();

    return (
        <ShellProvider>
            <div className="cf-page">
                <div className="cf-shell">
                    <NavRail
                        groups={groups}
                        homeHref={dashboard().url}
                        onSignOut={signOut}
                    />
                    <main className="cf-main">
                        <TopBar />
                        {children}
                    </main>
                </div>
            </div>
        </ShellProvider>
    );
}

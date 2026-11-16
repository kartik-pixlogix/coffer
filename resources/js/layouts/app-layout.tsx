import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ShellProvider } from '@/components/shell/shell-context';
import { signOut } from '@/components/shell/sign-out';
import { TopBar } from '@/components/shell/top-bar';
import { NavRail } from '@/components/ui/nav-rail';
import type { NavRailItemData } from '@/components/ui/nav-rail';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { setNumberFormat } from '@/lib/format';
import { isCurrent, railGroups } from '@/lib/navigation';
import { home } from '@/routes';
import type { BreadcrumbItem } from '@/types';

/**
 * The pages the role may open, with the current one marked; modules whose phase
 * hasn't shipped say so (D18).
 */
function useRailGroups(): NavRailItemData[][] {
    const { currentUrl } = useCurrentUrl();
    const { can, currentCompany } = usePage().props;

    return railGroups(can.pages, { jv: currentCompany?.type === 'jv' }).map(
        (group) =>
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
    const { auth } = usePage().props;

    // Money and figures in the pages below follow the viewer's number format (spec §17).
    setNumberFormat(auth.user.number_format);

    return (
        <ShellProvider>
            <div className="cf-page">
                <div className="cf-shell">
                    <NavRail
                        groups={groups}
                        homeHref={home().url}
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

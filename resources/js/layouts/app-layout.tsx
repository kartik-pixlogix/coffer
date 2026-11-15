import type { ReactNode } from 'react';
import { NavRail } from '@/components/shell/nav-rail';
import { ShellProvider } from '@/components/shell/shell-context';
import { TopBar } from '@/components/shell/top-bar';
import type { BreadcrumbItem } from '@/types';

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
    return (
        <ShellProvider>
            <div className="cf-page">
                <div className="cf-shell">
                    <NavRail />
                    <main className="cf-main">
                        <TopBar />
                        {children}
                    </main>
                </div>
            </div>
        </ShellProvider>
    );
}

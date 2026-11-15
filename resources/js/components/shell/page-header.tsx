import type { ReactNode } from 'react';
import { useShell } from '@/components/shell/shell-context';
import { Breadcrumb } from '@/components/ui/breadcrumb';

export type Crumb = {
    title: string;
    href?: string;
};

type PageHeaderProps = {
    /** The light first line of the two-tone title, e.g. "Hi Aarav," or "Settings". */
    kicker?: string;
    title: string;
    /** Crumbs after Workspace / Company. The last one is the current page. */
    crumbs?: Crumb[];
    actions?: ReactNode;
};

/**
 * Breadcrumb (Workspace / Company / …) over the two-tone page title, with the
 * page's period and primary controls on the right (cf-pagehead).
 */
export function PageHeader({
    kicker,
    title,
    crumbs = [{ title }],
    actions,
}: PageHeaderProps) {
    const { workspace, currentCompany } = useShell();

    return (
        <section className="cf-pagehead">
            <div>
                <Breadcrumb
                    items={[
                        { label: workspace.name },
                        { label: currentCompany.name },
                        ...crumbs.map((c) => ({
                            label: c.title,
                            href: c.href,
                        })),
                    ]}
                />
                <h1 className="cf-h1">
                    {kicker && (
                        <span className="t-display-light">{kicker}</span>
                    )}
                    <span className="t-display">{title}</span>
                </h1>
            </div>
            {actions && <div className="cf-actions">{actions}</div>}
        </section>
    );
}

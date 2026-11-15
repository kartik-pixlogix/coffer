import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { useShell } from '@/components/shell/shell-context';

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
    const trail: Crumb[] = [
        { title: workspace.name },
        { title: currentCompany.name },
        ...crumbs,
    ];

    return (
        <section className="cf-pagehead">
            <div>
                <nav aria-label="Breadcrumb">
                    <ol className="cf-crumbs">
                        {trail.map((crumb, index) => {
                            const last = index === trail.length - 1;

                            return (
                                <Fragment key={`${crumb.title}-${index}`}>
                                    {index > 0 && <li aria-hidden="true">/</li>}
                                    {last ? (
                                        <li aria-current="page">
                                            {crumb.title}
                                        </li>
                                    ) : (
                                        <li>
                                            {crumb.href ? (
                                                <Link href={crumb.href}>
                                                    {crumb.title}
                                                </Link>
                                            ) : (
                                                crumb.title
                                            )}
                                        </li>
                                    )}
                                </Fragment>
                            );
                        })}
                    </ol>
                </nav>
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

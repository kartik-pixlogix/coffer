import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type BreadcrumbItem = {
    label: ReactNode;
    /** Linked crumbs navigate with Inertia; leave out for plain text. Ignored on the last item. */
    href?: NonNullable<InertiaLinkProps['href']>;
};

export type BreadcrumbProps = {
    /** Group, company, section…; the last item is the current page (not linked). */
    items: BreadcrumbItem[];
    className?: string;
};

/**
 * Breadcrumb above every page title: `nav[aria-label=Breadcrumb] › ol.cf-crumbs`,
 * `/` separators hidden from assistive tech, the last item `aria-current="page"`.
 */
export function Breadcrumb({ items, className }: BreadcrumbProps) {
    const last = items.length - 1;

    return (
        <nav aria-label="Breadcrumb">
            <ol className={cn('cf-crumbs', className)}>
                {items.map((item, i) => (
                    <Fragment key={i}>
                        {i > 0 && <li aria-hidden="true">/</li>}
                        {i === last ? (
                            <li aria-current="page">{item.label}</li>
                        ) : (
                            <li>
                                {item.href !== undefined ? (
                                    <Link href={item.href}>{item.label}</Link>
                                ) : (
                                    item.label
                                )}
                            </li>
                        )}
                    </Fragment>
                ))}
            </ol>
        </nav>
    );
}

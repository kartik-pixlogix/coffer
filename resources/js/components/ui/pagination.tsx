import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { paginationRange } from '@/components/ui/pagination-range';
import { cn } from '@/lib/utils';

export { pageSummary, paginationRange } from '@/components/ui/pagination-range';
export type { PageItem } from '@/components/ui/pagination-range';

type ButtonAttrs = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'>;

export type PagerButtonProps = ButtonAttrs & {
    page: number;
    current?: boolean;
};

/** One numbered `cf-pagebtn`; the current page takes the ink fill (`aria-current="page"`). */
export function PagerButton({
    page,
    current = false,
    className,
    type = 'button',
    ...rest
}: PagerButtonProps) {
    return (
        <button
            type={type}
            className={cn('cf-pagebtn', className)}
            aria-current={current ? 'page' : undefined}
            {...rest}
        >
            {page}
        </button>
    );
}

export type PagerEdgeProps = Omit<ButtonAttrs, 'aria-label'> & {
    direction: 'previous' | 'next';
};

/** Previous / next arrow (`cf-pagebtn--edge`); disable it at the ends. */
export function PagerEdge({
    direction,
    className,
    type = 'button',
    ...rest
}: PagerEdgeProps) {
    return (
        <button
            type={type}
            className={cn('cf-pagebtn cf-pagebtn--edge', className)}
            aria-label={
                direction === 'previous' ? 'Previous page' : 'Next page'
            }
            {...rest}
        >
            <Icon
                icon={direction === 'previous' ? ChevronLeft : ChevronRight}
                size={14}
            />
        </button>
    );
}

/** The `…` between page runs; decorative. */
export function PagerEllipsis() {
    return <span aria-hidden="true">…</span>;
}

export type PagerProps = {
    /** Current page, 1-based. */
    page: number;
    pageCount: number;
    onPageChange: (page: number) => void;
    /** Pages either side of the current one (default 1). */
    siblings?: number;
    'aria-label'?: string;
    className?: string;
};

/** `nav.cf-pager`: previous, page buttons with ellipses, next. */
export function Pager({
    page,
    pageCount,
    onPageChange,
    siblings = 1,
    'aria-label': ariaLabel = 'Pagination',
    className,
}: PagerProps) {
    const items = paginationRange({ page, pageCount, siblings });

    return (
        <nav className={cn('cf-pager', className)} aria-label={ariaLabel}>
            <PagerEdge
                direction="previous"
                disabled={page <= 1}
                onClick={() => onPageChange(page - 1)}
            />
            {items.map((item, i) =>
                item === 'ellipsis' ? (
                    <PagerEllipsis key={`ellipsis-${i}`} />
                ) : (
                    <PagerButton
                        key={item}
                        page={item}
                        current={item === page}
                        onClick={() => {
                            if (item !== page) {
                                onPageChange(item);
                            }
                        }}
                    />
                ),
            )}
            <PagerEdge
                direction="next"
                disabled={page >= pageCount}
                onClick={() => onPageChange(page + 1)}
            />
        </nav>
    );
}

export type PaginationBarProps = {
    /** Left side: the range and total, e.g. `pageSummary(…)`. */
    summary?: ReactNode;
    children: ReactNode;
    className?: string;
};

/** Row under a table: summary on the left, pager on the right (`cf-kv t-caption t-muted`). */
export function PaginationBar({
    summary,
    children,
    className,
}: PaginationBarProps) {
    return (
        <div
            className={cn('cf-kv t-caption t-muted', className)}
            style={{ flexWrap: 'wrap' }}
        >
            <span>{summary}</span>
            {children}
        </div>
    );
}

export type PaginationProps = PagerProps & {
    /** "Showing 1–10 of 24 expenses in October 2026". */
    summary?: ReactNode;
};

/**
 * Pagination under any table longer than one page: the range and total on the left,
 * page buttons on the right. Hide it when `pageCount` is 1 or less.
 */
export function Pagination({ summary, className, ...pager }: PaginationProps) {
    return (
        <PaginationBar summary={summary} className={className}>
            <Pager {...pager} />
        </PaginationBar>
    );
}

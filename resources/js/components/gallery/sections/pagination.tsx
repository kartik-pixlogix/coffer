import { useState } from 'react';
import { PvNote, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import {
    PagerButton,
    PagerEdge,
    PagerEllipsis,
    Pagination,
    PaginationBar,
    pageSummary,
} from '@/components/ui/pagination';

const noop = () => {};

function LivePagination() {
    const [page, setPage] = useState(1);

    return (
        <Pagination
            page={page}
            pageCount={12}
            onPageChange={setPage}
            summary={pageSummary({
                page,
                perPage: 10,
                total: 114,
                noun: 'expenses',
            })}
        />
    );
}

export default {
    name: 'Pagination',
    group: 'Actions and navigation',
    order: 6,
    subtitle: 'Count summary plus page buttons',
    render: () => (
        <>
            {/* Forced doc states (hover on 2, focus on 3), as in preview.html. */}
            <PaginationBar summary="Showing 1–10 of 24 expenses in October 2026">
                <nav className="cf-pager" aria-label="Pagination">
                    <PagerEdge direction="previous" disabled onClick={noop} />
                    <PagerButton page={1} current />
                    <PagerButton
                        page={2}
                        style={{ background: 'var(--surface-muted)' }}
                    />
                    <PagerButton page={3} className="is-focus" />
                    <PagerEllipsis />
                    <PagerButton page={12} />
                    <PagerEdge direction="next" onClick={noop} />
                </nav>
            </PaginationBar>
            <PvNote>
                Current page uses the ink fill; 2 shows hover, 3 focus; previous
                is disabled on page 1. Keep the page size picker out unless a
                list often passes 100 rows.
            </PvNote>
            <PvState>Interactive · 12 pages</PvState>
            <LivePagination />
        </>
    ),
} satisfies GallerySection;

import { PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import {
    AppliedFilter,
    ClearFilters,
    FilterChip,
} from '@/components/ui/filter-chips';

const noop = () => {};

export default {
    name: 'FilterChips',
    group: 'Actions and navigation',
    order: 4,
    subtitle: 'Status filters with counts, and removable applied filters',
    render: () => (
        <>
            <div className="pv-row" role="group" aria-label="Status">
                <FilterChip pressed count={10}>
                    All
                </FilterChip>
                <FilterChip pressed={false} count={1} className="is-hover">
                    Pending
                </FilterChip>
                <FilterChip pressed={false} count={8} className="is-focus">
                    Approved
                </FilterChip>
                <FilterChip pressed={false} count={0} disabled>
                    Rejected
                </FilterChip>
            </div>
            <PvState>Applied filters · removable</PvState>
            <PvRow>
                <AppliedFilter
                    name="Paid by"
                    value="Kabir Shah"
                    onRemove={noop}
                />
                <AppliedFilter
                    name="Category"
                    value="Software"
                    onRemove={noop}
                />
                <ClearFilters onClick={noop} />
            </PvRow>
        </>
    ),
} satisfies GallerySection;

import { PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { SearchField } from '@/components/ui/search-field';

export default {
    name: 'SearchField',
    group: 'Forms',
    order: 10,
    subtitle: 'Global search with the ⌘K hint, and the in-page filter search',
    render: () => (
        <>
            <PvState>Top bar · default</PvState>
            <SearchField
                variant="topbar"
                aria-label="Search or jump to"
                placeholder="Search or jump to…"
                wrapClassName="max-w-none basis-auto"
            />
            <PvState>Focus with a query</PvState>
            <SearchField
                variant="topbar"
                aria-label="Search or jump to"
                defaultValue="razorpay"
                wrapClassName="max-w-none basis-auto is-focus"
            />
            <PvState>In-page filter · 40 px · disabled</PvState>
            <PvRow>
                <SearchField
                    aria-label="Search expenses"
                    placeholder="Search expenses"
                    wrapClassName="max-w-[260px]"
                />
                <SearchField
                    aria-label="Search vault"
                    placeholder="Vault locked"
                    wrapClassName="max-w-[200px]"
                    disabled
                />
            </PvRow>
        </>
    ),
} satisfies GallerySection;

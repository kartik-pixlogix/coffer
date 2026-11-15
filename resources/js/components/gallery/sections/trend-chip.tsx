import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { TrendChip } from '@/components/ui/trend-chip';

export default {
    name: 'TrendChip',
    group: 'Data and charts',
    order: 2,
    subtitle: 'Change versus the previous period, arrow plus colour',
    render: () => (
        <>
            <PvRow>
                <TrendChip percent={6} comparedTo="September" />
                <TrendChip percent={-8} comparedTo="September" />
                <TrendChip
                    percent={28}
                    tone="neutral"
                    aria-label="Up 28%, neutral for costs"
                />
                <TrendChip percent={0} />
                <TrendChip direction="up">
                    ₹6,00,000 net profit in October
                </TrendChip>
                <span
                    className="cf-skel rounded-full"
                    style={{ width: 52, height: 24 }}
                    aria-label="Loading"
                />
            </PvRow>
            <PvNote>
                Up is good for income and profit (positive), down is bad
                (negative). For costs, a rise is shown neutral rather than red,
                so the chip never shouts about normal spending.
            </PvNote>
        </>
    ),
} satisfies GallerySection;

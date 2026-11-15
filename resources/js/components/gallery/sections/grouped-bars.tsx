import { PvNote } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { GroupedBars } from '@/components/ui/grouped-bars';

/** Partner comparison, FY to date (design sample): revenue, expenses, net; paise. */
const PARTNERS = [
    { label: 'Aarav', values: [2136240_00, 1112400_00, 1023840_00] },
    { label: 'Ishita', values: [1266840_00, 544250_00, 722590_00] },
    { label: 'Kabir', values: [1763640_00, 2078000_00, -314360_00] },
    { label: 'Meera', values: [1043280_00, 633000_00, 410280_00] },
];

export default {
    name: 'GroupedBars',
    group: 'Data and charts',
    order: 10,
    subtitle:
        'Partner comparison · revenue, expenses and net, with a negative net',
    render: () => (
        <>
            <div className="cf-card">
                <GroupedBars
                    title="Partner comparison"
                    series={['Revenue', 'Expenses', 'Net']}
                    groups={PARTNERS}
                />
            </div>
            <PvNote>
                Series take chart-1, chart-2, chart-3 in that order; any bar
                below zero switches to chart-negative and gets its value written
                under the name.
            </PvNote>
        </>
    ),
} satisfies GallerySection;

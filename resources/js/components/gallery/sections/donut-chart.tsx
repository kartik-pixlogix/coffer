import type { GallerySection } from '@/components/gallery/types';
import { DonutChart } from '@/components/ui/donut-chart';

/** Revenue by partner, FY to date (design sample), paise. */
const PARTNERS = [
    { name: 'Aarav Mehta', value: 2136240_00 },
    { name: 'Ishita Rao', value: 1266840_00 },
    { name: 'Kabir Shah', value: 1763640_00 },
    { name: 'Meera Iyer', value: 1043280_00 },
];

/** Expenses by category, FY to date (Main.dc.html), paise. */
const CATEGORIES = [
    { name: 'Salary', value: 2840000_00 },
    { name: 'Software', value: 412650_00 },
    { name: 'Hardware', value: 385000_00 },
    { name: 'Marketing', value: 320000_00 },
    { name: 'Travel & office', value: 410000_00 },
];

export default {
    name: 'DonutChart',
    group: 'Data and charts',
    order: 9,
    subtitle:
        'Share of a total · partner colours, or the brand ramp for categories',
    render: () => (
        <>
            <div className="cf-card">
                <DonutChart
                    label="Revenue by partner"
                    slices={PARTNERS}
                    defaultActiveIndex={2}
                />
            </div>
            <div className="cf-card">
                <DonutChart
                    label="Expenses by category"
                    slices={CATEGORIES}
                    palette="brand"
                />
            </div>
        </>
    ),
} satisfies GallerySection;

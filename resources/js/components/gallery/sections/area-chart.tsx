import { PvNote } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { AreaChart } from '@/components/ui/area-chart';
import type { AreaChartDatum } from '@/components/ui/area-chart';

/** Design sample (Main.dc.html): Apr–Oct 2026, rupees → paise. */
const MONTHS = ['04', '05', '06', '07', '08', '09', '10'];
const INCOME = [620000, 740000, 810000, 975000, 830000, 1085000, 1150000];
const EXPENSE = [685000, 590000, 640000, 610000, 862650, 430000, 550000];

const REVENUE_VS_EXPENSES: AreaChartDatum[] = MONTHS.map((m, i) => ({
    date: `2026-${m}-01`,
    value: INCOME[i] * 100,
    comparison: EXPENSE[i] * 100,
}));

export default {
    name: 'AreaChart',
    group: 'Data and charts',
    order: 7,
    subtitle:
        'Revenue (ink line over a lime wash) against expenses (dashed) · crosshair tooltip',
    render: () => (
        <>
            <div className="cf-card">
                <AreaChart
                    title="Revenue vs expenses"
                    aria-label="Monthly revenue and expenses, April to October 2026"
                    data={REVENUE_VS_EXPENSES}
                    defaultActiveIndex={5}
                />
            </div>
            <PvNote>
                Lines are 2–2.5 px. Revenue is solid ink with a lime wash;
                expenses are a dashed muted line, so the two never depend on
                colour. The crosshair snaps to the nearest month: hover the
                chart, or focus it and use the arrow keys.
            </PvNote>
        </>
    ),
} satisfies GallerySection;

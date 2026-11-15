import { PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { ChartLegend, ChartTooltip } from '@/components/ui/chart-tooltip';

export default {
    name: 'ChartTooltip',
    group: 'Data and charts',
    order: 6,
    subtitle: 'Tooltip and legend styles shared by every chart',
    render: () => (
        <>
            <PvRow style={{ alignItems: 'flex-start' }}>
                <ChartTooltip static arrow title="Oct 2026" value="₹6,00,000" />
                <ChartTooltip
                    static
                    title="Sep 2026"
                    style={{ minWidth: 190 }}
                    rows={[
                        {
                            label: 'Revenue',
                            value: '₹10,85,000',
                            swatch: { color: 'var(--chart-1)' },
                        },
                        {
                            label: 'Expenses',
                            value: '₹4,30,000',
                            swatch: { color: 'var(--chart-2)' },
                        },
                        { label: 'Profit', value: '₹6,55,000', total: true },
                    ]}
                />
            </PvRow>
            <ChartLegend
                items={[
                    { label: 'Dot · series' },
                    { label: 'Dashed · comparison', swatch: 'line' },
                    {
                        label: 'Square · grouped bars',
                        swatch: 'sq',
                        color: 'var(--chart-1)',
                    },
                    { label: 'Below zero', color: 'var(--chart-negative)' },
                ]}
            />
            <PvRow>
                <span className="cf-axis">₹12L ₹8L ₹4L ₹0</span>
                <PvState>
                    Axis labels: IBM Plex Mono 12 px in ink-secondary, compact
                    units
                </PvState>
            </PvRow>
        </>
    ),
} satisfies GallerySection;

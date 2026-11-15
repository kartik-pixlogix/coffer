import { useState } from 'react';
import { PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { BarChart } from '@/components/ui/bar-chart';
import type { BarChartDatum } from '@/components/ui/bar-chart';

/** Profit by month from the design sample (income − expenses), rupees → paise. */
const PROFIT: BarChartDatum[] = [
    -65000, 150000, 170000, 365000, -32650, 655000, 600000,
].map((rupees, i) => ({
    date: `2026-${String(i + 4).padStart(2, '0')}-01`,
    value: rupees * 100,
}));

function BarChartDemo() {
    const [selected, setSelected] = useState(6);

    return (
        <>
            <div className="cf-card">
                <BarChart
                    title="Profit by month"
                    data={PROFIT}
                    selectedIndex={selected}
                    onSelect={setSelected}
                    labelledIndexes={[4]}
                    defaultHoveredIndex={5}
                />
            </div>
            <PvRow style={{ alignItems: 'stretch' }}>
                <div className="cf-card flex-1">
                    <PvState>Loading</PvState>
                    <BarChart data={[]} loading />
                </div>
                <div className="cf-card flex flex-1 flex-col justify-center">
                    <BarChart data={[]} />
                </div>
            </PvRow>
        </>
    );
}

export default {
    name: 'BarChart',
    group: 'Data and charts',
    order: 8,
    subtitle:
        'Profit by month · negative bars below the axis, selected bar in lime, hover tooltip',
    render: () => <BarChartDemo />,
} satisfies GallerySection;

import { ChartPie, Check, Clock, Receipt, TrendingUp } from 'lucide-react';
import { PvGrid } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { KpiTile } from '@/components/ui/kpi-tile';
import { StatusPill } from '@/components/ui/status-pill';
import { TrendChip } from '@/components/ui/trend-chip';

export default {
    name: 'KpiTile',
    group: 'Data and charts',
    order: 4,
    subtitle:
        'Lime icon chip, display title, mono figure · default, hover, selected, loading, error',
    render: () => (
        <PvGrid
            columns="repeat(2,minmax(0,1fr))"
            style={{ alignItems: 'stretch' }}
        >
            <KpiTile
                title="Total income"
                icon={TrendingUp}
                aside={<TrendChip percent={6} comparedTo="September" />}
                paise={6210000_00}
                caption="Default · FY 2026-27"
            />
            <KpiTile
                title="Net profit"
                icon={ChartPie}
                aside={<TrendChip percent={-8} comparedTo="September" />}
                paise={1842350_00}
                caption="Hover · lifts 3 px"
                className="is-hover"
            />
            <KpiTile
                title="Total hours"
                icon={Clock}
                hours={2157.5}
                caption="Selected · filters the table"
                selected
            />
            <KpiTile title="Loading" icon={Clock} loading />
            <KpiTile
                title="Pending approval"
                icon={Check}
                aside={<StatusPill tone="warn">1 expense</StatusPill>}
                paise={25000_00}
                caption="Review in Expenses"
                captionHref="#"
            />
            <KpiTile
                title="Total expenses"
                icon={Receipt}
                error
                onRetry={() => undefined}
            />
        </PvGrid>
    ),
} satisfies GallerySection;

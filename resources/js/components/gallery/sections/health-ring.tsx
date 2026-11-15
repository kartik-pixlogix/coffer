import { PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { HealthRing } from '@/components/ui/health-ring';

export default {
    name: 'HealthRing',
    group: 'Data and charts',
    order: 11,
    subtitle: 'Vault security score out of 100',
    render: () => (
        <PvRow style={{ gap: 24 }}>
            <div
                className="flex items-center gap-3 bg-graphite text-on-graphite"
                style={{ padding: 14, borderRadius: 16 }}
            >
                <HealthRing score={82} tone="dark" />
                <span className="cf-stack">
                    <span className="t-title">Vault health</span>
                    <span className="t-caption text-on-graphite-muted">
                        82 / 100 · good
                    </span>
                </span>
            </div>
            <HealthRing score={82} size="lg" />
            <HealthRing score={54} />
            <PvState>
                Graphite card · large on surface · below 60 turns warning
            </PvState>
        </PvRow>
    ),
} satisfies GallerySection;

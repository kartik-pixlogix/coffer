import { Copy } from 'lucide-react';
import { PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { IconButton } from '@/components/ui/icon-button';
import { TotpCode, TotpRing } from '@/components/ui/totp-ring';

export default {
    name: 'TotpRing',
    group: 'Data and charts',
    order: 12,
    subtitle: 'Seconds left on a one-time code · on graphite and on surface',
    render: () => (
        <>
            <div
                className="flex items-center bg-graphite text-on-graphite"
                style={{
                    gap: 14,
                    padding: '12px 12px 12px 14px',
                    borderRadius: 16,
                }}
            >
                <TotpRing secondsLeft={18} tone="dark" size={48} />
                <span className="flex flex-1 flex-col gap-0.5">
                    <span className="t-caption text-on-graphite-muted">
                        One-time code · refreshes every 30 s
                    </span>
                    <TotpCode code="482613" />
                </span>
                <IconButton
                    icon={Copy}
                    variant="glass"
                    iconSize={17}
                    aria-label="Copy one-time code"
                />
            </div>
            <PvRow>
                <TotpRing secondsLeft={24} />
                <TotpRing secondsLeft={4} />
                <TotpRing />
                <PvState>
                    On surface · last 5 seconds turn warning · live (wall clock)
                </PvState>
            </PvRow>
        </>
    ),
} satisfies GallerySection;

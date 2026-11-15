import {
    ArrowRight,
    Check,
    Info,
    Lock,
    Scale,
    TriangleAlert,
} from 'lucide-react';
import { PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { AlertBanner, AlertMoney } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import { Money } from '@/components/ui/money';

export default {
    name: 'AlertBanner',
    group: 'Feedback and overlays',
    order: 0,
    layout: 'stack',
    subtitle:
        'Settlement alert (graphite), out of balance (warning), plus negative, info and positive',
    render: () => (
        <>
            <AlertBanner
                variant="graphite"
                icon={Scale}
                aria-label="Settlement alert"
                title={
                    <>
                        Vertex Labs owes Bluefin Infotech{' '}
                        <AlertMoney paise={3_24_600_00} />
                    </>
                }
                badge={
                    <span
                        className="cf-pill cf-pill--neg cf-pill--micro"
                        style={{ height: 22 }}
                    >
                        OWES
                    </span>
                }
                action={
                    <Button variant="onDark" size="lg" disc={ArrowRight}>
                        Record transfer
                    </Button>
                }
            >
                Saarthi Portal JV · 50/50 sides · Vertex Labs is entitled to{' '}
                <Money paise={9_21_175_00} size="sm" /> and holds{' '}
                <Money paise={12_45_775_00} size="sm" />
            </AlertBanner>
            <AlertBanner
                variant="warn"
                icon={TriangleAlert}
                role="alert"
                title={
                    <>
                        Out of balance — drift <AlertMoney paise={1_250_00} />
                    </>
                }
                action={<Button variant="secondary">Find the drift</Button>}
            >
                Trial balance as of 15 Nov 2026 · FY 2026-27. Debits exceed
                credits; check the last import.
            </AlertBanner>
            <PvRow style={{ alignItems: 'stretch' }}>
                <AlertBanner
                    variant="neg"
                    slim
                    icon={Lock}
                    role="alert"
                    style={{ flex: 1 }}
                >
                    1 vault password found in a breach.
                </AlertBanner>
                <AlertBanner
                    variant="info"
                    slim
                    icon={Info}
                    style={{ flex: 1 }}
                >
                    You’re offline. Changes will sync when you reconnect.
                </AlertBanner>
                <AlertBanner
                    variant="pos"
                    slim
                    icon={Check}
                    iconStrokeWidth={2}
                    style={{ flex: 1 }}
                >
                    Balanced ✓ as of 15 Nov 2026.
                </AlertBanner>
            </PvRow>
        </>
    ),
} satisfies GallerySection;

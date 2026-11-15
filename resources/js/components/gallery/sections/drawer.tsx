import { ArrowRight, PanelRight } from 'lucide-react';
import { useState } from 'react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { Drawer, DrawerPanel } from '@/components/ui/drawer';

const media = (
    <span
        className="cf-avatar cf-avatar--lg cf-avatar--tile"
        style={{ background: 'var(--on-graphite)', color: 'var(--graphite)' }}
        aria-hidden="true"
    >
        VL
    </span>
);

const figure = (
    <>
        <span className="cf-pill cf-pill--neg cf-pill--micro">OWES</span>
        <span className="t-money" style={{ fontSize: 22, lineHeight: '26px' }}>
            ₹3,24,600
        </span>
    </>
);

function Body({ onTransfer }: { onTransfer?: () => void }) {
    return (
        <>
            <div
                className="cf-inset"
                style={{ display: 'flex', flexDirection: 'column', gap: 8 }}
            >
                <div className="cf-kv t-body">
                    <span className="t-muted">Entitled (50%)</span>
                    <span className="t-money">₹9,21,175</span>
                </div>
                <div className="cf-kv t-body">
                    <span className="t-muted">Holds</span>
                    <span className="t-money">₹12,45,775</span>
                </div>
                <div
                    className="cf-kv t-body"
                    style={{
                        borderTop: '1px solid var(--line)',
                        paddingTop: 8,
                    }}
                >
                    <span>To transfer</span>
                    <span className="t-money" style={{ fontWeight: 500 }}>
                        ₹3,24,600
                    </span>
                </div>
            </div>
            <div className="cf-actions">
                <Button
                    variant="primary"
                    disc={ArrowRight}
                    style={{ flex: 1 }}
                    onClick={onTransfer}
                >
                    Record transfer
                </Button>
                <Button>History</Button>
            </div>
        </>
    );
}

function LiveDrawer() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button icon={PanelRight} onClick={() => setOpen(true)}>
                Open settlement details
            </Button>
            <Drawer
                open={open}
                onOpenChange={setOpen}
                media={media}
                title="Vertex Labs → Bluefin Infotech"
                subtitle="Saarthi Portal JV · 50/50 sides"
                figure={figure}
            >
                <Body onTransfer={() => setOpen(false)} />
            </Drawer>
        </>
    );
}

export default {
    name: 'Drawer',
    group: 'Feedback and overlays',
    order: 5,
    subtitle:
        'Side panel with a graphite header · vault item, settlement, record details',
    render: () => (
        <>
            <DrawerPanel
                aria-label="Settlement details"
                media={media}
                title="Vertex Labs → Bluefin Infotech"
                subtitle="Saarthi Portal JV · 50/50 sides"
                figure={figure}
                style={{ width: '100%' }}
            >
                <Body />
            </DrawerPanel>
            <PvRow>
                <LiveDrawer />
                <PvNote>
                    Live: the floating variant; a scrim and focus trap below 768
                    px
                </PvNote>
            </PvRow>
        </>
    ),
} satisfies GallerySection;

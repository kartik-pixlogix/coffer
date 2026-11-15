import { KeyRound, RotateCw } from 'lucide-react';
import { useId, useState } from 'react';
import { PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { Popover, PopoverPanel } from '@/components/ui/popover';

const METER = Array.from({ length: 4 });

/** The preview's password-generator body (demo only). */
function GeneratorBody({ onUse }: { onUse?: () => void }) {
    const lengthId = useId();
    const [length, setLength] = useState(16);
    const [symbols, setSymbols] = useState(true);
    const [passphrase, setPassphrase] = useState(false);

    return (
        <>
            <div
                className="cf-inset"
                style={{
                    fontFamily: 'var(--font-mono)',
                    fontSize: 15,
                    overflowWrap: 'anywhere',
                    background: 'var(--surface-muted)',
                }}
            >
                {'k7#Qv2!mR9@xTp4w'.padEnd(length, 'x').slice(0, length)}
            </div>
            <div className="cf-field">
                <div className="cf-label-row">
                    <label className="cf-label" htmlFor={lengthId}>
                        Length
                    </label>
                    <span className="t-money-sm">{length}</span>
                </div>
                <input
                    id={lengthId}
                    type="range"
                    min={8}
                    max={64}
                    value={length}
                    onChange={(event) => setLength(Number(event.target.value))}
                    style={{ width: '100%', accentColor: 'var(--ink)' }}
                />
            </div>
            <PvRow>
                <label className="cf-switch">
                    <input
                        className="cf-sr"
                        type="checkbox"
                        role="switch"
                        checked={symbols}
                        onChange={(event) => setSymbols(event.target.checked)}
                    />
                    <span className="cf-switch-track" />
                    <span>Symbols</span>
                </label>
                <label className="cf-switch">
                    <input
                        className="cf-sr"
                        type="checkbox"
                        role="switch"
                        checked={passphrase}
                        onChange={(event) =>
                            setPassphrase(event.target.checked)
                        }
                    />
                    <span className="cf-switch-track" />
                    <span>Passphrase</span>
                </label>
            </PvRow>
            <div className="cf-field">
                <div className="cf-kv t-caption">
                    <span>Strength</span>
                    <span className="t-pos" style={{ fontWeight: 600 }}>
                        Strong · about 104 bits
                    </span>
                </div>
                <div className="cf-meter-dots" aria-hidden="true">
                    {METER.map((_, index) => (
                        <span
                            key={index}
                            style={{ background: 'var(--chart-ink)' }}
                        />
                    ))}
                </div>
            </div>
            <div className="cf-actions">
                <Button size="sm" icon={RotateCw}>
                    Regenerate
                </Button>
                <Button variant="primary" size="sm" onClick={onUse}>
                    Use password
                </Button>
            </div>
        </>
    );
}

function LivePopover() {
    const [open, setOpen] = useState(false);

    return (
        <Popover
            open={open}
            onOpenChange={setOpen}
            title="Generate password"
            trigger={
                <Button size="sm" icon={KeyRound}>
                    Open password generator
                </Button>
            }
        >
            <GeneratorBody onUse={() => setOpen(false)} />
        </Popover>
    );
}

export default {
    name: 'Popover',
    group: 'Feedback and overlays',
    order: 2,
    subtitle: 'Floating panel with controls · password generator',
    render: () => (
        <>
            <PopoverPanel title="Generate password" style={{ maxWidth: 360 }}>
                <GeneratorBody />
            </PopoverPanel>
            <PvRow>
                <LivePopover />
            </PvRow>
        </>
    ),
} satisfies GallerySection;

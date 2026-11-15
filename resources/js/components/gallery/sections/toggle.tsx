import { useState } from 'react';
import { PvGrid } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Toggle } from '@/components/ui/toggle';

const wait = (ms: number) =>
    new Promise<void>((resolve) => window.setTimeout(resolve, ms));

/** Saves for a moment, then keeps the new value. */
function SaturdaysDemo() {
    const [on, setOn] = useState(true);
    const [saving, setSaving] = useState(false);

    return (
        <Toggle
            checked={on}
            saving={saving}
            onCheckedChange={async (next) => {
                setOn(next);
                setSaving(true);
                await wait(900);
                setSaving(false);
            }}
        >
            Include Saturdays
        </Toggle>
    );
}

/** Saving fails: the switch goes back and says why. */
function TwoFactorDemo() {
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | undefined>(
        'Set up your own 2FA first.',
    );
    const [on, setOn] = useState(false);

    return (
        <Toggle
            checked={on}
            saving={saving}
            error={error}
            onCheckedChange={async (next) => {
                setOn(next);
                setError(undefined);
                setSaving(true);
                await wait(900);
                setSaving(false);
                setOn(false);
                setError('Set up your own 2FA first.');
            }}
        >
            Require 2FA
        </Toggle>
    );
}

export default {
    name: 'Toggle',
    group: 'Forms',
    order: 6,
    subtitle: 'On/off switch for settings that apply at once',
    render: () => (
        <PvGrid columns="repeat(2,minmax(0,1fr))">
            <Toggle>Off</Toggle>
            <SaturdaysDemo />
            <Toggle className="is-hover">Hover</Toggle>
            <Toggle className="is-focus" defaultChecked>
                Focus
            </Toggle>
            <Toggle disabled>Disabled off</Toggle>
            <Toggle defaultChecked disabled>
                Disabled on
            </Toggle>
            <Toggle defaultChecked saving>
                Include Saturdays
            </Toggle>
            <TwoFactorDemo />
        </PvGrid>
    ),
} satisfies GallerySection;

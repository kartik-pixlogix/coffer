import { useState } from 'react';
import { PvNote, PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { MonthGrid, MonthPicker } from '@/components/ui/month-picker';

/** Fixed so the specimens match the design (this month is Nov 2026). */
const CURRENT = '2026-11';

function Demo() {
    const [month, setMonth] = useState<string | null>('2026-10');

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            <MonthPicker
                value={month}
                onValueChange={setMonth}
                current={CURRENT}
                max={CURRENT}
            />
            {/* The popover, drawn in place to show it open. */}
            <div
                className="cf-pop"
                role="group"
                aria-label="Choose a month"
                style={{ width: 280, padding: 14 }}
            >
                <MonthGrid
                    selected={month}
                    onSelect={setMonth}
                    current={CURRENT}
                    max={CURRENT}
                />
            </div>
        </div>
    );
}

export default {
    name: 'MonthPicker',
    group: 'Forms',
    order: 14,
    subtitle: 'Months of the financial year, April to March',
    render: () => (
        <PvRow style={{ alignItems: 'flex-start' }}>
            <Demo />
            <div
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 8,
                    flex: 1,
                    minWidth: 180,
                }}
            >
                <PvState>
                    Selected Oct · current Nov ringed · future months disabled
                </PvState>
                <PvNote>
                    Used by Timesheets “Month” mode, the Expenses filter and
                    salary runs. The year step moves by financial year, not
                    calendar year.
                </PvNote>
            </div>
        </PvRow>
    ),
} satisfies GallerySection;

import { useState } from 'react';
import { PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import {
    Calendar,
    DatePicker,
    DateRangePicker,
} from '@/components/ui/date-picker';
import type { DateRange } from '@/components/ui/date-picker.utils';

/** Fixed so the specimens match the design (today is ringed on 15 Nov 2026). */
const TODAY = '2026-11-15';
const FY_OPEN_FROM = '2026-04-01';
const FY_CLOSED = 'FY 2025-26 is closed. Pick a date from 1 Apr 2026.';

function OpenDemo() {
    const [date, setDate] = useState<string | null>('2026-10-22');

    return (
        <div
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
                width: 300,
            }}
        >
            <DatePicker
                label="Date"
                value={date}
                onValueChange={setDate}
                today={TODAY}
                wrapClassName="is-focus"
            />
            {/* The popover, drawn in place to show it open. */}
            <div
                className="cf-pop cf-cal"
                role="group"
                aria-label="Choose a date"
            >
                <Calendar selected={date} today={TODAY} onSelect={setDate} />
            </div>
        </div>
    );
}

function RangeDemo() {
    const [range, setRange] = useState<DateRange>({
        start: '2026-11-10',
        end: '2026-11-13',
    });

    return (
        <Calendar
            className="max-w-[260px]"
            range={range}
            today={TODAY}
            max={TODAY}
            onSelect={(iso) =>
                setRange(
                    range.start && !range.end && iso >= range.start
                        ? { start: range.start, end: iso }
                        : { start: iso, end: null },
                )
            }
        />
    );
}

function ClosedYearDemo() {
    const [date, setDate] = useState<string | null>('2026-03-31');

    return (
        <DatePicker
            label="Error"
            value={date}
            onValueChange={setDate}
            today={TODAY}
            isDateDisabled={(iso) => iso < FY_OPEN_FROM}
            unavailableText={FY_CLOSED}
            error={date !== null && date < FY_OPEN_FROM ? FY_CLOSED : undefined}
        />
    );
}

export default {
    name: 'DatePicker',
    group: 'Forms',
    order: 13,
    subtitle:
        'Field plus calendar popover · today, selected, range, unavailable',
    render: () => (
        <PvRow style={{ alignItems: 'flex-start' }}>
            <OpenDemo />
            <div
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                    flex: 1,
                    minWidth: 200,
                }}
            >
                <PvState>
                    Range (FY report) · today ringed · locked days
                </PvState>
                <RangeDemo />
                <ClosedYearDemo />
                <DateRangePicker
                    label="Report period"
                    today={TODAY}
                    defaultValue={{ start: '2026-04-01', end: '2027-03-31' }}
                />
            </div>
        </PvRow>
    ),
} satisfies GallerySection;

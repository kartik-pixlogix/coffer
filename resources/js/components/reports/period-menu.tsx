import { ChevronDown, Clock } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-picker';
import type { DateRange } from '@/components/ui/date-picker.utils';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import type { MenuEntry } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import type {
    PeriodQuery,
    ReportPeriodProps,
    ReportPreset,
} from '@/types/reports';

/** Query for a preset of the shown fiscal year (spec §5, §15; D59). */
export function presetQuery(
    period: ReportPeriodProps,
    preset: ReportPreset,
    extra: { month?: string; from?: string; to?: string } = {},
): PeriodQuery {
    if (preset === 'month') {
        return { period: 'month', month: extra.month ?? period.month ?? null };
    }

    if (preset === 'custom') {
        return {
            period: 'custom',
            from: extra.from ?? period.from,
            to: extra.to ?? period.to,
        };
    }

    return preset === 'fy'
        ? { fy: period.fy.start }
        : { fy: period.fy.start, period: preset };
}

/** "FY to date" for the current year, "Full year" for a past one. */
export const fyPresetLabel = (period: ReportPeriodProps): string =>
    period.fy.current ? 'FY to date' : 'Full year';

/** What the period button reads: "FY to date", "Q2", "October 2026", "1 Apr – 15 Nov 2026". */
export function periodName(period: ReportPeriodProps): string {
    if (period.preset === 'fy') {
        return fyPresetLabel(period);
    }

    if (period.preset === 'month') {
        return (
            period.months.find((m) => m.value === period.month)?.label ??
            period.label
        );
    }

    if (period.preset === 'custom') {
        return period.label;
    }

    return (
        period.quarters.find((q) => q.value === period.preset)?.label ??
        period.label
    );
}

type Props = {
    period: ReportPeriodProps;
    onChange: (query: PeriodQuery) => void;
    /** Which presets the menu lists (Reports shows quarters as segments). */
    include?: { fy?: boolean; quarters?: boolean };
    /** Button text; defaults to the period's range ("1 Apr – 15 Nov 2026"). */
    label?: string;
};

/**
 * The period button (Main.dc.html "Apr – Oct 2026"): FY to date, its quarters and months,
 * or a custom range picked in a dialog.
 */
export function PeriodMenu({
    period,
    onChange,
    include = { fy: true, quarters: true },
    label,
}: Props) {
    const [customOpen, setCustomOpen] = useState(false);
    const [range, setRange] = useState<DateRange>({
        start: period.from,
        end: period.to,
    });

    const entries: MenuEntry[] = [];

    if (include.fy !== false) {
        entries.push({
            label: `${fyPresetLabel(period)} · ${period.fy.label}`,
            onSelect: () => onChange(presetQuery(period, 'fy')),
        });
    }

    if (include.quarters !== false && period.quarters.length > 0) {
        entries.push({ type: 'separator', key: 'sep-q' });
        period.quarters.forEach((q) =>
            entries.push({
                key: q.value,
                label: `${q.label} · ${q.range}`,
                onSelect: () => onChange(presetQuery(period, q.value)),
            }),
        );
    }

    if (period.months.length > 0) {
        entries.push({ type: 'separator', key: 'sep-m' });
        entries.push({ type: 'label', key: 'months', label: 'Months' });
        period.months
            .slice()
            .reverse()
            .forEach((m) =>
                entries.push({
                    key: m.value,
                    label: m.label,
                    onSelect: () =>
                        onChange(
                            presetQuery(period, 'month', { month: m.value }),
                        ),
                }),
            );
    }

    entries.push({ type: 'separator', key: 'sep-c' });
    entries.push({
        label: 'Custom range…',
        onSelect: () => {
            setRange({ start: period.from, end: period.to });
            setCustomOpen(true);
        },
    });

    const canApply = range.start !== null && range.end !== null;

    return (
        <>
            <DropdownMenu
                aria-label="Period"
                align="end"
                trigger={
                    <button
                        type="button"
                        className="cf-btn cf-btn--muted cf-btn--lg"
                        aria-label={`Period: ${period.label}`}
                    >
                        <Icon icon={Clock} size={18} />
                        {label ?? period.label}
                        <Icon icon={ChevronDown} size={16} />
                    </button>
                }
                entries={entries}
            />
            <Modal
                open={customOpen}
                onOpenChange={setCustomOpen}
                title="Custom range"
                subtitle="Figures, trends and charts follow the dates you pick."
                icon={Clock}
                size="sm"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            size="lg"
                            onClick={() => setCustomOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            size="lg"
                            disabled={!canApply}
                            onClick={() => {
                                if (range.start && range.end) {
                                    setCustomOpen(false);
                                    onChange(
                                        presetQuery(period, 'custom', {
                                            from: range.start,
                                            to: range.end,
                                        }),
                                    );
                                }
                            }}
                        >
                            Show figures
                        </Button>
                    </>
                }
            >
                <DateRangePicker
                    label="Dates"
                    value={range}
                    onValueChange={setRange}
                />
            </Modal>
        </>
    );
}

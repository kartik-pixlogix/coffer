import { CalendarDays, ChevronDown } from 'lucide-react';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import type { FiscalYearProps } from '@/types/finance';

/** The "FY 2026-27" button on finance pages: picks the fiscal year shown. */
export function FyMenu({
    fy,
    onPick,
}: {
    fy: FiscalYearProps;
    onPick: (start: number) => void;
}) {
    return (
        <DropdownMenu
            aria-label="Fiscal year"
            align="end"
            trigger={
                <button
                    type="button"
                    className="cf-btn cf-btn--muted cf-btn--lg"
                >
                    <Icon icon={CalendarDays} size={18} />
                    {fy.label}
                    <Icon icon={ChevronDown} size={16} />
                </button>
            }
            entries={fy.options
                .slice()
                .reverse()
                .map((o) => ({
                    label: o.label,
                    onSelect: () => onPick(o.value),
                }))}
        />
    );
}

/** Query values without the empty ones, for `router.get`. */
export function cleanQuery(
    query: Record<string, string | number | null | undefined>,
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(query).filter(
            (entry): entry is [string, string | number] =>
                entry[1] !== undefined && entry[1] !== null && entry[1] !== '',
        ),
    );
}

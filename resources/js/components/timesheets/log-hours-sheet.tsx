import { ChevronLeft, ChevronRight, Minus, Plus } from 'lucide-react';
import { MonthSoFar } from '@/components/timesheets/log-hours-modal';
import { useLogDraft } from '@/components/timesheets/use-log-draft';
import type { LogMode } from '@/components/timesheets/use-log-draft';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { IconButton } from '@/components/ui/icon-button';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { Sheet } from '@/components/ui/sheet';
import { Toggle } from '@/components/ui/toggle';
import { toDate, toIsoDate } from '@/lib/format';
import { formatCenti, parseHours } from '@/lib/hours';
import type { TimesheetOptions } from '@/types/timesheets';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: TimesheetOptions;
    today: string;
    /** The day picked in the week strip. */
    day: string;
    workingDays: number;
    /** The company's standard day, for the full-month warning (A4). */
    hoursPerDayCenti?: number;
};

const STEP = 50;
const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const shiftDay = (iso: string, by: number) => {
    const d = toDate(iso);
    d.setDate(d.getDate() + by);

    return toIsoDate(d);
};

/** "Today, Sun 15 Nov" · "Sat 14 Nov" */
const dayLabel = (iso: string, today: string) => {
    const d = toDate(iso);
    const text = `${DOW[d.getDay()]} ${d.getDate()} ${d.toLocaleDateString('en-IN', { month: 'short' })}`;

    return iso === today ? `Today, ${text}` : text;
};

const monthName = (ym: string) =>
    toDate(`${ym}-01`).toLocaleDateString('en-IN', { month: 'long' });

/**
 * Log hours at phone width (MobileLogHours.dc.html bottom sheet): One day with a day
 * stepper (no days after today) and ±0.5 h, or Whole month with the per-day preview.
 */
export function LogHoursSheet({
    open,
    onOpenChange,
    options,
    today,
    day,
    workingDays,
    hoursPerDayCenti,
}: Props) {
    const form = useLogDraft(
        {
            entry: null,
            members: options.members,
            defaultProjectId:
                options.projects.find((p) => p.active)?.id ?? null,
            today: day,
            workingDays,
            hoursPerDayCenti,
            defaultHours: '7.5',
        },
        open,
        () => onOpenChange(false),
    );
    const { draft, set, centi, days, errors } = form;
    const thisMonth = today.slice(0, 7);
    const lastMonth = shiftDay(`${thisMonth}-01`, -1).slice(0, 7);

    const step = (by: number) => {
        const next = Math.min(2400, Math.max(0, (centi ?? 0) + by));
        set({ hours: String(next / 100) });
    };

    const saveLabel =
        draft.mode === 'month'
            ? `Save ${formatCenti(centi ?? 0)} h for ${monthName(draft.month)}`
            : `Save ${formatCenti(centi ?? 0)} h`;

    return (
        <Sheet
            open={open}
            onOpenChange={onOpenChange}
            title="Log hours"
            footer={
                <Button
                    variant="primary"
                    size="lg"
                    className="w-full justify-center"
                    loading={form.saving}
                    disabled={!form.canSave}
                    onClick={form.save}
                >
                    {saveLabel}
                </Button>
            }
        >
            <SegmentedControl<LogMode>
                aria-label="Entry type"
                className="w-full"
                value={draft.mode}
                options={[
                    { value: 'day', label: 'One day' },
                    { value: 'month', label: 'Whole month' },
                ]}
                onValueChange={(mode) =>
                    set({
                        mode,
                        hours: mode === 'day' ? '7.5' : draft.hours,
                    })
                }
            />
            <Select
                id="ms-m"
                label="Person"
                value={draft.memberId}
                onChange={(e) => form.setMember(e.target.value)}
                options={options.members.map((m) => ({
                    value: String(m.id),
                    label: m.name,
                }))}
                error={errors.member_id}
            />

            {draft.mode === 'day' ? (
                <>
                    <div className="cf-kv">
                        <IconButton
                            icon={ChevronLeft}
                            aria-label="Previous day"
                            onClick={() =>
                                set({ date: shiftDay(draft.date, -1) })
                            }
                        />
                        <span className="t-label-strong">
                            {dayLabel(draft.date, today)}
                        </span>
                        <IconButton
                            icon={ChevronRight}
                            aria-label="Next day"
                            disabled={draft.date >= today}
                            onClick={() =>
                                set({ date: shiftDay(draft.date, 1) })
                            }
                        />
                    </div>
                    <div className="lh-stepper">
                        <button
                            type="button"
                            className="cf-iconbtn lh-step"
                            aria-label="Half an hour less"
                            disabled={(centi ?? 0) <= 0}
                            onClick={() => step(-STEP)}
                        >
                            <Minus size={22} strokeWidth={1.5} aria-hidden />
                        </button>
                        <span className="lh-stepper-value" aria-live="polite">
                            <span className="t-mono">
                                {formatCenti(centi ?? 0)}
                            </span>
                            <span className="t-caption t-muted"> h</span>
                        </span>
                        <button
                            type="button"
                            className="cf-iconbtn lh-step"
                            aria-label="Half an hour more"
                            disabled={(centi ?? 0) >= 2400}
                            onClick={() => step(STEP)}
                        >
                            <Plus size={22} strokeWidth={1.5} aria-hidden />
                        </button>
                    </div>
                    {errors.date && <FieldError>{errors.date}</FieldError>}
                </>
            ) : (
                <>
                    <Select
                        id="ms-mo"
                        label="Month"
                        value={draft.month}
                        onChange={(e) => set({ month: e.target.value })}
                        options={[thisMonth, lastMonth].map((ym) => ({
                            value: ym,
                            label: toDate(`${ym}-01`).toLocaleDateString(
                                'en-IN',
                                { month: 'long', year: 'numeric' },
                            ),
                        }))}
                        error={errors.month}
                    />
                    <div className="cf-field">
                        <label className="cf-label" htmlFor="ms-t">
                            Total hours
                        </label>
                        <span
                            className={
                                form.hoursError
                                    ? 'cf-inwrap is-error'
                                    : 'cf-inwrap'
                            }
                        >
                            <input
                                id="ms-t"
                                type="text"
                                inputMode="decimal"
                                className="t-mono"
                                style={{ fontSize: 15 }}
                                value={draft.hours}
                                onChange={(e) => set({ hours: e.target.value })}
                                aria-invalid={!!form.hoursError || undefined}
                            />
                            <span className="cf-prefix">h</span>
                        </span>
                        {form.hoursError && (
                            <FieldError>{form.hoursError}</FieldError>
                        )}
                    </div>
                    <Toggle
                        checked={draft.saturdays}
                        onCheckedChange={(saturdays) => set({ saturdays })}
                        className="lh-switch-wide"
                    >
                        Include Saturdays
                    </Toggle>
                    <div className="cf-inset t-money-sm" role="status">
                        {(parseHours(draft.hours) ?? 0) > 0
                            ? `${days} working days → ${((centi ?? 0) / 100 / days).toFixed(2)} hrs/day`
                            : `Enter the month’s total to spread it over ${days} working days.`}
                    </div>
                    <MonthSoFar
                        month={draft.month}
                        existing={form.existing}
                        totalCenti={form.monthTotalCenti}
                        fullCenti={form.fullMonthCenti}
                        over={form.overMonth}
                    />
                </>
            )}

            <Select
                id="ms-p"
                label="Project"
                value={draft.projectId}
                onChange={(e) => set({ projectId: e.target.value })}
                options={options.projects
                    .filter((p) => p.active)
                    .map((p) => ({ value: String(p.id), label: p.name }))}
                error={errors.project_id}
            />
            <Toggle
                checked={draft.billable}
                onCheckedChange={(billable) => set({ billable })}
                help="Counts toward the client’s hours"
            >
                Billable
            </Toggle>
            <div className="cf-field">
                <label className="cf-label" htmlFor="ms-n">
                    Note
                </label>
                <input
                    id="ms-n"
                    className="cf-input"
                    type="text"
                    maxLength={500}
                    placeholder="What did you work on?"
                    value={draft.notes}
                    onChange={(e) => set({ notes: e.target.value })}
                />
            </div>
        </Sheet>
    );
}

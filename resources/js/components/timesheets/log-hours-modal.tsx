import { ArrowRight, Clock, TriangleAlert } from 'lucide-react';
import { useId } from 'react';
import { useLogDraft } from '@/components/timesheets/use-log-draft';
import type { LogMode } from '@/components/timesheets/use-log-draft';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Field, FieldError, useFieldIds } from '@/components/ui/field';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { MonthPicker } from '@/components/ui/month-picker';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Toggle } from '@/components/ui/toggle';
import { formatCenti, perDayLabel } from '@/lib/hours';
import { cn } from '@/lib/utils';
import type { TimeEntry, TimesheetOptions } from '@/types/timesheets';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null: log new hours. An entry: edit it (day mode only). */
    entry: TimeEntry | null;
    options: TimesheetOptions;
    companyName: string;
    today: string;
    workingDays: number;
    /** The company's standard day (Settings › Company), for a full month's hint. */
    hoursPerDayCenti?: number;
};

/**
 * "Log hours" (Timesheets.dc.html modal, spec §8.1). Day: a date and hours. Month: the
 * month's total spread over its working days, with "Include Saturdays" and a live
 * per-day preview. Hours accept 7.5 or 7:30. Also edits a single entry.
 */
export function LogHoursModal({
    open,
    onOpenChange,
    entry,
    options,
    companyName,
    today,
    workingDays,
    hoursPerDayCenti,
}: Props) {
    const internal = options.projects.find(
        (p) => p.active && p.name === 'Internal',
    );
    const form = useLogDraft(
        {
            entry,
            members: options.members,
            defaultProjectId:
                options.projects.find((p) => p.active && p !== internal)?.id ??
                internal?.id ??
                null,
            today,
            workingDays,
        },
        open,
        () => onOpenChange(false),
    );
    const { draft, set, centi, days, errors } = form;

    const hoursIds = useFieldIds(
        undefined,
        { help: true, error: form.hoursError ?? undefined },
        undefined,
    );
    const modeLabel = useId();

    const editing = entry !== null;
    const memberOptions = options.members.map((m) => ({
        value: String(m.id),
        label: m.name,
    }));

    // An edit can keep a member who has since been deactivated.
    if (entry && !options.members.some((m) => m.id === entry.memberId)) {
        memberOptions.push({
            value: String(entry.memberId),
            label: entry.memberName,
        });
    }

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? 'Edit hours' : 'Log hours'}
            subtitle={companyName}
            icon={Clock}
            dirty
            footer={
                <>
                    <Button
                        variant="secondary"
                        size="lg"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={ArrowRight}
                        loading={form.saving}
                        disabled={!form.canSave}
                        onClick={form.save}
                    >
                        {editing
                            ? 'Save entry'
                            : `Log ${centi === null ? '' : `${formatCenti(centi)} `}h`}
                    </Button>
                </>
            }
        >
            <div className="lh-row">
                <Select
                    id="lh-m"
                    label="Member"
                    value={draft.memberId}
                    onChange={(e) => form.setMember(e.target.value)}
                    options={memberOptions}
                    placeholder={
                        memberOptions.length === 0
                            ? 'No active members'
                            : undefined
                    }
                    error={errors.member_id}
                />
                {!editing && (
                    <div className="cf-field">
                        <span className="cf-label" id={modeLabel}>
                            Log by
                        </span>
                        <SegmentedControl<LogMode>
                            aria-labelledby={modeLabel}
                            value={draft.mode}
                            options={[
                                { value: 'day', label: 'Day' },
                                { value: 'month', label: 'Month' },
                            ]}
                            onValueChange={(mode) => set({ mode })}
                        />
                    </div>
                )}
            </div>

            <div className="wiz-grid-2">
                {draft.mode === 'month' ? (
                    <div className="cf-field">
                        <span className="cf-label">Month</span>
                        <MonthPicker
                            aria-label="Month"
                            value={draft.month}
                            onValueChange={(month) => set({ month })}
                            max={today.slice(0, 7)}
                            className="w-full justify-start"
                        />
                        {errors.month && (
                            <FieldError>{errors.month}</FieldError>
                        )}
                    </div>
                ) : (
                    <DatePicker
                        id="lh-d"
                        label="Date"
                        value={draft.date}
                        onValueChange={(date) => date && set({ date })}
                        error={errors.date}
                    />
                )}
                <Field
                    ids={hoursIds}
                    label={draft.mode === 'month' ? 'Total hours' : 'Hours'}
                    help={
                        draft.mode === 'month'
                            ? hoursPerDayCenti
                                ? `A full month is ${formatCenti(days * hoursPerDayCenti)} h. Accepts 57.33 or 57:20`
                                : 'Accepts 57.33 or 57:20'
                            : 'Accepts 7.5 or 7:30'
                    }
                    error={form.hoursError ?? undefined}
                >
                    <span
                        className={cn(
                            'cf-inwrap',
                            hoursIds.invalid && 'is-error',
                        )}
                    >
                        <input
                            id={hoursIds.id}
                            type="text"
                            inputMode="decimal"
                            autoComplete="off"
                            className="t-mono"
                            style={{ fontSize: 15 }}
                            value={draft.hours}
                            onChange={(e) => set({ hours: e.target.value })}
                            aria-invalid={hoursIds.invalid || undefined}
                            aria-describedby={hoursIds.describedBy}
                        />
                        <span className="t-caption t-muted">
                            {draft.mode === 'month' ? 'h' : 'h:mm'}
                        </span>
                    </span>
                </Field>
            </div>

            {draft.mode === 'month' && (
                <div className="cf-inset lh-sat">
                    <Toggle
                        checked={draft.saturdays}
                        onCheckedChange={(saturdays) => set({ saturdays })}
                    >
                        Include Saturdays
                    </Toggle>
                    <span
                        role="status"
                        className="t-money-sm"
                        style={{ fontSize: 13 }}
                    >
                        {perDayLabel(centi ?? 0, days)}
                    </span>
                </div>
            )}

            {form.longDay && (
                <p className="cf-help lh-warn" role="status">
                    <Icon icon={TriangleAlert} size={14} />
                    More than 12 h in a day. Check it’s right before logging.
                </p>
            )}

            <div className="wiz-grid-2">
                <Select
                    id="lh-p"
                    label="Partner"
                    value={draft.partnerId}
                    onChange={(e) => set({ partnerId: e.target.value })}
                    options={options.partners.map((p) => ({
                        value: String(p.id),
                        label: p.name,
                    }))}
                    help="Filled from the member; change it for shared work."
                    error={errors.partner_id}
                />
                <Select
                    id="lh-j"
                    label="Project"
                    value={draft.projectId}
                    onChange={(e) => set({ projectId: e.target.value })}
                    options={options.projects
                        .filter(
                            (p) => p.active || String(p.id) === draft.projectId,
                        )
                        .map((p) => ({ value: String(p.id), label: p.name }))}
                    placeholder="Pick a project"
                    error={errors.project_id}
                />
            </div>

            <Toggle
                checked={draft.billable}
                onCheckedChange={(billable) => set({ billable })}
            >
                Billable
            </Toggle>

            <Textarea
                id="lh-n"
                label="Notes"
                value={draft.notes}
                rows={2}
                limit={500}
                placeholder="What did they work on?"
                onChange={(e) => set({ notes: e.target.value })}
                error={errors.notes}
            />
        </Modal>
    );
}

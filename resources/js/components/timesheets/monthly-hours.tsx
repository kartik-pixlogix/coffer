import { router } from '@inertiajs/react';
import { ArrowRight, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { ImportFooter } from '@/components/timesheets/import-steps';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { IconButton } from '@/components/ui/icon-button';
import { MonthPicker } from '@/components/ui/month-picker';
import { Toggle } from '@/components/ui/toggle';
import { formatCenti, parseHours, SATURDAY, workingDays } from '@/lib/hours';
import { monthLong } from '@/lib/team';
import { cn } from '@/lib/utils';
import {
    index as timesheetsIndex,
    monthly as saveMonthly,
} from '@/routes/timesheets';
import type { TimesheetOptions } from '@/types/timesheets';

type Row = {
    key: number;
    memberId: string;
    hours: string;
    projectId: string;
    billable: boolean;
};

type Props = {
    options: TimesheetOptions;
    today: string;
    workingDays: number;
};

/**
 * "Monthly hours by hand" (BulkImport.dc.html second tab, spec §8.2): a month total per
 * member, each spread over the month's working days like month mode.
 */
export function MonthlyHours({ options, today, workingDays: mask }: Props) {
    const defaultProject = String(
        options.projects.find((p) => p.name !== 'Internal')?.id ??
            options.projects[0]?.id ??
            '',
    );
    const [month, setMonth] = useState(today.slice(0, 7));
    const [saturdays, setSaturdays] = useState((mask & SATURDAY) !== 0);
    const [rows, setRows] = useState<Row[]>(() => [
        {
            key: 1,
            memberId: '',
            hours: '',
            projectId: defaultProject,
            billable: true,
        },
    ]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const [year, monthNumber] = month.split('-').map(Number);
    const days = workingDays(
        year,
        monthNumber - 1,
        saturdays ? mask | SATURDAY : mask & ~SATURDAY,
    ).length;
    const total = rows.reduce((sum, r) => sum + (parseHours(r.hours) ?? 0), 0);
    const used = new Set(rows.map((r) => r.memberId));
    const canSave =
        rows.length > 0 &&
        rows.every(
            (r) =>
                r.memberId !== '' &&
                r.projectId !== '' &&
                (parseHours(r.hours) ?? 0) >= 25,
        );

    const update = (key: number, patch: Partial<Row>) =>
        setRows((rs) =>
            rs.map((r) => (r.key === key ? { ...r, ...patch } : r)),
        );

    const save = () =>
        router.post(
            saveMonthly().url,
            {
                month,
                saturdays,
                rows: rows.map((r) => ({
                    member_id: Number(r.memberId),
                    hours: r.hours.trim(),
                    project_id: Number(r.projectId),
                    billable: r.billable,
                })),
            },
            {
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: setErrors,
            },
        );

    const nameOf = (r: Row) =>
        options.members.find((m) => String(m.id) === r.memberId)?.name ??
        `row ${rows.indexOf(r) + 1}`;

    return (
        <>
            <section
                className="cf-card flex flex-col gap-3.5"
                aria-labelledby="mh-h"
            >
                <div className="cf-kv flex-wrap gap-3">
                    <h2 id="mh-h" className="cf-dot-title t-title">
                        Monthly hours · {monthLong(`${month}-01`)}
                    </h2>
                    <div className="cf-row flex-wrap gap-3">
                        <MonthPicker
                            aria-label="Month"
                            value={month}
                            max={today.slice(0, 7)}
                            onValueChange={setMonth}
                        />
                        <Toggle
                            checked={saturdays}
                            onCheckedChange={setSaturdays}
                        >
                            Include Saturdays ({days} working days)
                        </Toggle>
                    </div>
                </div>
                <div className="cf-table-wrap">
                    <div
                        className="cf-table min-w-[840px]"
                        role="table"
                        aria-label="Monthly hours"
                    >
                        <div className="cf-tr cf-thead rcols" role="row">
                            <span role="columnheader">Member</span>
                            <span role="columnheader" className="cf-num">
                                Total hours
                            </span>
                            <span role="columnheader">Per day</span>
                            <span role="columnheader">Project</span>
                            <span role="columnheader">Billable</span>
                            <span role="columnheader">
                                <span className="cf-sr">Remove</span>
                            </span>
                        </div>
                        {rows.map((r, i) => {
                            const centi = parseHours(r.hours);
                            const hoursError = errors[`rows.${i}.hours`];

                            return (
                                <div
                                    key={r.key}
                                    className="cf-tr rcols"
                                    role="row"
                                >
                                    <span role="cell">
                                        <select
                                            className="cf-select h-10"
                                            aria-label={`Member for row ${i + 1}`}
                                            value={r.memberId}
                                            onChange={(e) =>
                                                update(r.key, {
                                                    memberId: e.target.value,
                                                })
                                            }
                                        >
                                            <option value="">
                                                Choose a member
                                            </option>
                                            {options.members
                                                .filter(
                                                    (m) =>
                                                        String(m.id) ===
                                                            r.memberId ||
                                                        !used.has(String(m.id)),
                                                )
                                                .map((m) => (
                                                    <option
                                                        key={m.id}
                                                        value={m.id}
                                                    >
                                                        {m.name}
                                                    </option>
                                                ))}
                                        </select>
                                        {errors[`rows.${i}.member_id`] && (
                                            <FieldError>
                                                {errors[`rows.${i}.member_id`]}
                                            </FieldError>
                                        )}
                                    </span>
                                    <span role="cell">
                                        <span
                                            className={cn(
                                                'cf-inwrap cf-inwrap--sm',
                                                hoursError && 'is-error',
                                            )}
                                        >
                                            <input
                                                type="text"
                                                inputMode="decimal"
                                                className="t-mono text-right"
                                                value={r.hours}
                                                aria-label={`Total hours for ${nameOf(r)}`}
                                                aria-invalid={
                                                    hoursError
                                                        ? true
                                                        : undefined
                                                }
                                                onChange={(e) =>
                                                    update(r.key, {
                                                        hours: e.target.value,
                                                    })
                                                }
                                            />
                                            <span className="t-caption t-muted">
                                                h
                                            </span>
                                        </span>
                                        {hoursError && (
                                            <FieldError>
                                                {hoursError}
                                            </FieldError>
                                        )}
                                    </span>
                                    <span
                                        role="cell"
                                        className="t-money-sm text-[13px]"
                                    >
                                        {centi !== null && days > 0
                                            ? `${(centi / 100 / days).toFixed(2)} h/day`
                                            : '—'}
                                    </span>
                                    <span role="cell">
                                        <select
                                            className="cf-select h-10"
                                            aria-label={`Project for ${nameOf(r)}`}
                                            value={r.projectId}
                                            onChange={(e) =>
                                                update(r.key, {
                                                    projectId: e.target.value,
                                                })
                                            }
                                        >
                                            {options.projects.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </select>
                                    </span>
                                    <span role="cell">
                                        <button
                                            type="button"
                                            className={cn(
                                                'cf-pill imp-billable',
                                                r.billable && 'cf-pill--info',
                                            )}
                                            aria-pressed={r.billable}
                                            aria-label={`Billable for ${nameOf(r)}`}
                                            onClick={() =>
                                                update(r.key, {
                                                    billable: !r.billable,
                                                })
                                            }
                                        >
                                            {r.billable
                                                ? 'Billable'
                                                : 'Non-billable'}
                                        </button>
                                    </span>
                                    <span role="cell" className="cf-rowacts">
                                        <IconButton
                                            icon={X}
                                            size="sm"
                                            variant="bare"
                                            aria-label={`Remove ${nameOf(r)}`}
                                            disabled={rows.length === 1}
                                            onClick={() =>
                                                setRows((rs) =>
                                                    rs.filter(
                                                        (x) => x.key !== r.key,
                                                    ),
                                                )
                                            }
                                        />
                                    </span>
                                </div>
                            );
                        })}
                        <div className="cf-tr is-total rcols" role="row">
                            <span role="cell">Total</span>
                            <span role="cell" className="t-money cf-num">
                                {formatCenti(total)} h
                            </span>
                            <span role="cell" />
                            <span role="cell" />
                            <span role="cell" />
                            <span role="cell" />
                        </div>
                    </div>
                </div>
                <Button
                    variant="secondary"
                    size="sm"
                    icon={Plus}
                    className="self-start"
                    disabled={
                        used.size > options.members.length ||
                        rows.length >= options.members.length
                    }
                    onClick={() =>
                        setRows((rs) => [
                            ...rs,
                            {
                                key: Date.now(),
                                memberId: '',
                                hours: '',
                                projectId: defaultProject,
                                billable: true,
                            },
                        ])
                    }
                >
                    Add row
                </Button>
                {(errors.month || errors.date || errors.rows) && (
                    <FieldError>
                        {errors.month ?? errors.date ?? errors.rows}
                    </FieldError>
                )}
            </section>
            <ImportFooter
                back={
                    <Button
                        variant="secondary"
                        size="lg"
                        onClick={() => router.visit(timesheetsIndex().url)}
                    >
                        Cancel
                    </Button>
                }
            >
                <Button
                    variant="primary"
                    size="lg"
                    disc={ArrowRight}
                    disabled={!canSave}
                    loading={saving}
                    onClick={save}
                >
                    Save {formatCenti(total)} h
                </Button>
            </ImportFooter>
        </>
    );
}

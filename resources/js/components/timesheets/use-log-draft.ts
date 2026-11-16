import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    LONG_DAY_CENTI,
    MAX_ENTRY_CENTI,
    MIN_ENTRY_CENTI,
    SATURDAY,
    parseHours,
    workingDays,
} from '@/lib/hours';
import { store as logHours, update as updateEntry } from '@/routes/timesheets';
import type { MemberOption, TimeEntry } from '@/types/timesheets';

export type LogMode = 'day' | 'month';

export type LogDraft = {
    memberId: string;
    mode: LogMode;
    /** "YYYY-MM-DD" (day mode). */
    date: string;
    /** "YYYY-MM" (month mode). */
    month: string;
    hours: string;
    saturdays: boolean;
    partnerId: string;
    projectId: string;
    billable: boolean;
    notes: string;
};

type Setup = {
    entry: TimeEntry | null;
    members: MemberOption[];
    defaultProjectId: number | null;
    today: string;
    /** The company's working-day mask (Mon–Fri = 31). */
    workingDays: number;
    /** Starting hours text: "57.33" (desktop month) or "7.5" (phone day). */
    defaultHours?: string;
    defaultMode?: LogMode;
};

function start(setup: Setup): LogDraft {
    const { entry, members, defaultProjectId, today } = setup;
    const member = entry
        ? members.find((m) => m.id === entry.memberId)
        : members[0];

    return {
        memberId: String(entry?.memberId ?? member?.id ?? ''),
        mode: entry ? 'day' : (setup.defaultMode ?? 'day'),
        date: entry?.date ?? today,
        month: today.slice(0, 7),
        hours: entry
            ? String(entry.hoursCenti / 100)
            : (setup.defaultHours ?? ''),
        saturdays: (setup.workingDays & SATURDAY) !== 0,
        partnerId: String(entry?.partnerId ?? member?.partnerId ?? ''),
        projectId: String(entry?.projectId ?? defaultProjectId ?? ''),
        billable: entry?.billable ?? true,
        notes: entry?.notes ?? '',
    };
}

/**
 * The Log hours form shared by the desktop modal and the phone sheet (spec §8.1):
 * draft state, the month preview ("21 working days → 2.73 hrs/day"), client checks
 * that mirror the server, and saving (new entry, month batch or an edit).
 */
export function useLogDraft(setup: Setup, open: boolean, onDone: () => void) {
    const [draft, setDraft] = useState<LogDraft>(() => start(setup));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [shownFor, setShownFor] = useState<string | null>(null);

    // Each opening starts from the entry (edit) or the defaults.
    const key = open ? String(setup.entry?.id ?? 'new') : null;

    if (key !== shownFor) {
        setShownFor(key);
        setDraft(start(setup));
        setErrors({});
    }

    const set = (patch: Partial<LogDraft>) =>
        setDraft((d) => ({ ...d, ...patch }));

    /** Picking a member fills their partner ("Filled from the member"). */
    const setMember = (memberId: string) => {
        const member = setup.members.find((m) => String(m.id) === memberId);
        set({
            memberId,
            partnerId: member ? String(member.partnerId) : draft.partnerId,
        });
    };

    const centi = parseHours(draft.hours);
    const [year, monthNumber] = draft.month.split('-').map(Number);
    const mask = draft.saturdays
        ? setup.workingDays | SATURDAY
        : setup.workingDays & ~SATURDAY;
    const days =
        draft.mode === 'month' && year && monthNumber
            ? workingDays(year, monthNumber - 1, mask).length
            : 1;

    let hoursError: string | null = null;

    if (draft.hours.trim() !== '' && centi === null) {
        hoursError = 'Enter hours like 2.5 or 2:30.';
    } else if (centi !== null && centi < MIN_ENTRY_CENTI) {
        hoursError = 'Hours can’t be less than 0.25.';
    } else if (centi !== null && centi > MAX_ENTRY_CENTI * days) {
        hoursError =
            draft.mode === 'month'
                ? `That’s more than 24 h a day over ${days} working days.`
                : 'Hours can’t be more than 24 in a day.';
    }

    const longDay =
        draft.mode === 'day' &&
        centi !== null &&
        centi > LONG_DAY_CENTI &&
        hoursError === null;

    const canSave =
        draft.memberId !== '' &&
        draft.projectId !== '' &&
        centi !== null &&
        hoursError === null;

    const save = () => {
        const payload = {
            member_id: Number(draft.memberId),
            ...(setup.entry ? {} : { mode: draft.mode }),
            date: draft.mode === 'day' ? draft.date : null,
            month: draft.mode === 'month' ? draft.month : null,
            saturdays: draft.saturdays,
            hours: draft.hours.trim(),
            partner_id: draft.partnerId ? Number(draft.partnerId) : null,
            project_id: Number(draft.projectId),
            billable: draft.billable,
            notes: draft.notes.trim() || null,
        };
        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: onDone,
            onError: (bag: Record<string, string>) => setErrors(bag),
        };

        if (setup.entry) {
            router.put(updateEntry(setup.entry.id).url, payload, options);
        } else {
            router.post(logHours().url, payload, options);
        }
    };

    return {
        draft,
        set,
        setMember,
        centi,
        days,
        hoursError: hoursError ?? errors.hours ?? null,
        longDay,
        errors,
        saving,
        canSave,
        save,
    };
}

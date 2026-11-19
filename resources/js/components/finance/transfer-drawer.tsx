import { router } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { useState } from 'react';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DatePicker } from '@/components/ui/date-picker';
import { Drawer } from '@/components/ui/drawer';
import { Icon } from '@/components/ui/icon';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { formatInr } from '@/lib/format';
import {
    store as storeTransfer,
    update as updateTransfer,
} from '@/routes/settlements';
import type {
    SettlementTransferRow,
    SideSettlementRow,
    SuggestedTransfer,
} from '@/types/finance';

type Draft = {
    fromId: string;
    toId: string;
    amount: number | null;
    date: string;
    reference: string;
};

export type TransferTarget =
    | { mode: 'new'; suggestion: SuggestedTransfer | null }
    | { mode: 'edit'; transfer: SettlementTransferRow };

/**
 * Record transfer (Settlements.dc.html panel, spec §13): from side → to side (they must
 * differ), amount, date and a bank reference, with what is owed now and what is left
 * after this transfer. An amount above what is owed needs "Record anyway" ticked first
 * (sent as `confirm_over`, F7 / D134).
 * Editing a recorded transfer reverses it and posts it again.
 */
export function TransferDrawer({
    target,
    onClose,
    company,
    sides,
    suggested,
    today,
}: {
    target: TransferTarget | null;
    onClose: () => void;
    company: string;
    sides: SideSettlementRow[];
    suggested: SuggestedTransfer[];
    today: string;
}) {
    const [draft, setDraft] = useState<Draft | null>(null);
    const [shownFor, setShownFor] = useState<TransferTarget | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [confirmOver, setConfirmOver] = useState(false);

    if (target !== shownFor) {
        setShownFor(target);
        setErrors({});
        setConfirmOver(false);

        if (target?.mode === 'edit') {
            const t = target.transfer;
            setDraft({
                fromId: String(t.fromId),
                toId: String(t.toId),
                amount: t.amountPaise,
                date: t.date,
                reference: t.reference ?? '',
            });
        } else if (target) {
            const s = target.suggestion;
            const from = s?.fromId ?? sides[0]?.id;
            const to = s?.toId ?? sides.find((x) => x.id !== from)?.id;
            setDraft({
                fromId: String(from ?? ''),
                toId: String(to ?? ''),
                amount: s?.amountPaise ?? null,
                date: today,
                reference: '',
            });
        }
    }

    if (target === null || draft === null) {
        return null;
    }

    const set = (patch: Partial<Draft>) =>
        setDraft((d) => (d ? { ...d, ...patch } : d));
    const name = (id: string) =>
        sides.find((s) => String(s.id) === id)?.name ?? '';
    const editing = target.mode === 'edit' ? target.transfer : null;

    // What the "from" side owes the "to" side now (for an edit: before this transfer).
    const pair = suggested.find(
        (s) =>
            String(s.fromId) === draft.fromId && String(s.toId) === draft.toId,
    );
    const sameAsEdited =
        editing !== null &&
        String(editing.fromId) === draft.fromId &&
        String(editing.toId) === draft.toId;
    const owed =
        (pair?.amountPaise ?? 0) +
        (sameAsEdited ? (editing?.amountPaise ?? 0) : 0);
    const amount = draft.amount ?? 0;
    const left = owed - amount;
    const same = draft.fromId === draft.toId;
    const over = amount > 0 && left < 0;

    const help =
        owed === 0
            ? `${name(draft.fromId)} doesn’t owe ${name(draft.toId)} anything right now.`
            : left < 0
              ? `That’s more than is owed. The most you’d need to record is ${formatInr(owed)}.`
              : left === 0
                ? 'This settles the sides in full.'
                : `A part payment leaves ${formatInr(left)} to settle later.`;

    const save = () => {
        const payload = {
            from_side_id: draft.fromId,
            to_side_id: draft.toId,
            amount_paise: amount,
            date: draft.date,
            reference: draft.reference.trim() || null,
            confirm_over: over && confirmOver,
        };
        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: onClose,
            onError: (bag: Record<string, string>) => setErrors(bag),
        };

        if (editing) {
            router.put(updateTransfer(editing.id).url, payload, options);
        } else {
            router.post(storeTransfer().url, payload, options);
        }
    };

    const sideOptions = sides.map((s) => ({
        value: String(s.id),
        label: s.name,
    }));

    return (
        <Drawer
            open
            onOpenChange={(o) => !o && onClose()}
            title={editing ? 'Edit transfer' : 'Record transfer'}
            subtitle={`${company} · settles sides`}
            closeLabel="Close"
            style={{ width: 440, bottom: 'auto' }}
            figure={
                <span className="cf-row gap-2.5">
                    <span className="cf-pill st-side-pill">
                        {name(draft.fromId)}
                    </span>
                    <Icon icon={ArrowRight} size={18} />
                    <span className="cf-pill st-side-pill">
                        {name(draft.toId)}
                    </span>
                </span>
            }
        >
            <div className="wiz-grid-2">
                <Select
                    id="rt-f"
                    label="From"
                    value={draft.fromId}
                    onChange={(e) => set({ fromId: e.target.value })}
                    options={sideOptions}
                    error={errors.from_side_id}
                />
                <Select
                    id="rt-to"
                    label="To"
                    value={draft.toId}
                    onChange={(e) => set({ toId: e.target.value })}
                    options={sideOptions}
                    error={
                        errors.to_side_id ??
                        (same
                            ? 'From and to must be different sides.'
                            : undefined)
                    }
                />
            </div>
            <AmountInput
                id="rt-a"
                label="Amount"
                value={draft.amount}
                onValueChange={(value) => set({ amount: value })}
                help={left < 0 ? undefined : help}
                error={errors.amount_paise ?? (left < 0 ? help : undefined)}
            />
            <div className="wiz-grid-2">
                <DatePicker
                    id="rt-d"
                    label="Date"
                    value={draft.date}
                    onValueChange={(date) => date && set({ date })}
                    error={errors.date}
                />
                <TextInput
                    id="rt-r"
                    label="Reference"
                    placeholder="UTR or IMPS ref"
                    value={draft.reference}
                    maxLength={100}
                    className="font-mono"
                    onChange={(e) => set({ reference: e.target.value })}
                    error={errors.reference}
                />
            </div>
            <div className="cf-inset flex flex-col gap-1.5">
                <div className="cf-kv t-body">
                    <span className="t-muted">Owed now</span>
                    <span className="t-money">{formatInr(owed)}</span>
                </div>
                <div className="cf-kv t-body">
                    <span className="t-muted">This transfer</span>
                    <span className="t-money">{formatInr(amount)}</span>
                </div>
                <div className="cf-kv t-body st-left">
                    <span>Left to settle</span>
                    <span className="t-money font-semibold">
                        {formatInr(Math.max(left, 0))}
                    </span>
                </div>
            </div>
            {over && (
                <Checkbox
                    checked={confirmOver}
                    onCheckedChange={setConfirmOver}
                    error={errors.confirm_over}
                >
                    Record anyway: {formatInr(-left)} more than is owed
                </Checkbox>
            )}
            <div className="cf-actions">
                <Button variant="secondary" size="lg" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    variant="primary"
                    size="lg"
                    disc={Check}
                    loading={saving}
                    disabled={amount <= 0 || same || (over && !confirmOver)}
                    onClick={save}
                    className="flex-1 justify-between"
                >
                    {editing ? 'Save transfer' : `Record ${formatInr(amount)}`}
                </Button>
            </div>
        </Drawer>
    );
}

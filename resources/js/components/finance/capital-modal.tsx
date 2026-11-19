import { router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Check } from 'lucide-react';
import { useState } from 'react';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DatePicker } from '@/components/ui/date-picker';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatInr } from '@/lib/format';
import { store as storeMovement } from '@/routes/capital-accounts';
import type {
    CapitalStatementRow,
    FinancePartner,
    MoneyAccountOption,
} from '@/types/finance';

export type CapitalKind = 'contribution' | 'drawing';

type Props = {
    kind: CapitalKind | null;
    onClose: () => void;
    partners: FinancePartner[];
    accounts: MoneyAccountOption[];
    statement: CapitalStatementRow[];
    today: string;
};

type Draft = {
    partnerId: string;
    amount: number | null;
    date: string;
    accountId: string;
    note: string;
};

const COPY: Record<
    CapitalKind,
    { title: string; subtitle: string; account: string; save: string }
> = {
    contribution: {
        title: 'Record contribution',
        subtitle: 'Money a partner puts into the company',
        account: 'Into',
        save: 'Record contribution',
    },
    drawing: {
        title: 'Record drawing',
        subtitle: 'Money a partner takes out of the company',
        account: 'From',
        save: 'Record drawing',
    },
};

/**
 * Record contribution / Record drawing (CapitalAccounts.dc.html modal, spec §11):
 * partner, amount, date, the money account (the partner's side account by default) and
 * a note, with the partner's closing capital after it.
 */
export function CapitalModal({
    kind,
    onClose,
    partners,
    accounts,
    statement,
    today,
}: Props) {
    const [draft, setDraft] = useState<Draft | null>(null);
    const [shownFor, setShownFor] = useState<CapitalKind | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [confirmOverdraw, setConfirmOverdraw] = useState(false);
    const active = partners.filter((p) => p.active);

    const accountFor = (partnerId: string): string => {
        const p = partners.find((x) => String(x.id) === partnerId);
        const side = p?.sideId
            ? accounts.find((a) => a.sideId === p.sideId)
            : undefined;

        return String(
            (side ?? accounts.find((a) => a.sideId === null) ?? accounts[0])
                ?.id ?? '',
        );
    };

    if (kind !== shownFor) {
        setShownFor(kind);
        setErrors({});
        setConfirmOverdraw(false);

        if (kind) {
            const first = String(active[0]?.id ?? '');
            setDraft({
                partnerId: first,
                amount: null,
                date: today,
                accountId: accountFor(first),
                note: '',
            });
        }
    }

    if (kind === null || draft === null) {
        return null;
    }

    const copy = COPY[kind];
    const set = (patch: Partial<Draft>) =>
        setDraft((d) => (d ? { ...d, ...patch } : d));
    const partner = active.find((p) => String(p.id) === draft.partnerId);
    const closing =
        statement.find((r) => String(r.partnerId) === draft.partnerId)
            ?.closingPaise ?? 0;
    const amount = draft.amount ?? 0;
    const after = kind === 'contribution' ? closing + amount : closing - amount;
    const amountBad = errors.amount_paise !== undefined;
    // A drawing past the partner's capital takes their account below zero: it needs
    // a tick first, sent as `confirm_overdraw` (D134).
    const overdraw = kind === 'drawing' && amount > 0 && after < 0;

    const save = () => {
        if (amount <= 0) {
            setErrors({ amount_paise: 'Enter an amount above ₹0.' });

            return;
        }

        router.post(
            storeMovement().url,
            {
                kind,
                partner_id: draft.partnerId,
                amount_paise: amount,
                date: draft.date,
                money_account_id: draft.accountId,
                note: draft.note.trim() || null,
                confirm_overdraw: overdraw && confirmOverdraw,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: onClose,
                onError: (bag) => setErrors(bag),
            },
        );
    };

    return (
        <Modal
            open
            onOpenChange={(open) => !open && onClose()}
            title={copy.title}
            subtitle={copy.subtitle}
            icon={kind === 'contribution' ? ArrowDownRight : ArrowUpRight}
            size="sm"
            style={{ width: 520 }}
            dirty
            footer={
                <>
                    <Button variant="secondary" size="lg" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={Check}
                        loading={saving}
                        disabled={overdraw && !confirmOverdraw}
                        onClick={save}
                    >
                        {copy.save}
                    </Button>
                </>
            }
        >
            <Select
                id="rc-p"
                label="Partner"
                value={draft.partnerId}
                onChange={(e) =>
                    set({
                        partnerId: e.target.value,
                        accountId: accountFor(e.target.value),
                    })
                }
                options={active.map((p) => ({
                    value: String(p.id),
                    label: p.name,
                }))}
                error={errors.partner_id}
            />
            <div className="wiz-grid-2">
                <AmountInput
                    id="rc-a"
                    label="Amount"
                    value={draft.amount}
                    onValueChange={(value) => {
                        set({ amount: value });

                        if (amountBad) {
                            setErrors((e) => {
                                const next = { ...e };
                                delete next.amount_paise;

                                return next;
                            });
                        }
                    }}
                    error={errors.amount_paise}
                />
                <DatePicker
                    id="rc-d"
                    label="Date"
                    value={draft.date}
                    onValueChange={(date) => date && set({ date })}
                    error={errors.date}
                />
            </div>
            <Select
                id="rc-m"
                label={copy.account}
                value={draft.accountId}
                onChange={(e) => set({ accountId: e.target.value })}
                options={accounts.map((a) => ({
                    value: String(a.id),
                    label: a.name,
                }))}
                error={errors.money_account_id}
            />
            <Textarea
                id="rc-n"
                label="Note"
                placeholder="Optional"
                value={draft.note}
                maxLength={500}
                style={{ minHeight: 72 }}
                onChange={(e) => set({ note: e.target.value })}
                error={errors.note}
            />
            <div
                className="cf-inset"
                style={{ background: 'var(--surface-muted)' }}
            >
                <div className="cf-kv t-body">
                    <span className="t-muted">
                        {partner?.name ?? 'Partner'} closing after this
                    </span>
                    <span className="t-money" style={{ fontWeight: 600 }}>
                        {formatInr(after)}
                    </span>
                </div>
            </div>
            {overdraw && (
                <Checkbox
                    checked={confirmOverdraw}
                    onCheckedChange={setConfirmOverdraw}
                    error={errors.confirm_overdraw}
                >
                    Record anyway: {partner?.name ?? 'the partner'}’s capital
                    goes below zero
                </Checkbox>
            )}
        </Modal>
    );
}

import { router } from '@inertiajs/react';
import { Check, Coins, CreditCard, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { LoanModal } from '@/components/finance/loan-modals';
import { AmountInput } from '@/components/ui/amount-input';
import { Avatar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DatePicker } from '@/components/ui/date-picker';
import { IconButton } from '@/components/ui/icon-button';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { formatDate, formatInr } from '@/lib/format';
import { destroy as destroyPayout } from '@/routes/expenses/reimbursements';
import { reimburse } from '@/routes/expenses';
import type { PersonalCardOwed, PersonalCardsPanel } from '@/types/finance';

type PayDraft = {
    amount: number | null;
    date: string;
    accountId: string;
    note: string;
};

/**
 * "Owed for personal cards" on Expenses (D50): who the company owes for spending on
 * their own card (from the books), with Pay out (a reimbursement from a money
 * account) and, for partners, Convert to loan (spec §4.2, §12), plus the latest
 * payouts with undo. Shown to people who can manage capital and loans.
 */
export function PersonalCards({
    panel,
    today,
}: {
    panel: PersonalCardsPanel;
    today: string;
}) {
    const [paying, setPaying] = useState<PersonalCardOwed | null>(null);
    const [converting, setConverting] = useState<PersonalCardOwed | null>(null);
    const [undoing, setUndoing] = useState<
        PersonalCardsPanel['recent'][number] | null
    >(null);
    const [busy, setBusy] = useState(false);
    const total = panel.owed.reduce((s, o) => s + o.owedPaise, 0);

    if (panel.owed.length === 0 && panel.recent.length === 0) {
        return null;
    }

    return (
        <section className="cf-card ex-cards" aria-labelledby="ex-cards-h">
            <div className="cf-kv flex-wrap gap-2">
                <h2 id="ex-cards-h" className="cf-dot-title t-title">
                    Owed for personal cards
                </h2>
                <span className="t-body t-muted">
                    {panel.owed.length === 0
                        ? 'Everyone is paid back'
                        : `${formatInr(total)} to ${panel.owed.length} ${panel.owed.length === 1 ? 'person' : 'people'}`}
                </span>
            </div>
            {panel.owed.map((o) => (
                <div key={o.key} className="cf-inset ex-card-row">
                    <span className="cf-row min-w-0 gap-2.5">
                        <Avatar
                            name={o.name}
                            size="sm"
                            partner={
                                o.colour
                                    ? (o.colour as PartnerColour)
                                    : undefined
                            }
                            label={false}
                        />
                        <span className="cf-stack min-w-0">
                            <span className="t-label-strong">{o.name}</span>
                            <span className="t-caption t-muted">
                                {o.type === 'partner'
                                    ? 'Partner · paid on their card'
                                    : `Team member${o.billedTo ? ` · billed to ${o.billedTo}` : ''}`}
                            </span>
                        </span>
                    </span>
                    <span className="t-money text-right font-medium">
                        {formatInr(o.owedPaise)}
                    </span>
                    <span className="cf-row justify-end gap-2">
                        {o.type === 'partner' && (
                            <Button
                                variant="ghost"
                                size="sm"
                                icon={Coins}
                                onClick={() => setConverting(o)}
                            >
                                Convert to loan
                            </Button>
                        )}
                        <Button
                            variant="secondary"
                            size="sm"
                            aria-label={`Pay out ${formatInr(o.owedPaise)} to ${o.name}`}
                            onClick={() => setPaying(o)}
                        >
                            Pay out
                        </Button>
                    </span>
                </div>
            ))}
            {panel.recent.length > 0 && (
                <details className="ex-payouts">
                    <summary className="t-label-strong">Recent payouts</summary>
                    {panel.recent.map((r) => (
                        <div key={r.id} className="ex-payout">
                            <span className="t-body t-muted">
                                {formatDate(r.date)}
                            </span>
                            <span className="t-body">
                                {r.name}{' '}
                                <span className="t-caption t-muted">
                                    from {r.fromName}
                                </span>
                            </span>
                            <span className="t-money text-right">
                                {formatInr(r.amountPaise)}
                            </span>
                            <IconButton
                                icon={Trash2}
                                size="sm"
                                aria-label={`Undo the ${formatInr(r.amountPaise)} payout to ${r.name}`}
                                onClick={() => setUndoing(r)}
                            />
                        </div>
                    ))}
                </details>
            )}

            <PayoutModal
                payee={paying}
                onClose={() => setPaying(null)}
                accounts={panel.accounts}
                today={today}
            />
            <LoanModal
                open={converting !== null}
                onClose={() => setConverting(null)}
                partners={panel.owed
                    .filter((o) => o.type === 'partner')
                    .map((o) => ({
                        id: o.id,
                        name: o.name,
                        colour: o.colour ?? 1,
                        sideId: null,
                        active: true,
                        owedPaise: o.owedPaise,
                        accountId: o.accountId,
                    }))}
                accounts={panel.accounts}
                today={today}
                preset={
                    converting
                        ? {
                              partnerId: converting.id,
                              source: 'card',
                              amountPaise: converting.owedPaise,
                          }
                        : null
                }
            />
            <ConfirmDialog
                open={undoing !== null}
                onOpenChange={(o) => !o && setUndoing(null)}
                title="Undo this payout?"
                description={
                    undoing
                        ? `${formatInr(undoing.amountPaise)} to ${undoing.name} on ${formatDate(undoing.date)}. The entry is reversed and the amount is owed again.`
                        : undefined
                }
                icon={Trash2}
                confirmLabel="Undo payout"
                loading={busy}
                onConfirm={() => {
                    if (!undoing) {
                        return;
                    }

                    router.delete(destroyPayout(undoing.id).url, {
                        preserveScroll: true,
                        onStart: () => setBusy(true),
                        onFinish: () => {
                            setBusy(false);
                            setUndoing(null);
                        },
                    });
                }}
            />
        </section>
    );
}

/** Pay out: amount (all of it by default, never more), date, paid from and a note. */
function PayoutModal({
    payee,
    onClose,
    accounts,
    today,
}: {
    payee: PersonalCardOwed | null;
    onClose: () => void;
    accounts: PersonalCardsPanel['accounts'];
    today: string;
}) {
    const [draft, setDraft] = useState<PayDraft | null>(null);
    const [shownFor, setShownFor] = useState<PersonalCardOwed | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    if (payee !== shownFor) {
        setShownFor(payee);
        setErrors({});
        setDraft(
            payee
                ? {
                      amount: payee.owedPaise,
                      date: today,
                      accountId: String(payee.accountId),
                      note: '',
                  }
                : null,
        );
    }

    if (payee === null || draft === null) {
        return null;
    }

    const set = (patch: Partial<PayDraft>) =>
        setDraft((d) => (d ? { ...d, ...patch } : d));
    const amount = draft.amount ?? 0;
    const over = amount > payee.owedPaise;

    const save = () =>
        router.post(
            reimburse().url,
            {
                payee: payee.key,
                amount_paise: amount,
                date: draft.date,
                money_account_id: draft.accountId,
                note: draft.note.trim() || null,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: onClose,
                onError: (bag) => setErrors(bag),
            },
        );

    return (
        <Modal
            open
            onOpenChange={(o) => !o && onClose()}
            title={`Pay back ${payee.name}`}
            subtitle={`The company owes ${formatInr(payee.owedPaise)} for card spending`}
            icon={CreditCard}
            size="sm"
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
                        disabled={amount <= 0 || over}
                        onClick={save}
                    >
                        Pay {formatInr(amount)}
                    </Button>
                </>
            }
        >
            <div className="wiz-grid-2">
                <AmountInput
                    id="po-a"
                    label="Amount"
                    value={draft.amount}
                    onValueChange={(value) => set({ amount: value })}
                    error={
                        errors.amount_paise ??
                        (over
                            ? `That’s more than the ${formatInr(payee.owedPaise)} owed.`
                            : undefined)
                    }
                />
                <DatePicker
                    id="po-d"
                    label="Paid on"
                    value={draft.date}
                    onValueChange={(date) => date && set({ date })}
                    error={errors.date}
                />
            </div>
            <Select
                id="po-f"
                label="Paid from"
                value={draft.accountId}
                onChange={(e) => set({ accountId: e.target.value })}
                options={accounts.map((a) => ({
                    value: String(a.id),
                    label: a.name,
                }))}
                error={errors.money_account_id}
            />
            <TextInput
                id="po-n"
                label="Note"
                placeholder="Optional, e.g. NEFT reference"
                value={draft.note}
                maxLength={500}
                onChange={(e) => set({ note: e.target.value })}
                error={errors.note ?? errors.payee}
            />
        </Modal>
    );
}

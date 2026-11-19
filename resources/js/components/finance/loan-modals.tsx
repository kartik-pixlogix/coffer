import { router } from '@inertiajs/react';
import { Check, Coins, Pencil, Plus } from 'lucide-react';
import { useId, useState } from 'react';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { FieldError } from '@/components/ui/field';
import { Modal } from '@/components/ui/modal';
import { ProgressBar } from '@/components/ui/progress-bar';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { countOf, formatInr } from '@/lib/format';
import { firstName } from '@/lib/team';
import {
    repay as repayRoute,
    store as storeLoan,
    update as updateLoan,
} from '@/routes/partner-loans';
import type { LoanRow, MoneyAccountOption } from '@/types/finance';

export type LoanPartner = {
    id: number;
    name: string;
    colour: number;
    sideId: number | null;
    active: boolean;
    /** What the company owes them for personal-card spending. */
    owedPaise: number;
    /** Their side's account (the company account in a Single company). */
    accountId: number;
};

type Errors = Record<string, string>;

const visit = (
    setSaving: (v: boolean) => void,
    setErrors: (e: Errors) => void,
    onDone: () => void,
) => ({
    preserveScroll: true,
    onStart: () => setSaving(true),
    onFinish: () => setSaving(false),
    onSuccess: onDone,
    onError: (bag: Errors) => setErrors(bag),
});

/* ------------------------------------------------------------ record loan */

type LoanDraft = {
    partnerId: string;
    source: 'account' | 'card';
    amount: number | null;
    date: string;
    accountId: string;
    purpose: string;
    instalment: number | null;
    dueOn: string | null;
};

/**
 * Record loan (spec §12, D51): the lending partner, where the money came from (new
 * money into an account, or their personal-card balance converted into a loan, spec
 * §4.2), amount, date, purpose and an optional monthly instalment or due date. The
 * design has no board for it, so it follows the repayment modal's look.
 */
export function LoanModal({
    open,
    onClose,
    partners,
    accounts,
    today,
    preset,
}: {
    open: boolean;
    onClose: () => void;
    partners: LoanPartner[];
    accounts: MoneyAccountOption[];
    today: string;
    /** Open on a partner's personal-card balance (Expenses' "Convert to loan"). */
    preset?: { partnerId: number; source: 'card'; amountPaise: number } | null;
}) {
    const [draft, setDraft] = useState<LoanDraft | null>(null);
    const [shown, setShown] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [saving, setSaving] = useState(false);
    const sourceLabel = useId();
    const active = partners.filter((p) => p.active);

    if (open !== shown) {
        setShown(open);
        setErrors({});

        if (open) {
            const p =
                active.find((x) => x.id === preset?.partnerId) ?? active[0];
            setDraft({
                partnerId: String(p?.id ?? ''),
                source: preset?.source ?? 'account',
                amount: preset?.amountPaise ?? null,
                date: today,
                accountId: String(p?.accountId ?? accounts[0]?.id ?? ''),
                purpose: '',
                instalment: null,
                dueOn: null,
            });
        }
    }

    if (!open || draft === null) {
        return null;
    }

    const set = (patch: Partial<LoanDraft>) =>
        setDraft((d) => (d ? { ...d, ...patch } : d));
    const partner = active.find((p) => String(p.id) === draft.partnerId);
    const owed = partner?.owedPaise ?? 0;
    const amount = draft.amount ?? 0;
    const overOwed = draft.source === 'card' && amount > owed;

    const save = () =>
        router.post(
            storeLoan().url,
            {
                partner_id: draft.partnerId,
                source: draft.source,
                amount_paise: amount,
                date: draft.date,
                money_account_id:
                    draft.source === 'account' ? draft.accountId : null,
                purpose: draft.purpose.trim() || null,
                instalment_paise: draft.instalment,
                due_on: draft.dueOn,
            },
            visit(setSaving, setErrors, onClose),
        );

    return (
        <Modal
            open
            onOpenChange={(o) => !o && onClose()}
            title="Record loan"
            subtitle="Money a partner lends the company"
            icon={Coins}
            dirty
            footer={
                <>
                    <Button variant="secondary" size="lg" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={Plus}
                        loading={saving}
                        disabled={amount <= 0 || overOwed}
                        onClick={save}
                    >
                        Record loan
                    </Button>
                </>
            }
        >
            <Select
                id="rl-p"
                label="Lending partner"
                value={draft.partnerId}
                onChange={(e) => {
                    const p = active.find(
                        (x) => String(x.id) === e.target.value,
                    );
                    set({
                        partnerId: e.target.value,
                        accountId: String(p?.accountId ?? draft.accountId),
                        source:
                            draft.source === 'card' && (p?.owedPaise ?? 0) > 0
                                ? 'card'
                                : 'account',
                    });
                }}
                options={active.map((p) => ({
                    value: String(p.id),
                    label: p.name,
                }))}
                error={errors.partner_id}
            />
            <div className="cf-field">
                <span className="cf-label" id={sourceLabel}>
                    Where the money came from
                </span>
                <SegmentedControl<'account' | 'card'>
                    aria-labelledby={sourceLabel}
                    value={draft.source}
                    onValueChange={(source) => set({ source })}
                    options={[
                        { value: 'account', label: 'New money' },
                        {
                            value: 'card',
                            label: 'Personal-card balance',
                            disabled: owed <= 0,
                        },
                    ]}
                />
                <span className="cf-help">
                    {draft.source === 'card'
                        ? `The company owes ${partner ? firstName(partner.name) : 'them'} ${formatInr(owed)} for card spending. This turns it into a loan; no money moves.`
                        : owed > 0
                          ? `${partner ? firstName(partner.name) : 'They'} also ${partner ? 'has' : 'have'} ${formatInr(owed)} of card spending that could become a loan.`
                          : 'Money paid into one of the company’s accounts.'}
                </span>
                {errors.source && <FieldError>{errors.source}</FieldError>}
            </div>
            <div className="wiz-grid-2">
                <AmountInput
                    id="rl-a"
                    label="Amount"
                    value={draft.amount}
                    onValueChange={(value) => set({ amount: value })}
                    error={
                        errors.amount_paise ??
                        (overOwed
                            ? `That’s more than the ${formatInr(owed)} owed.`
                            : undefined)
                    }
                />
                <DatePicker
                    id="rl-d"
                    label="Date"
                    value={draft.date}
                    onValueChange={(date) => date && set({ date })}
                    error={errors.date}
                />
            </div>
            {draft.source === 'account' && (
                <Select
                    id="rl-m"
                    label="Received into"
                    value={draft.accountId}
                    onChange={(e) => set({ accountId: e.target.value })}
                    options={accounts.map((a) => ({
                        value: String(a.id),
                        label: a.name,
                    }))}
                    error={errors.money_account_id}
                />
            )}
            <TextInput
                id="rl-n"
                label="What it’s for"
                placeholder="Optional, e.g. salaries before milestone 2"
                value={draft.purpose}
                maxLength={200}
                onChange={(e) => set({ purpose: e.target.value })}
                error={errors.purpose}
            />
            <div className="wiz-grid-2">
                <AmountInput
                    id="rl-i"
                    label="Monthly instalment"
                    value={draft.instalment}
                    onValueChange={(instalment) => set({ instalment })}
                    help="Optional"
                    error={errors.instalment_paise}
                />
                <DatePicker
                    id="rl-due"
                    label="Due by"
                    value={draft.dueOn}
                    onValueChange={(dueOn) => set({ dueOn })}
                    help="Optional"
                    error={errors.due_on}
                />
            </div>
        </Modal>
    );
}

/* ------------------------------------------------------- record repayment */

type RepayDraft = {
    loanId: number;
    amount: number | null;
    date: string;
    accountId: string;
    note: string;
};

const suggest = (loan: LoanRow) =>
    loan.instalmentPaise
        ? Math.min(loan.instalmentPaise, loan.outstandingPaise)
        : loan.outstandingPaise;

/**
 * Record repayment (PartnerLoans.dc.html modal, spec §12): the open loan, its borrowed /
 * repaid / outstanding figures, amount (Pay in full; never more than outstanding),
 * paid on, paid from, a note, and the progress after this payment.
 */
export function RepaymentModal({
    loanId,
    onClose,
    loans,
    accounts,
    today,
}: {
    loanId: number | null;
    onClose: () => void;
    loans: LoanRow[];
    accounts: MoneyAccountOption[];
    today: string;
}) {
    const [draft, setDraft] = useState<RepayDraft | null>(null);
    const [shownFor, setShownFor] = useState<number | null>(null);
    const [errors, setErrors] = useState<Errors>({});
    const [saving, setSaving] = useState(false);
    const loanLabel = useId();
    const open = loans.filter((l) => l.outstandingPaise > 0);

    const draftFor = (loan: LoanRow): RepayDraft => ({
        loanId: loan.id,
        amount: suggest(loan),
        date: today,
        accountId: String(loan.fromAccountId),
        note: '',
    });

    if (loanId !== shownFor) {
        setShownFor(loanId);
        setErrors({});
        const loan = open.find((l) => l.id === loanId);
        setDraft(loan ? draftFor(loan) : null);
    }

    const loan = open.find((l) => l.id === draft?.loanId);

    if (loanId === null || draft === null || !loan) {
        return null;
    }

    const set = (patch: Partial<RepayDraft>) =>
        setDraft((d) => (d ? { ...d, ...patch } : d));
    const amount = draft.amount ?? 0;
    const amountError =
        errors.amount_paise ??
        (!amount
            ? 'Enter the amount paid back.'
            : amount > loan.outstandingPaise
              ? `That’s more than the ${formatInr(loan.outstandingPaise)} outstanding.`
              : undefined);
    const after = Math.max(0, loan.outstandingPaise - amount);
    const afterPct = Math.min(
        100,
        Math.round(
            ((loan.repaidPaise + Math.min(amount, loan.outstandingPaise)) /
                loan.amountPaise) *
                100,
        ),
    );

    const save = () =>
        router.post(
            repayRoute(loan.id).url,
            {
                amount_paise: amount,
                date: draft.date,
                money_account_id: draft.accountId,
                note: draft.note.trim() || null,
            },
            visit(setSaving, setErrors, onClose),
        );

    return (
        <Modal
            open
            onOpenChange={(o) => !o && onClose()}
            title="Record repayment"
            subtitle="Money the company paid back to a partner"
            icon={Coins}
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
                        disabled={
                            amountError !== undefined && !errors.amount_paise
                        }
                        onClick={save}
                    >
                        Record repayment
                    </Button>
                </>
            }
        >
            <div className="cf-field">
                <span className="cf-label" id={loanLabel}>
                    Loan
                </span>
                <SegmentedControl<string>
                    aria-labelledby={loanLabel}
                    value={String(loan.id)}
                    onValueChange={(id) => {
                        const next = open.find((l) => String(l.id) === id);

                        if (next) {
                            setErrors({});
                            setDraft(draftFor(next));
                        }
                    }}
                    options={open.map((l) => ({
                        value: String(l.id),
                        label: `${l.code} · ${firstName(l.name)}`,
                    }))}
                />
            </div>
            <div className="ln-figs">
                <div className="cf-inset cf-stack">
                    <span className="t-caption t-muted">Borrowed</span>
                    <span className="t-money">
                        {formatInr(loan.amountPaise)}
                    </span>
                </div>
                <div className="cf-inset cf-stack">
                    <span className="t-caption t-muted">Repaid so far</span>
                    <span className="t-money">
                        {formatInr(loan.repaidPaise)}
                    </span>
                </div>
                <div className="cf-inset cf-stack">
                    <span className="t-caption t-muted">Outstanding</span>
                    <span className="t-money font-medium">
                        {formatInr(loan.outstandingPaise)}
                    </span>
                </div>
            </div>
            <div className="wiz-grid-2">
                <AmountInput
                    id="rp-a"
                    label="Amount"
                    labelAction={
                        <button
                            type="button"
                            className="cf-link t-caption"
                            onClick={() =>
                                set({ amount: loan.outstandingPaise })
                            }
                        >
                            Pay in full
                        </button>
                    }
                    value={draft.amount}
                    onValueChange={(value) => {
                        set({ amount: value });
                        setErrors((e) => {
                            const next = { ...e };
                            delete next.amount_paise;

                            return next;
                        });
                    }}
                    help={loan.terms}
                    error={amountError}
                />
                <DatePicker
                    id="rp-d"
                    label="Paid on"
                    value={draft.date}
                    onValueChange={(date) => date && set({ date })}
                    error={errors.date}
                />
            </div>
            <Select
                id="rp-f"
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
                id="rp-n"
                label="Note"
                placeholder="Optional"
                value={draft.note}
                maxLength={500}
                onChange={(e) => set({ note: e.target.value })}
                error={errors.note ?? errors.loan_id}
            />
            <div className="cf-inset flex flex-col gap-2">
                <div className="cf-kv">
                    <span className="t-label-strong">After this payment</span>
                    <span className="t-money">
                        {formatInr(after)} outstanding
                    </span>
                </div>
                <ProgressBar
                    value={afterPct}
                    variant="lime"
                    aria-label="Repaid after this payment"
                />
                <span className="t-caption t-muted">
                    {after === 0
                        ? `${loan.code} closes once this is recorded.`
                        : `${afterPct}% of ${formatInr(loan.amountPaise)} repaid to ${loan.name}.`}
                </span>
            </div>
        </Modal>
    );
}

/* -------------------------------------------------------------- edit loan */

type EditDraft = {
    amount: number | null;
    date: string;
    purpose: string;
    instalment: number | null;
    dueOn: string | null;
};

const editDraftFor = (loan: LoanRow): EditDraft => ({
    amount: loan.amountPaise,
    date: loan.date,
    purpose: loan.purpose ?? '',
    instalment: loan.instalmentPaise,
    dueOn: loan.dueOn,
});

/**
 * Edit loan (A6, D138; no board, so it follows Record loan): what it's for and the
 * repayment terms can always change; the amount and date only while nothing has been
 * repaid, and changing them reverses the loan's entry and posts it again. The lender
 * and where the money came from are fixed (delete and record again).
 */
export function EditLoanModal({
    loan,
    onClose,
}: {
    loan: LoanRow | null;
    onClose: () => void;
}) {
    const [draft, setDraft] = useState<EditDraft | null>(null);
    const [shownFor, setShownFor] = useState<number | null>(null);
    const [errors, setErrors] = useState<Errors>({});
    const [saving, setSaving] = useState(false);

    if ((loan?.id ?? null) !== shownFor) {
        setShownFor(loan?.id ?? null);
        setErrors({});
        setDraft(loan ? editDraftFor(loan) : null);
    }

    if (loan === null || draft === null) {
        return null;
    }

    const set = (patch: Partial<EditDraft>) =>
        setDraft((d) => (d ? { ...d, ...patch } : d));
    const fixed = loan.repaymentsCount > 0;
    const amount = draft.amount ?? 0;
    const purpose = draft.purpose.trim();
    const reposts = amount !== loan.amountPaise || draft.date !== loan.date;
    const changed =
        reposts ||
        purpose !== (loan.purpose ?? '') ||
        draft.instalment !== loan.instalmentPaise ||
        draft.dueOn !== loan.dueOn;
    const source = loan.converted
        ? 'converted from personal-card spending'
        : loan.intoName
          ? `received into ${loan.intoName}`
          : 'new money';

    const save = () =>
        router.put(
            updateLoan(loan.id).url,
            {
                amount_paise: amount,
                date: draft.date,
                purpose: purpose || null,
                instalment_paise: draft.instalment,
                due_on: draft.dueOn,
            },
            visit(setSaving, setErrors, onClose),
        );

    return (
        <Modal
            open
            onOpenChange={(o) => !o && onClose()}
            title="Edit loan"
            subtitle={`${loan.code} · ${loan.name}`}
            icon={Pencil}
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
                        disabled={amount <= 0 || !changed}
                        onClick={save}
                    >
                        Save changes
                    </Button>
                </>
            }
        >
            <div className="wiz-grid-2">
                <AmountInput
                    id="el-a"
                    label="Amount"
                    value={draft.amount}
                    disabled={fixed}
                    onValueChange={(value) => set({ amount: value })}
                    help={
                        fixed
                            ? `Fixed: ${countOf(loan.repaymentsCount, 'repayment')} recorded`
                            : undefined
                    }
                    error={errors.amount_paise}
                />
                <DatePicker
                    id="el-d"
                    label="Date"
                    value={draft.date}
                    disabled={fixed}
                    onValueChange={(date) => date && set({ date })}
                    help={fixed ? 'Fixed while it has repayments' : undefined}
                    error={errors.date}
                />
            </div>
            <TextInput
                id="el-n"
                label="What it’s for"
                placeholder="Optional, e.g. salaries before milestone 2"
                value={draft.purpose}
                maxLength={200}
                onChange={(e) => set({ purpose: e.target.value })}
                error={errors.purpose}
            />
            <div className="wiz-grid-2">
                <AmountInput
                    id="el-i"
                    label="Monthly instalment"
                    value={draft.instalment}
                    onValueChange={(instalment) => set({ instalment })}
                    help="Optional"
                    error={errors.instalment_paise}
                />
                <DatePicker
                    id="el-due"
                    label="Due by"
                    value={draft.dueOn}
                    onValueChange={(dueOn) => set({ dueOn })}
                    help="Optional"
                    error={errors.due_on}
                />
            </div>
            <p className="cf-help m-0" role="status">
                {reposts
                    ? `Saving reverses ${loan.code}’s entry and posts it again with the new amount and date.`
                    : `Lent by ${loan.name}, ${source}. To change the lender or where the money came from, delete the loan and record it again.`}
            </p>
        </Modal>
    );
}

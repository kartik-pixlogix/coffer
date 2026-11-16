import {
    ArrowRight,
    Check,
    Landmark,
    Lock,
    Plus,
    Receipt,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useId, useState } from 'react';
import {
    ATTACH_ACCEPT,
    MAX_ATTACHMENT,
} from '@/components/finance/income-modal';
import {
    defaultAccount,
    defaultFrom,
    fromOptions,
    gap,
    halves,
    payerError,
    whoOptions,
} from '@/components/finance/expense-form';
import type { MeProps, PayerDraft } from '@/components/finance/expense-form';
import { useExpenseForm } from '@/components/finance/use-expense-form';
import { AmountInput } from '@/components/ui/amount-input';
import { StatusPill } from '@/components/ui/status-pill';
import { Button, TextButton } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { FieldError } from '@/components/ui/field';
import { FileDrop } from '@/components/ui/file-drop';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Modal } from '@/components/ui/modal';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { Toggle } from '@/components/ui/toggle';
import { formatInr } from '@/lib/format';
import type { ExpenseOptions, ExpenseRow, PayerMode } from '@/types/finance';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    expense: ExpenseRow | null;
    options: ExpenseOptions;
    me: MeProps;
    company: { name: string };
    abilities: { addCategory: boolean; ownOnly: boolean };
    today: string;
};

const MODES: { value: PayerMode; label: string }[] = [
    { value: 'full', label: 'Full' },
    { value: 'half', label: 'Half' },
    { value: 'custom', label: 'Custom' },
    { value: 'company', label: 'Direct company' },
];

/**
 * "Add expense" (Expenses.dc.html modal, spec §10): description, amount, date, category
 * with "+ Add category", who paid (Full, Half, Custom with a live total, or straight
 * from the company account), each line's paid-from (an account or a personal card),
 * project and "Bill to a client", notes and the receipt. It goes to partners for
 * approval; editing an approved one (Admins) sends it back.
 */
export function ExpenseModal({
    open,
    onOpenChange,
    expense,
    options,
    me,
    company,
    abilities,
    today,
}: Props) {
    const form = useExpenseForm({
        open,
        expense,
        options,
        me,
        ownOnly: abilities.ownOnly,
        today,
        onDone: () => onOpenChange(false),
    });
    const { draft, set, errors } = form;
    const [addingCat, setAddingCat] = useState(false);
    const [catText, setCatText] = useState('');
    const catLabel = useId();
    const paidLabel = useId();

    const keepWho = expense?.payers.map((p) =>
        p.type === 'company'
            ? 'company'
            : `${p.type}:${p.type === 'partner' ? p.partnerId : p.memberId}`,
    );
    const keepFrom = expense?.payers
        .filter((p) => p.accountId)
        .map((p) => `account:${p.accountId}`);
    const people = whoOptions(options, me, abilities.ownOnly, keepWho).filter(
        (o) => o.value !== 'company',
    );
    const everyone = whoOptions(options, me, abilities.ownOnly, keepWho);
    const categories = options.categories.filter(
        (c) => (c.active && !c.system) || c.id === expense?.categoryId,
    );
    const projects = options.projects.filter(
        (p) => p.active || p.id === expense?.projectId,
    );
    const amount = draft.amount ?? 0;

    const setPayer = (i: number, patch: Partial<PayerDraft>) =>
        set({
            payers: draft.payers.map((p, j) => {
                if (j !== i) {
                    return p;
                }

                const next = { ...p, ...patch };

                // A new payer starts on their usual paid-from.
                if (patch.who && patch.from === undefined) {
                    next.from = defaultFrom(options, patch.who);
                }

                return next;
            }),
        });

    const whoSelect = (i: number, label: string, list = people) => (
        <Select
            aria-label={label}
            value={draft.payers[i]?.who ?? ''}
            onChange={(e) => setPayer(i, { who: e.target.value })}
            options={list}
            wrapClassName="min-w-0 flex-1"
        />
    );
    const fromSelect = (i: number, label: string) => (
        <Select
            aria-label={label}
            value={draft.payers[i]?.from ?? ''}
            onChange={(e) => setPayer(i, { from: e.target.value })}
            options={fromOptions(options, draft.payers[i]?.who ?? '', keepFrom)}
            wrapClassName="ex-from"
        />
    );

    const left = gap(draft);
    const sum = amount - left;
    const balanced = left === 0 && amount > 0;
    const [halfA, halfB] = halves(amount);
    const pickMode = (mode: PayerMode) => {
        const patch: Partial<typeof draft> = { mode };

        if (mode === 'company') {
            patch.payers = [
                {
                    who: 'company',
                    from: defaultAccount(options),
                    amount: null,
                },
                ...draft.payers.slice(1),
            ];
        } else if (draft.payers[0]?.who === 'company') {
            const first = people[0]?.value ?? 'company';
            patch.payers = [
                { who: first, from: defaultFrom(options, first), amount: null },
                ...draft.payers.slice(1),
            ];
        }

        if (mode !== 'company' && (patch.payers ?? draft.payers).length < 2) {
            const second = people[1]?.value ?? people[0]?.value ?? 'company';
            patch.payers = [
                ...(patch.payers ?? draft.payers),
                {
                    who: second,
                    from: defaultFrom(options, second),
                    amount: null,
                },
            ];
        }

        set(patch);
    };

    const savedFile =
        draft.keepFile && expense?.receipt ? expense.receipt : null;
    const pError = payerError(errors);

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={expense ? 'Edit expense' : 'Add expense'}
            subtitle={
                expense?.status === 'approved'
                    ? `${company.name} · saving reverses its entry and sends it back for approval`
                    : `${company.name} · goes to partners for approval`
            }
            icon={Receipt}
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
                        onClick={form.save}
                    >
                        Save expense
                    </Button>
                </>
            }
        >
            <TextInput
                id="ex-d"
                label="Description"
                placeholder="What was it for?"
                value={draft.description}
                maxLength={200}
                onChange={(e) => set({ description: e.target.value })}
                error={errors.description}
            />

            <div className="wiz-grid-2">
                <AmountInput
                    id="ex-a"
                    label="Amount"
                    value={draft.amount}
                    onValueChange={(a) => set({ amount: a })}
                    error={errors.amount_paise}
                />
                <DatePicker
                    id="ex-dt"
                    label="Date"
                    value={draft.date}
                    onValueChange={(date) => date && set({ date })}
                    error={errors.spent_on}
                />
            </div>

            <div className="cf-field">
                <div className="cf-kv">
                    <span className="cf-label" id={catLabel}>
                        Category
                    </span>
                    {abilities.addCategory &&
                        !addingCat &&
                        draft.newCategory === '' && (
                            <TextButton
                                className="cf-row gap-1"
                                onClick={() => {
                                    setCatText('');
                                    setAddingCat(true);
                                }}
                            >
                                <Icon icon={Plus} size={14} />
                                Add category
                            </TextButton>
                        )}
                </div>
                {addingCat ? (
                    <div className="cf-row">
                        <TextInput
                            aria-label="New category name"
                            placeholder="New category name"
                            value={catText}
                            maxLength={60}
                            autoFocus
                            className="min-w-0 flex-1"
                            onChange={(e) => setCatText(e.target.value)}
                        />
                        <Button
                            variant="primary"
                            disabled={catText.trim() === ''}
                            onClick={() => {
                                set({ newCategory: catText.trim() });
                                setAddingCat(false);
                            }}
                        >
                            Add
                        </Button>
                        <Button onClick={() => setAddingCat(false)}>
                            Cancel
                        </Button>
                    </div>
                ) : draft.newCategory !== '' ? (
                    <div className="cf-file">
                        <span className="cf-file-name cf-row">
                            {draft.newCategory}
                            <StatusPill status="new" size="sm" />
                        </span>
                        <IconButton
                            icon={X}
                            size="sm"
                            aria-label="Remove new category"
                            onClick={() => set({ newCategory: '' })}
                        />
                    </div>
                ) : (
                    <Select
                        aria-labelledby={catLabel}
                        value={draft.categoryId}
                        onChange={(e) => set({ categoryId: e.target.value })}
                        options={categories.map((c) => ({
                            value: String(c.id),
                            label: c.name,
                        }))}
                    />
                )}
                {(errors.category_id || errors.new_category) && (
                    <FieldError>
                        {errors.category_id ?? errors.new_category}
                    </FieldError>
                )}
            </div>

            <div className="cf-field">
                <span className="cf-label" id={paidLabel}>
                    Paid by
                </span>
                <SegmentedControl<PayerMode>
                    aria-labelledby={paidLabel}
                    value={draft.mode}
                    onValueChange={pickMode}
                    options={
                        abilities.ownOnly
                            ? MODES.filter(
                                  (m) =>
                                      m.value === 'full' ||
                                      m.value === 'company',
                              )
                            : MODES
                    }
                />

                {draft.mode === 'full' && (
                    <div className="ex-payer">
                        {whoSelect(0, 'Who paid')}
                        {fromSelect(0, 'Paid from')}
                    </div>
                )}

                {draft.mode === 'half' &&
                    [0, 1].map((i) => (
                        <div key={i} className="ex-payer">
                            {whoSelect(
                                i,
                                i === 0 ? 'First payer' : 'Second payer',
                            )}
                            {fromSelect(i, `Payer ${i + 1} paid from`)}
                            <span className="t-money ex-each">
                                {formatInr(i === 0 ? halfA : halfB)}
                            </span>
                        </div>
                    ))}

                {draft.mode === 'custom' && (
                    <>
                        {draft.payers.map((p, i) => (
                            <div key={i} className="ex-payer">
                                {whoSelect(i, `Payer ${i + 1}`, everyone)}
                                {fromSelect(i, `Payer ${i + 1} paid from`)}
                                <AmountInput
                                    aria-label={`Amount paid by payer ${i + 1}`}
                                    size="sm"
                                    align="right"
                                    value={p.amount}
                                    onValueChange={(a) =>
                                        setPayer(i, { amount: a })
                                    }
                                    wrapClassName="ex-amt"
                                />
                                <IconButton
                                    icon={X}
                                    size="sm"
                                    aria-label={`Remove payer ${i + 1}`}
                                    disabled={draft.payers.length < 2}
                                    onClick={() =>
                                        set({
                                            payers: draft.payers.filter(
                                                (_, j) => j !== i,
                                            ),
                                        })
                                    }
                                />
                            </div>
                        ))}
                        <div className="cf-kv flex-wrap gap-2">
                            <TextButton
                                className="cf-row gap-1"
                                onClick={() => {
                                    const next =
                                        people.find(
                                            (o) =>
                                                !draft.payers.some(
                                                    (p) => p.who === o.value,
                                                ),
                                        )?.value ??
                                        people[0]?.value ??
                                        'company';
                                    set({
                                        payers: [
                                            ...draft.payers,
                                            {
                                                who: next,
                                                from: defaultFrom(
                                                    options,
                                                    next,
                                                ),
                                                amount: left > 0 ? left : null,
                                            },
                                        ],
                                    });
                                }}
                            >
                                <Icon icon={Plus} size={14} />
                                Add payer
                            </TextButton>
                            <span
                                role="status"
                                className={`cf-pill ${balanced ? 'cf-pill--pos' : left > 0 ? 'cf-pill--warn' : 'cf-pill--neg'}`}
                            >
                                <Icon
                                    icon={balanced ? Check : TriangleAlert}
                                    size={14}
                                    strokeWidth={2}
                                />
                                <span className="t-money-sm">
                                    {formatInr(sum)} / {formatInr(amount)}
                                </span>{' '}
                                {balanced
                                    ? 'balanced'
                                    : left > 0
                                      ? `${formatInr(left)} left`
                                      : `${formatInr(-left)} over`}
                            </span>
                        </div>
                    </>
                )}

                {draft.mode === 'company' && (
                    <>
                        <div className="cf-inset cf-row t-body t-muted ex-direct">
                            <Icon
                                icon={Landmark}
                                size={18}
                                className="text-ink"
                            />
                            Paid from the company account. No partner balance or
                            loan changes.
                        </div>
                        {fromOptions(options, 'company', keepFrom).length > 1 &&
                            fromSelect(0, 'Account it was paid from')}
                    </>
                )}
                {pError && <FieldError>{pError}</FieldError>}
            </div>

            <div className="wiz-grid-2">
                <Select
                    id="ex-p"
                    label="Project"
                    value={draft.projectId}
                    onChange={(e) => set({ projectId: e.target.value })}
                    options={[
                        { value: '', label: 'No project' },
                        ...projects.map((p) => ({
                            value: String(p.id),
                            label: p.client
                                ? `${p.name} · ${p.client}`
                                : p.name,
                        })),
                    ]}
                    error={errors.project_id}
                />
                <TextInput
                    id="ex-n"
                    label="Notes"
                    placeholder="Optional"
                    value={draft.notes}
                    maxLength={1000}
                    onChange={(e) => set({ notes: e.target.value })}
                    error={errors.notes}
                />
            </div>
            <Toggle
                checked={draft.billable}
                onCheckedChange={(billable) => set({ billable })}
            >
                <span className="cf-stack">
                    <span className="t-label-strong">Bill to a client</span>
                    <span className="t-caption t-muted">
                        Shows on the project client’s monthly summary
                    </span>
                </span>
            </Toggle>

            <div className="wiz-grid-2">
                <div className="cf-field">
                    <span className="cf-label">Submitted by</span>
                    <span className="cf-inwrap is-readonly">
                        <Icon icon={Lock} size={16} className="ml-3.5" />
                        <input
                            type="text"
                            readOnly
                            aria-readonly="true"
                            aria-label="Submitted by"
                            value={
                                expense?.submittedBy
                                    ? expense.submittedBy.name
                                    : `${me.name} (you)`
                            }
                        />
                    </span>
                </div>
                <div className="cf-field">
                    <span className="cf-label">Receipt</span>
                    <FileDrop
                        variant="row"
                        aria-label="Upload receipt"
                        title="Upload"
                        hint="PDF, JPG or PNG, up to 10 MB"
                        accept={ATTACH_ACCEPT}
                        maxSize={MAX_ATTACHMENT}
                        rejectHint="Upload a PDF, JPG or PNG up to 10 MB."
                        onFiles={([file]) => set({ file: file ?? null })}
                        file={
                            draft.file
                                ? {
                                      name: draft.file.name,
                                      size: draft.file.size,
                                      status: 'done',
                                  }
                                : savedFile
                                  ? {
                                        name: savedFile.name,
                                        size: savedFile.size,
                                        status: 'done',
                                    }
                                  : undefined
                        }
                        onRemove={() => set({ file: null, keepFile: false })}
                        removeLabel="Remove receipt"
                        error={errors.receipt}
                    />
                </div>
            </div>

            {form.showProblem && form.problem && (
                <div
                    role="alert"
                    className="cf-alert cf-alert--neg cf-alert--slim"
                >
                    <Icon icon={TriangleAlert} size={16} />
                    <span className="t-body">
                        {draft.mode === 'custom' &&
                        form.problem.startsWith('Payer')
                            ? `Payer amounts must add up to ${formatInr(amount)}.`
                            : form.problem}
                    </span>
                </div>
            )}
        </Modal>
    );
}

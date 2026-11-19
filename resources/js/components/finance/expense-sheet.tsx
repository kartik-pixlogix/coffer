import { Camera, Paperclip, Upload } from 'lucide-react';
import {
    ATTACH_ACCEPT,
    MAX_ATTACHMENT,
} from '@/components/finance/income-modal';
import {
    defaultFrom,
    fromOptions,
    payerError,
    whoOptions,
} from '@/components/finance/expense-form';
import type { MeProps } from '@/components/finance/expense-form';
import { useExpenseForm } from '@/components/finance/use-expense-form';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { FieldError } from '@/components/ui/field';
import { FilterChip } from '@/components/ui/filter-chips';
import { formatFileSize } from '@/components/ui/file-drop.utils';
import { Icon } from '@/components/ui/icon';
import { Select } from '@/components/ui/select';
import { Sheet } from '@/components/ui/sheet';
import { TextInput } from '@/components/ui/text-input';
import { Toggle } from '@/components/ui/toggle';
import { formatPaiseDigits, parseRupeesToPaise } from '@/lib/format';
import type { ExpenseOptions } from '@/types/finance';
import { useToast } from '@/components/ui/toast';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: ExpenseOptions;
    me: MeProps;
    ownOnly: boolean;
    today: string;
};

/**
 * "Add expense" on a phone (MobileExpenses.dc.html sheet): a big amount, what it was
 * for, category chips, who paid and from where (an account or a personal card), the
 * date, a receipt by camera or file, and "Bill to a client". One payer; splits are on
 * the desktop form.
 */
export function ExpenseSheet({
    open,
    onOpenChange,
    options,
    me,
    ownOnly,
    today,
}: Props) {
    const form = useExpenseForm({
        open,
        expense: null,
        options,
        me,
        ownOnly,
        today,
        onDone: () => onOpenChange(false),
    });
    const { draft, set, errors } = form;
    const toast = useToast();
    const payer = draft.payers[0];
    // The phone sheet's half-width select shows plain names like MobileExpenses.dc.html;
    // "(you)" cut "Rohan Desai (you)" short at 375 px (X8).
    const people = whoOptions(options, me, ownOnly).map((o) =>
        o.value === `member:${me.memberId}` ? { ...o, label: me.name } : o,
    );
    const categories = options.categories.filter((c) => c.active && !c.system);
    const projects = options.projects.filter((p) => p.active);
    const amount = draft.amount ?? 0;

    const pick = (files: FileList | null) => {
        const file = files?.[0];

        if (!file) {
            return;
        }

        if (
            file.size > MAX_ATTACHMENT ||
            !/\.(pdf|jpe?g|png)$/i.test(file.name)
        ) {
            toast.error('Attach a PDF, JPG or PNG up to 10 MB.');

            return;
        }

        set({ file });
    };

    return (
        <Sheet
            open={open}
            onOpenChange={onOpenChange}
            title="Add expense"
            footer={
                <Button
                    variant="primary"
                    size="lg"
                    className="flex-1"
                    loading={form.saving}
                    disabled={!amount}
                    onClick={form.save}
                >
                    {amount
                        ? `Save ₹${formatPaiseDigits(amount)} expense`
                        : 'Enter an amount'}
                </Button>
            }
        >
            <label className="cf-card ex-mamount">
                <span className="t-caption t-muted">Amount</span>
                <span className="cf-row items-baseline gap-1.5">
                    <span className="t-money" style={{ fontSize: 28 }}>
                        ₹
                    </span>
                    <input
                        className="ex-amt-big"
                        type="text"
                        inputMode="decimal"
                        autoComplete="off"
                        aria-label="Amount in rupees"
                        aria-invalid={!amount || undefined}
                        value={
                            draft.amount ? formatPaiseDigits(draft.amount) : ''
                        }
                        onChange={(e) =>
                            set({ amount: parseRupeesToPaise(e.target.value) })
                        }
                    />
                </span>
                {errors.amount_paise && (
                    <FieldError>{errors.amount_paise}</FieldError>
                )}
            </label>
            <TextInput
                id="mx-n"
                label="What was it for?"
                placeholder="Vendor or short note"
                value={draft.description}
                maxLength={200}
                onChange={(e) => set({ description: e.target.value })}
                error={errors.description}
            />
            <div className="cf-field">
                <span className="cf-label" id="mx-c">
                    Category
                </span>
                <div
                    className="cf-row flex-wrap gap-1.5"
                    role="group"
                    aria-labelledby="mx-c"
                >
                    {categories.map((c) => (
                        <FilterChip
                            key={c.id}
                            pressed={draft.categoryId === String(c.id)}
                            onClick={() => set({ categoryId: String(c.id) })}
                        >
                            {c.name}
                        </FilterChip>
                    ))}
                </div>
                {errors.category_id && (
                    <FieldError>{errors.category_id}</FieldError>
                )}
            </div>
            <div className="ex-mgrid">
                <Select
                    id="mx-p"
                    label="Paid by"
                    value={payer?.who ?? ''}
                    onChange={(e) =>
                        set({
                            mode:
                                e.target.value === 'company'
                                    ? 'company'
                                    : 'full',
                            payers: [
                                {
                                    who: e.target.value,
                                    from: defaultFrom(options, e.target.value),
                                    amount: null,
                                },
                            ],
                        })
                    }
                    options={people}
                />
                <DatePicker
                    id="mx-d"
                    label="Date"
                    value={draft.date}
                    onValueChange={(date) => date && set({ date })}
                    error={errors.spent_on}
                />
            </div>
            <Select
                id="mx-f"
                label="Paid from"
                value={payer?.from ?? ''}
                onChange={(e) =>
                    set({
                        payers: [{ ...payer, from: e.target.value }],
                    })
                }
                options={fromOptions(options, payer?.who ?? '')}
                error={payerError(errors)}
            />
            <div className="cf-field">
                <span className="cf-label">Receipt</span>
                <div className="ex-mgrid">
                    <label className="cf-btn cf-btn--secondary relative">
                        <Icon icon={Camera} size={16} />
                        Take photo
                        <input
                            type="file"
                            className="cf-sr"
                            accept="image/*"
                            capture="environment"
                            aria-label="Take a photo of the receipt"
                            onChange={(e) => pick(e.target.files)}
                        />
                    </label>
                    <label className="cf-btn cf-btn--secondary relative">
                        <Icon icon={Upload} size={16} />
                        Choose file
                        <input
                            type="file"
                            className="cf-sr"
                            accept={ATTACH_ACCEPT}
                            aria-label="Choose a receipt file"
                            onChange={(e) => pick(e.target.files)}
                        />
                    </label>
                </div>
                {draft.file && (
                    <div className="cf-file">
                        <Icon icon={Paperclip} size={18} />
                        <span className="cf-file-name">{draft.file.name}</span>
                        <span className="t-caption t-muted">
                            {formatFileSize(draft.file.size)}
                        </span>
                    </div>
                )}
                {errors.receipt && <FieldError>{errors.receipt}</FieldError>}
            </div>
            <Toggle
                checked={draft.billable}
                onCheckedChange={(billable) => set({ billable })}
                className="w-full flex-row-reverse justify-between"
            >
                <span className="cf-stack">
                    <span className="t-label-strong">Bill to a client</span>
                    <span className="t-caption t-muted">
                        Shows on the client’s monthly summary
                    </span>
                </span>
            </Toggle>
            {draft.billable && (
                <Select
                    id="mx-pr"
                    label="Client project"
                    value={draft.projectId}
                    onChange={(e) => set({ projectId: e.target.value })}
                    placeholder="Pick a project"
                    options={projects.map((p) => ({
                        value: String(p.id),
                        label: p.client ? `${p.name} · ${p.client}` : p.name,
                    }))}
                    error={errors.project_id}
                />
            )}
            <div className="cf-alert cf-alert--info cf-alert--slim">
                <span className="t-caption">
                    A partner approves it before it counts.
                </span>
            </div>
            {form.showProblem && form.problem && (
                <div
                    role="alert"
                    className="cf-alert cf-alert--neg cf-alert--slim"
                >
                    <span className="t-caption">{form.problem}</span>
                </div>
            )}
        </Sheet>
    );
}

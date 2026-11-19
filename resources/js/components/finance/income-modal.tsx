import { router } from '@inertiajs/react';
import { ArrowRight, TrendingUp } from 'lucide-react';
import { useId, useState } from 'react';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { FieldError } from '@/components/ui/field';
import { FileDrop } from '@/components/ui/file-drop';
import { Modal } from '@/components/ui/modal';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { store as addIncome, update as updateIncome } from '@/routes/income';
import type { IncomeOptions, IncomeRow } from '@/types/finance';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null: add income. A row: edit it. */
    income: IncomeRow | null;
    options: IncomeOptions;
    company: { name: string; jv: boolean; pooled: boolean };
    today: string;
};

type Draft = {
    source: string;
    amount: number | null;
    date: string;
    categoryId: string;
    broughtInBy: string;
    receivedInto: string;
    intoTouched: boolean;
    projectId: string;
    notes: string;
    file: File | null;
    /** The saved invoice is kept unless removed. */
    keepFile: boolean;
};

export const MAX_ATTACHMENT = 10 * 1024 * 1024;
export const ATTACH_ACCEPT = '.pdf,.jpg,.jpeg,.png';

/**
 * "Add income" (Income.dc.html modal, spec §9): source with suggestions from earlier
 * entries, amount, date, category, who brought it in and where it was received (a
 * money account or a partner personally), project, notes and the invoice.
 */
export function IncomeModal({
    open,
    onOpenChange,
    income,
    options,
    company,
    today,
}: Props) {
    const [draft, setDraft] = useState<Draft | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [shownFor, setShownFor] = useState<string | null>(null);
    const catLabel = useId();
    const listId = useId();

    const partners = options.partners.filter(
        (p) => p.active || p.id === income?.broughtInBy,
    );
    const categories = options.categories.filter(
        (c) => c.active || c.id === income?.categoryId,
    );
    const projects = options.projects.filter(
        (p) => p.active || p.id === income?.projectId,
    );

    /** Default "received into": the partner's side account in a JV, else the company account. */
    const intoFor = (partnerId: string): string => {
        const p = options.partners.find((x) => String(x.id) === partnerId);
        const side = p?.sideId
            ? options.accounts.find((a) => a.active && a.sideId === p.sideId)
            : undefined;
        const fallback =
            options.accounts.find((a) => a.active && a.sideId === null) ??
            options.accounts.find((a) => a.active);
        const account = side ?? fallback;

        return account ? `account:${account.id}` : '';
    };

    const key = open ? String(income?.id ?? 'new') : null;

    if (key !== shownFor) {
        setShownFor(key);
        setErrors({});

        if (open) {
            // P1: the signed-in person's own partner (and so their side's account) first.
            const mine = partners.find((p) => p.id === options.myPartnerId);
            const first = String((mine ?? partners[0])?.id ?? '');
            setDraft(
                income
                    ? {
                          source: income.source,
                          amount: income.amountPaise,
                          date: income.date,
                          categoryId: String(income.categoryId),
                          broughtInBy: income.broughtInBy
                              ? String(income.broughtInBy)
                              : '',
                          receivedInto: income.receivedInto,
                          intoTouched: true,
                          projectId: income.projectId
                              ? String(income.projectId)
                              : '',
                          notes: income.notes ?? '',
                          file: null,
                          keepFile: income.attachment !== null,
                      }
                    : {
                          source: '',
                          amount: null,
                          date: today,
                          categoryId: String(
                              categories.find((c) => c.active)?.id ?? '',
                          ),
                          broughtInBy: first,
                          receivedInto: intoFor(first),
                          intoTouched: false,
                          projectId: '',
                          notes: '',
                          file: null,
                          keepFile: false,
                      },
            );
        }
    }

    if (draft === null) {
        return null;
    }

    const set = (patch: Partial<Draft>) =>
        setDraft((d) => (d ? { ...d, ...patch } : d));

    const accountOptions = options.accounts
        .filter((a) => a.active || `account:${a.id}` === income?.receivedInto)
        .map((a) => ({ value: `account:${a.id}`, label: a.name }));
    const personalOptions = partners.map((p) => ({
        value: `partner:${p.id}`,
        label: `${p.name} personally`,
    }));

    const canSave =
        draft.source.trim() !== '' &&
        (draft.amount ?? 0) > 0 &&
        draft.categoryId !== '' &&
        draft.receivedInto !== '' &&
        (company.pooled || draft.broughtInBy !== '');

    const save = () => {
        const payload: Record<string, string | number | boolean | File | null> =
            {
                source: draft.source.trim(),
                amount_paise: draft.amount ?? 0,
                received_on: draft.date,
                category_id: draft.categoryId,
                brought_in_by: draft.broughtInBy || null,
                received_into: draft.receivedInto,
                project_id: draft.projectId || null,
                notes: draft.notes.trim() || null,
                remove_attachment:
                    income !== null &&
                    income.attachment !== null &&
                    !draft.keepFile,
            };

        if (draft.file) {
            payload.attachment = draft.file;
        }

        const visitOptions = {
            preserveScroll: true,
            forceFormData: draft.file !== null,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onOpenChange(false),
            onError: (bag: Record<string, string>) => {
                setErrors(bag);

                // A refused attachment: clear the chip so the field shows why (G5).
                if (bag.attachment) {
                    set({ file: null });
                }
            },
        };

        if (income) {
            router.post(
                updateIncome(income.id).url,
                { ...payload, _method: 'put' },
                visitOptions,
            );
        } else {
            router.post(addIncome().url, payload, visitOptions);
        }
    };

    const savedFile =
        draft.keepFile && income?.attachment ? income.attachment : null;

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={income ? 'Edit income' : 'Add income'}
            subtitle={
                company.pooled
                    ? `${company.name} · shared by equity`
                    : `${company.name} · counts toward the partner who brought it in`
            }
            icon={TrendingUp}
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
                        loading={saving}
                        disabled={!canSave}
                        onClick={save}
                    >
                        {income ? 'Save income' : 'Add income'}
                    </Button>
                </>
            }
        >
            <TextInput
                id="ai-s"
                label="Source"
                placeholder="Client and what it’s for"
                value={draft.source}
                maxLength={200}
                list={listId}
                autoComplete="off"
                onChange={(e) => set({ source: e.target.value })}
                error={errors.source}
            />
            <datalist id={listId}>
                {options.sources.map((s) => (
                    <option key={s} value={s} />
                ))}
            </datalist>

            <div className="wiz-grid-2">
                <AmountInput
                    id="ai-a"
                    label="Amount"
                    placeholder="1,20,000"
                    value={draft.amount}
                    onValueChange={(amount) => set({ amount })}
                    error={errors.amount_paise}
                />
                <DatePicker
                    id="ai-d"
                    label="Date received"
                    value={draft.date}
                    onValueChange={(date) => date && set({ date })}
                    error={errors.received_on}
                />
            </div>

            {categories.length <= 5 ? (
                <div className="cf-field">
                    <span className="cf-label" id={catLabel}>
                        Category
                    </span>
                    <SegmentedControl<string>
                        aria-labelledby={catLabel}
                        value={draft.categoryId}
                        onValueChange={(categoryId) => set({ categoryId })}
                        options={categories.map((c) => ({
                            value: String(c.id),
                            label: c.name,
                        }))}
                    />
                    {errors.category_id && (
                        <FieldError>{errors.category_id}</FieldError>
                    )}
                </div>
            ) : (
                <Select
                    id="ai-c"
                    label="Category"
                    value={draft.categoryId}
                    onChange={(e) => set({ categoryId: e.target.value })}
                    options={categories.map((c) => ({
                        value: String(c.id),
                        label: c.name,
                    }))}
                    error={errors.category_id}
                />
            )}

            <div className="wiz-grid-2">
                <Select
                    id="ai-p"
                    label="Brought in by"
                    value={draft.broughtInBy}
                    onChange={(e) =>
                        set({
                            broughtInBy: e.target.value,
                            ...(draft.intoTouched
                                ? {}
                                : { receivedInto: intoFor(e.target.value) }),
                        })
                    }
                    options={[
                        ...(company.pooled
                            ? [{ value: '', label: 'No one (pooled)' }]
                            : []),
                        ...partners.map((p) => ({
                            value: String(p.id),
                            label: p.name,
                        })),
                    ]}
                    help={
                        company.pooled
                            ? 'Optional: profit is shared by equity.'
                            : undefined
                    }
                    error={errors.brought_in_by}
                />
                <Select
                    id="ai-i"
                    label="Received into"
                    value={draft.receivedInto}
                    onChange={(e) =>
                        set({ receivedInto: e.target.value, intoTouched: true })
                    }
                    options={[...accountOptions, ...personalOptions]}
                    help={
                        company.jv
                            ? 'Which side’s bank got the money. Used for settlements.'
                            : 'The account that got the money, or a partner personally.'
                    }
                    error={errors.received_into}
                />
            </div>

            <div className="wiz-grid-2">
                <Select
                    id="ai-pr"
                    label="Project"
                    value={draft.projectId}
                    onChange={(e) => set({ projectId: e.target.value })}
                    options={[
                        { value: '', label: 'No project' },
                        ...projects.map((p) => ({
                            value: String(p.id),
                            label: p.name,
                        })),
                    ]}
                    error={errors.project_id}
                />
                <TextInput
                    id="ai-n"
                    label="Notes"
                    placeholder="Optional"
                    value={draft.notes}
                    maxLength={1000}
                    onChange={(e) => set({ notes: e.target.value })}
                    error={errors.notes}
                />
            </div>

            <FileDrop
                variant="row"
                aria-label="Attach invoice"
                title="Attach invoice"
                hint="PDF, JPG or PNG, up to 10 MB"
                accept={ATTACH_ACCEPT}
                maxSize={MAX_ATTACHMENT}
                rejectHint="Attach a PDF, JPG or PNG up to 10 MB."
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
                removeLabel="Remove invoice"
                error={errors.attachment}
            />
        </Modal>
    );
}

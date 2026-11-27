import { router } from '@inertiajs/react';
import { useState } from 'react';
import { draftFor, gap, payload } from '@/components/finance/expense-form';
import { syncExpenseTax } from '@/components/finance/tax-form';
import type { ExpenseDraft, MeProps } from '@/components/finance/expense-form';
import {
    store as addExpense,
    update as updateExpense,
} from '@/routes/expenses';
import type { ExpenseOptions, ExpenseRow } from '@/types/finance';

type Args = {
    open: boolean;
    expense: ExpenseRow | null;
    options: ExpenseOptions;
    me: MeProps;
    ownOnly: boolean;
    today: string;
    onDone: () => void;
};

/**
 * The add / edit expense form shared by the desktop modal and the phone sheet: the
 * draft (reset on each opening), server errors, and saving with the receipt.
 */
export function useExpenseForm({
    open,
    expense,
    options,
    me,
    ownOnly,
    today,
    onDone,
}: Args) {
    const [draft, setDraft] = useState<ExpenseDraft>(() =>
        draftFor(expense, options, me, ownOnly, today),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [tried, setTried] = useState(false);
    const [shownFor, setShownFor] = useState<string | null>(null);

    const key = open ? String(expense?.id ?? 'new') : null;

    if (key !== shownFor) {
        setShownFor(key);

        if (open) {
            setDraft(draftFor(expense, options, me, ownOnly, today));
            setErrors({});
            setTried(false);
        }
    }

    // The bill's tax follows its amount (D162): the taxable value, GST and TDS are kept in step.
    const set = (patch: Partial<ExpenseDraft>) =>
        setDraft((d) => {
            const next = { ...d, ...patch };

            return next.tax
                ? {
                      ...next,
                      tax: syncExpenseTax(
                          next.tax,
                          next.amount ?? 0,
                          options.tax,
                      ),
                  }
                : next;
        });

    const hasCategory =
        draft.newCategory.trim() !== '' || draft.categoryId !== '';
    const balanced = draft.mode !== 'custom' || gap(draft) === 0;

    let problem: string | null = null;

    if (draft.description.trim() === '') {
        problem = 'Add a description.';
    } else if (!draft.amount) {
        problem = 'Enter an amount.';
    } else if (!hasCategory) {
        problem = 'Pick a category.';
    } else if (!balanced) {
        problem = draft.tax?.tdsSection
            ? 'Payer amounts must add up to the amount less TDS.'
            : 'Payer amounts must add up to the amount.';
    } else if (draft.billable && draft.projectId === '') {
        problem = 'Pick the client’s project to bill.';
    }

    const save = () => {
        setTried(true);

        if (problem !== null) {
            return;
        }

        const data = payload(draft, options, expense);
        const visit = {
            preserveScroll: true,
            forceFormData: draft.file !== null,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onDone(),
            onError: (bag: Record<string, string>) => {
                setErrors(bag);

                // A refused receipt: clear the chip so the field shows why (G5).
                if (bag.receipt) {
                    setDraft((d) => ({ ...d, file: null }));
                }
            },
        };

        if (expense) {
            router.post(
                updateExpense(expense.id).url,
                { ...data, _method: 'put' },
                visit,
            );
        } else {
            router.post(addExpense().url, data, visit);
        }
    };

    return { draft, set, errors, saving, save, problem, showProblem: tried };
}

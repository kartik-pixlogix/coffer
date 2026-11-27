import type { FormDataConvertible } from '@inertiajs/core';
import {
    payableAfterTds,
    taxFromRow,
    taxPayload,
} from '@/components/finance/tax-form';
import type { ExpenseOptions, ExpenseRow, PayerMode } from '@/types/finance';
import type { TaxDraft } from '@/types/tax';

/** `partner:{id}`, `member:{id}` or `company`. */
export type Who = string;
/** `account:{id}` or `personal` (a card to reimburse). */
export type From = string;

export type PayerDraft = { who: Who; from: From; amount: number | null };

export type ExpenseDraft = {
    description: string;
    amount: number | null;
    date: string;
    categoryId: string;
    /** "+ Add category": a name typed instead of picking one. */
    newCategory: string;
    projectId: string;
    billable: boolean;
    notes: string;
    mode: PayerMode;
    payers: PayerDraft[];
    file: File | null;
    keepFile: boolean;
    /** GST and TDS on the bill (D162), or null when there's none. */
    tax: TaxDraft | null;
};

export type MeProps = {
    memberId: number | null;
    partnerId: number | null;
    name: string;
};

export const PERSONAL = 'personal';

/** Who can be picked as a payer: partners, team members and (in Custom) the company. */
export function whoOptions(
    options: ExpenseOptions,
    me: MeProps,
    ownOnly: boolean,
    keep: Who[] = [],
): { value: Who; label: string }[] {
    if (ownOnly) {
        return [
            ...(me.memberId
                ? [
                      {
                          value: `member:${me.memberId}`,
                          label: `${me.name} (you)`,
                      },
                  ]
                : []),
            { value: 'company', label: 'Company account' },
        ];
    }

    return [
        ...options.partners
            .filter((p) => p.active || keep.includes(`partner:${p.id}`))
            .map((p) => ({ value: `partner:${p.id}`, label: p.name })),
        ...options.members
            .filter((m) => m.active || keep.includes(`member:${m.id}`))
            .map((m) => ({
                value: `member:${m.id}`,
                label: `${m.name} · team`,
            })),
        { value: 'company', label: 'Company account' },
    ];
}

/** Where a payer paid from: the company's accounts, or (people only) a personal card. */
export function fromOptions(
    options: ExpenseOptions,
    who: Who,
    keep: From[] = [],
): { value: From; label: string }[] {
    const accounts = options.accounts
        .filter((a) => a.active || keep.includes(`account:${a.id}`))
        .map((a) => ({ value: `account:${a.id}`, label: a.name }));

    return who === 'company'
        ? accounts
        : [
              ...accounts,
              // Short enough for the payer row (X4); the reimbursement shows on the row.
              { value: PERSONAL, label: 'Personal card' },
          ];
}

export function defaultAccount(options: ExpenseOptions): From {
    const account =
        options.accounts.find((a) => a.active && a.sideId === null) ??
        options.accounts.find((a) => a.active);

    return account ? `account:${account.id}` : '';
}

/** People pay by personal card unless told otherwise; the company from its account. */
export function defaultFrom(options: ExpenseOptions, who: Who): From {
    return who === 'company' ? defaultAccount(options) : PERSONAL;
}

export function halves(amount: number): [number, number] {
    const second = Math.floor(amount / 2);

    return [amount - second, second];
}

/** The draft for a new expense, or one being edited. */
export function draftFor(
    expense: ExpenseRow | null,
    options: ExpenseOptions,
    me: MeProps,
    ownOnly: boolean,
    today: string,
): ExpenseDraft {
    if (expense) {
        return {
            description: expense.description,
            amount: expense.amountPaise,
            date: expense.date,
            categoryId: String(expense.categoryId),
            newCategory: '',
            projectId: expense.projectId ? String(expense.projectId) : '',
            billable: expense.billable,
            notes: expense.notes ?? '',
            mode: expense.mode,
            payers: expense.payers.map((p) => ({
                who:
                    p.type === 'company'
                        ? 'company'
                        : p.type === 'partner'
                          ? `partner:${p.partnerId}`
                          : `member:${p.memberId}`,
                from: p.accountId ? `account:${p.accountId}` : PERSONAL,
                amount: p.amountPaise,
            })),
            file: null,
            keepFile: expense.receipt !== null,
            tax: expense.tax ? taxFromRow(expense.tax) : null,
        };
    }

    const first = whoOptions(options, me, ownOnly)[0]?.value ?? 'company';
    const preferred = me.partnerId
        ? `partner:${me.partnerId}`
        : me.memberId
          ? `member:${me.memberId}`
          : first;
    const second =
        whoOptions(options, me, ownOnly).find(
            (o) => o.value !== preferred && o.value !== 'company',
        )?.value ?? preferred;

    return {
        description: '',
        amount: null,
        date: today,
        categoryId: String(
            options.categories.find((c) => c.active && c.name !== 'Salary')
                ?.id ?? '',
        ),
        newCategory: '',
        projectId: '',
        billable: false,
        notes: '',
        mode: 'full',
        payers: [
            {
                who: preferred,
                from: defaultFrom(options, preferred),
                amount: null,
            },
            { who: second, from: defaultFrom(options, second), amount: null },
        ],
        file: null,
        keepFile: false,
        tax: null,
    };
}

/** The payer lines the mode uses (Full: the first; Half: two; Company: one company line). */
export function linesFor(
    draft: ExpenseDraft,
    options: ExpenseOptions,
): PayerDraft[] {
    // With TDS the payers pay the bill less what is held back (D162).
    const amount = payableAfterTds(draft.amount ?? 0, draft.tax);

    switch (draft.mode) {
        case 'full':
            return [{ ...draft.payers[0], amount }];
        case 'half': {
            const [a, b] = halves(amount);

            return [
                { ...draft.payers[0], amount: a },
                { ...(draft.payers[1] ?? draft.payers[0]), amount: b },
            ];
        }
        case 'company':
            return [
                {
                    who: 'company',
                    from:
                        draft.payers[0]?.who === 'company'
                            ? draft.payers[0].from
                            : defaultAccount(options),
                    amount,
                },
            ];
        default:
            return draft.payers;
    }
}

/** Custom lines' sum against what they must pay (the amount less TDS): 0 when balanced. */
export function gap(draft: ExpenseDraft): number {
    const sum = draft.payers.reduce((s, p) => s + (p.amount ?? 0), 0);

    return payableAfterTds(draft.amount ?? 0, draft.tax) - sum;
}

export function payload(
    draft: ExpenseDraft,
    options: ExpenseOptions,
    editing: ExpenseRow | null,
): Record<string, FormDataConvertible> {
    const lines = linesFor(draft, options).map((p) => {
        const [type, id] = p.who.split(':');

        return {
            type,
            partner_id: type === 'partner' ? Number(id) : null,
            member_id: type === 'member' ? Number(id) : null,
            account_id: p.from.startsWith('account:')
                ? Number(p.from.slice(8))
                : null,
            amount_paise: p.amount ?? 0,
        };
    });

    const data: Record<string, FormDataConvertible> = {
        description: draft.description.trim(),
        amount_paise: draft.amount ?? 0,
        spent_on: draft.date,
        category_id: draft.newCategory.trim() ? null : draft.categoryId || null,
        new_category: draft.newCategory.trim() || null,
        project_id: draft.projectId || null,
        billable: draft.billable,
        notes: draft.notes.trim() || null,
        payer_mode: draft.mode,
        payers: lines,
        remove_receipt:
            editing !== null && editing.receipt !== null && !draft.keepFile,
    };

    if (draft.file) {
        data.receipt = draft.file;
    }

    if (draft.tax) {
        data.tax = taxPayload(draft.tax);
    }

    return data;
}

/** The first message from the payer fields (`payers`, `payers.0.partner_id`…). */
export function payerError(errors: Record<string, string>): string | undefined {
    return (
        errors.payers ??
        Object.entries(errors).find(([key]) => key.startsWith('payers.'))?.[1]
    );
}

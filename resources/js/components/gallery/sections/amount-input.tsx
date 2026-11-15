import { useState } from 'react';
import { PvGrid, PvNote } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { AmountInput } from '@/components/ui/amount-input';
import { formatInr } from '@/lib/format';

function DefaultDemo() {
    const [paise, setPaise] = useState<number | null>(null);

    return (
        <AmountInput
            label="Default"
            placeholder="0"
            value={paise}
            onValueChange={setPaise}
            help={paise === null ? undefined : `${paise} paise`}
        />
    );
}

function ErrorDemo() {
    const payers = 1_14_900_00; // the payers add up to ₹1,14,900
    const [paise, setPaise] = useState<number | null>(1_20_000_00);
    const gap = (paise ?? 0) - payers;

    return (
        <AmountInput
            label="Error"
            value={paise}
            onValueChange={setPaise}
            error={
                gap > 0
                    ? `Payers add up to ${formatInr(gap)} more than the amount.`
                    : undefined
            }
        />
    );
}

export default {
    name: 'AmountInput',
    group: 'Forms',
    order: 7,
    subtitle: '₹ prefix, IBM Plex Mono with tabular figures, Indian grouping',
    render: () => (
        <>
            <PvGrid
                columns="repeat(2,minmax(0,1fr))"
                style={{ alignItems: 'start' }}
            >
                <DefaultDemo />
                <AmountInput
                    label="Hover"
                    wrapClassName="is-hover"
                    defaultValue={1_14_900_00}
                />
                <AmountInput
                    label="Focus"
                    wrapClassName="is-focus"
                    defaultValue={25_000_00}
                />
                <AmountInput label="Disabled" defaultValue={0} disabled />
                <ErrorDemo />
                <AmountInput
                    label="Compact, right-aligned (payer row)"
                    size="sm"
                    align="right"
                    wrapClassName="max-w-[170px]"
                    defaultValue={54_900_00}
                />
            </PvGrid>
            <PvNote>
                Format with Indian grouping (₹18,42,350) as the person types;
                negative amounts read −₹ and are never typed as a minus.
            </PvNote>
        </>
    ),
} satisfies GallerySection;

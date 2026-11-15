import { useState } from 'react';
import { PvGrid } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Checkbox } from '@/components/ui/checkbox';

function RowsDemo() {
    const [rows, setRows] = useState([true, false, true]);
    const all = rows.every(Boolean);
    const some = rows.some(Boolean);

    return (
        <Checkbox
            checked={all}
            indeterminate={some && !all}
            onCheckedChange={() => setRows(rows.map(() => !all))}
        >
            Some rows selected
        </Checkbox>
    );
}

function TermsDemo() {
    const [agreed, setAgreed] = useState(false);

    return (
        <Checkbox
            checked={agreed}
            onCheckedChange={setAgreed}
            error={
                agreed ? undefined : 'Agree to the Terms to start your trial.'
            }
        >
            I agree to the Terms
        </Checkbox>
    );
}

export default {
    name: 'Checkbox',
    group: 'Forms',
    order: 4,
    subtitle: 'Multi-select, row selection and consent',
    render: () => (
        <PvGrid columns="repeat(2,minmax(0,1fr))">
            <Checkbox>Remember me</Checkbox>
            <Checkbox defaultChecked>Billable</Checkbox>
            <Checkbox className="is-hover">Hover</Checkbox>
            <Checkbox className="is-focus" defaultChecked>
                Focus
            </Checkbox>
            <Checkbox disabled>Disabled</Checkbox>
            <Checkbox defaultChecked disabled>
                Disabled checked
            </Checkbox>
            <RowsDemo />
            <TermsDemo />
        </PvGrid>
    ),
} satisfies GallerySection;

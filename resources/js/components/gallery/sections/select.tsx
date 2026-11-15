import { useState } from 'react';
import { PvGrid, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Select } from '@/components/ui/select';

const PARTNERS = [
    { value: 'aarav', label: 'Aarav Mehta — Vertex Labs' },
    { value: 'kabir', label: 'Kabir Shah — Bluefin Infotech' },
];

function ErrorDemo() {
    const [payer, setPayer] = useState('');

    return (
        <Select
            label="Error"
            placeholder="Choose a partner"
            options={PARTNERS}
            value={payer}
            onChange={(event) => setPayer(event.currentTarget.value)}
            error={payer === '' ? 'Pick who paid.' : undefined}
        />
    );
}

export default {
    name: 'Select',
    group: 'Forms',
    order: 3,
    subtitle: 'Native select with the Coffer chevron · box and pill',
    render: () => (
        <PvGrid
            columns="repeat(2,minmax(0,1fr))"
            style={{ alignItems: 'start' }}
        >
            <Select
                label="Default"
                options={[
                    { value: 'hardware', label: 'Hardware' },
                    { value: 'software', label: 'Software' },
                    { value: 'travel', label: 'Travel' },
                ]}
            />
            <Select
                label="Focus"
                className="is-focus"
                style={{ borderColor: 'var(--focus)' }}
                options={[PARTNERS[1]]}
            />
            <Select
                label="Disabled"
                options={[{ value: 'INR', label: 'INR — Indian rupee' }]}
                disabled
            />
            <ErrorDemo />
            <div className="cf-field">
                <span className="cf-label">Filter pills</span>
                <PvRow>
                    <Select
                        pill
                        aria-label="Category"
                        options={[
                            { value: '', label: 'All categories' },
                            { value: 'hardware', label: 'Hardware' },
                            { value: 'software', label: 'Software' },
                        ]}
                    />
                    <Select
                        pill
                        aria-label="Paid by"
                        options={[
                            { value: '', label: 'Anyone' },
                            { value: 'aarav', label: 'Aarav' },
                            { value: 'kabir', label: 'Kabir' },
                        ]}
                    />
                </PvRow>
            </div>
        </PvGrid>
    ),
} satisfies GallerySection;

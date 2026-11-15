import { useState } from 'react';
import { PvGrid } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { TextInput } from '@/components/ui/text-input';

function ErrorDemo() {
    const [name, setName] = useState('');

    return (
        <TextInput
            label="Error"
            placeholder="Company name"
            value={name}
            onChange={(event) => setName(event.currentTarget.value)}
            error={name.trim() === '' ? 'Add a company name.' : undefined}
        />
    );
}

export default {
    name: 'TextInput',
    group: 'Forms',
    order: 1,
    subtitle: 'Label, help text, every state',
    render: () => (
        <PvGrid
            columns="repeat(2,minmax(0,1fr))"
            style={{ alignItems: 'start' }}
        >
            <TextInput label="Default" placeholder="What was it for?" />
            <TextInput
                label="Hover"
                className="is-hover"
                defaultValue="MacBook Air"
            />
            <TextInput
                label="Focus"
                className="is-focus"
                defaultValue="Figma organisation seats"
            />
            <TextInput
                label="Disabled"
                defaultValue="Saarthi Portal JV"
                disabled
            />
            <ErrorDemo />
            <TextInput
                label="Read-only"
                defaultValue="Aarav Mehta (you)"
                readOnly
            />
            <TextInput
                label="Loading"
                defaultValue="VLABS"
                loading
                help="Checking the short code…"
            />
            <TextInput
                label="With help text"
                size="sm"
                defaultValue="VL"
                help="2–6 letters, shown on avatars and exports."
            />
        </PvGrid>
    ),
} satisfies GallerySection;

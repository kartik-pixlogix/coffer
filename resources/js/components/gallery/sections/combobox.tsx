import { useState } from 'react';
import { PvGrid } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Combobox } from '@/components/ui/combobox';
import type { ComboboxOption } from '@/components/ui/combobox';

/** Avatar markup inline (the Avatar component belongs to another batch). */
function Initials({ text, partner }: { text: string; partner?: number }) {
    return (
        <span className="cf-avatar cf-avatar--sm" aria-hidden="true">
            {text}
            {partner !== undefined && (
                <span
                    className="cf-avatar-dot"
                    style={{ background: `var(--partner-${partner})` }}
                />
            )}
        </span>
    );
}

const PEOPLE: ComboboxOption[] = [
    {
        value: 'kabir',
        label: 'Kabir Shah',
        meta: 'Bluefin Infotech',
        leading: <Initials text="KS" partner={3} />,
    },
    {
        value: 'sana',
        label: 'Sana Qureshi',
        meta: 'shared with Kabir',
        leading: <Initials text="SQ" partner={3} />,
    },
    {
        value: 'neha',
        label: 'Neha Joshi',
        meta: 'inactive',
        // Matches "sha" so the open specimen shows an unavailable option.
        keywords: ['Shah team'],
        disabled: true,
        leading: <Initials text="NJ" />,
    },
    {
        value: 'aarav',
        label: 'Aarav Mehta',
        meta: 'Vertex Labs',
        leading: <Initials text="AM" partner={1} />,
    },
    {
        value: 'ishita',
        label: 'Ishita Rao',
        meta: 'Vertex Labs',
        leading: <Initials text="IR" partner={2} />,
    },
];

function ProjectDemo() {
    const [projects, setProjects] = useState<ComboboxOption[]>([
        { value: 'saarthi-portal', label: 'Saarthi Portal' },
        { value: 'bluefin-crm', label: 'Bluefin CRM' },
    ]);
    const [project, setProject] = useState<string | null>(null);

    return (
        <div className="cf-field">
            <span className="cf-label">No match · error</span>
            <Combobox
                aria-label="Project"
                listLabel="Projects"
                options={projects}
                value={project}
                onValueChange={setProject}
                defaultQuery="Saarthi mobile"
                searchIcon={false}
                noun="project"
                onCreate={(name) => {
                    const value = name.toLowerCase().replace(/\s+/g, '-');
                    setProjects([...projects, { value, label: name }]);
                    setProject(value);
                }}
            />
        </div>
    );
}

export default {
    name: 'Combobox',
    group: 'Forms',
    order: 12,
    subtitle: 'Searchable select, shown open with a match highlighted',
    render: () => (
        <PvGrid
            columns="repeat(2,minmax(0,1fr))"
            style={{ alignItems: 'start' }}
        >
            <Combobox
                label="Open · searching"
                listLabel="Partners"
                options={PEOPLE}
                defaultValue="sana"
                defaultOpen
                defaultQuery="sha"
                wrapClassName="is-focus"
            />
            <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                <Combobox
                    label="Closed · value"
                    variant="select"
                    listLabel="Partners"
                    options={PEOPLE}
                    defaultValue="kabir"
                    placeholder="Search people…"
                />
                <div className="cf-field">
                    <span className="cf-label">Loading results</span>
                    <Combobox
                        aria-label="Linked user account"
                        listLabel="User accounts"
                        options={[]}
                        filter={false}
                        defaultQuery="sana@"
                        searchIcon={false}
                        loading
                    />
                </div>
                <ProjectDemo />
            </div>
        </PvGrid>
    ),
} satisfies GallerySection;

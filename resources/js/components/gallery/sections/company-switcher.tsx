import { useState } from 'react';
import type { CSSProperties } from 'react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import {
    CompanySwitcher,
    CompanySwitcherList,
    CompanySwitcherTrigger,
} from '@/components/ui/company-switcher';
import { toast } from '@/components/ui/toast';
import type { CompanySummary } from '@/types';

const COMPANIES: CompanySummary[] = [
    {
        id: 1,
        name: 'Saarthi Portal JV',
        initials: 'SP',
        type: 'jv',
        role: 'admin',
    },
    {
        id: 2,
        name: 'Vertex Labs Pvt Ltd',
        initials: 'VL',
        type: 'single',
        role: 'admin',
    },
    {
        id: 3,
        name: 'Northstar Studio',
        initials: 'NS',
        type: 'single',
        role: 'partner',
    },
    {
        id: 4,
        name: 'Kriya Commerce',
        initials: 'KC',
        type: 'single',
        role: 'viewer',
    },
];

const COLUMN: CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
    alignItems: 'flex-start',
};

function LiveSwitcher() {
    const [current, setCurrent] = useState(1);

    return (
        <CompanySwitcher
            companies={COMPANIES}
            currentId={current}
            workspaceName="Vertex Group"
            onSwitch={setCurrent}
            onNewCompany={() => toast.info('New company')}
        />
    );
}

export default {
    name: 'CompanySwitcher',
    group: 'Actions and navigation',
    order: 22,
    subtitle: 'Top-bar switcher shown open',
    render: () => (
        <>
            <div style={COLUMN}>
                <CompanySwitcherTrigger company={COMPANIES[0]} expanded />
                <CompanySwitcherList
                    companies={COMPANIES}
                    currentId={1}
                    activeId={3}
                    workspaceName="Vertex Group"
                />
            </div>
            <PvRow>
                <LiveSwitcher />
                <PvNote>
                    Live: ↑ ↓ move, Enter switches, Esc returns focus
                </PvNote>
            </PvRow>
        </>
    ),
} satisfies GallerySection;

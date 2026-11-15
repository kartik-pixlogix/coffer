import type { CompanySummary, WorkspaceSummary } from '@/types';

/*
 * Phase 0 placeholder data for the top bar, matching design/screens/Main.dc.html.
 * Phase 2 replaces it with shared Inertia props (workspace, companies, current
 * company); Phase 8 replaces the unread count with real notifications.
 */

export const placeholderWorkspace: WorkspaceSummary = {
    id: 1,
    name: 'Vertex Group',
};

export const placeholderCompanies: CompanySummary[] = [
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

export const placeholderUnreadNotifications = 3;

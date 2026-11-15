import type { CompanyRole, CompanyType } from '@/types';

/** "Sunday, 15 Nov 2026" — the top-bar date. */
export function formatLongDate(date: Date): string {
    const parts = new Intl.DateTimeFormat('en-IN', {
        weekday: 'long',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).formatToParts(date);

    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((p) => p.type === type)?.value ?? '';

    return `${part('weekday')}, ${part('day')} ${part('month')} ${part('year')}`;
}

const COMPANY_TYPES: Record<CompanyType, string> = {
    single: 'Single',
    jv: 'Joint Venture',
};

const ROLES: Record<CompanyRole, string> = {
    admin: 'Admin',
    partner: 'Partner',
    member: 'Member',
    viewer: 'Viewer',
};

export const formatRole = (role: CompanyRole): string => ROLES[role];

/** "Joint Venture · Admin" — the second line of a company-switcher row. */
export const formatCompanyMeta = (type: CompanyType, role: CompanyRole) =>
    `${COMPANY_TYPES[type]} · ${ROLES[role]}`;

export type CompanyType = 'single' | 'jv';

export type CompanyRole = 'admin' | 'partner' | 'member' | 'viewer';

export type WorkspaceSummary = {
    id: number;
    name: string;
};

/** One row of the company switcher: a company and the viewer's role in it. */
export type CompanySummary = {
    id: number;
    name: string;
    initials: string;
    type: CompanyType;
    role: CompanyRole;
};

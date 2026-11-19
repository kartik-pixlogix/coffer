export type CompanyType = 'single' | 'jv';

export type CompanyRole = 'admin' | 'partner' | 'member' | 'viewer';

export type WorkspaceSummary = {
    id: number;
    name: string;
    /** The signed-in user owns the workspace (billing). */
    isOwner: boolean;
};

/** One row of the company switcher: a company and the viewer's role in it. */
export type CompanySummary = {
    id: number;
    name: string;
    initials: string;
    type: CompanyType;
    role: CompanyRole;
    /** Workspace name, shown when the user belongs to more than one workspace. */
    workspace: string | null;
    /** Archived (V4, D139): read-only; only listed while it is the current company. */
    archived?: boolean;
};

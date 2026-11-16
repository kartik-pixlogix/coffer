export type CostType = 'hourly' | 'fixed_monthly';

/** A partner members bill to (Team tabs, pickers). */
export type PartnerOption = { id: number; name: string; colour: number };

/** Someone in the workspace who can be linked to a member. */
export type UserOption = { id: number; name: string; email: string };

export type TeamMember = {
    id: number;
    name: string;
    designation: string | null;
    partnerId: number;
    userId: number | null;
    costType: CostType;
    /** Per hour (Hourly) or per month (Fixed monthly), in effect today. */
    ratePaise: number;
    active: boolean;
    hasHistory: boolean;
};

export type TeamCan = {
    manage: boolean;
    editRates: boolean;
    /** "Run salaries" (Team page only). */
    runSalaries?: boolean;
};

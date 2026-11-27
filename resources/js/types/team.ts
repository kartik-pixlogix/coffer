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
    /** TDS on their pay (D161): '192' (a monthly amount) or a vendor section (its rate). */
    tdsSection: string | null;
    tdsMonthlyPaise: number;
    active: boolean;
    hasHistory: boolean;
};

export type TeamCan = {
    manage: boolean;
    editRates: boolean;
    /** "Run salaries" (Team page only). */
    runSalaries?: boolean;
};

/** Vault items a member can open (Team member page; null = no account or no vault access). */
export type TeamVaultAccess = {
    items: {
        id: number;
        name: string;
        vault: string;
        monogram: string;
        level: 'view' | 'use' | 'edit' | 'manage';
    }[];
    /** Items they can open that the viewer can't see. */
    hidden: number;
} | null;

import type { PartnerOption } from '@/types/team';

export type TimeEntry = {
    id: number;
    /** "YYYY-MM-DD" */
    date: string;
    memberId: number;
    memberName: string;
    partnerId: number;
    projectId: number;
    projectName: string;
    /** Hundredths of an hour (D35). */
    hoursCenti: number;
    billable: boolean;
    notes: string | null;
    /** Month-mode logs and imports share a batch that can be deleted together. */
    batch: string | null;
    batchSize: number;
};

export type MemberOption = { id: number; name: string; partnerId: number };

export type ProjectOption = { id: number; name: string; active: boolean };

export type TimesheetOptions = {
    members: MemberOption[];
    partners: PartnerOption[];
    projects: ProjectOption[];
};

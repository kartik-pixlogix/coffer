/** Account › Your data (D98). Dates are ISO strings. */
export type DataExportRow = {
    id: number;
    kind: 'account' | 'workspace';
    label: string;
    status: 'queued' | 'ready' | 'failed';
    createdAt: string;
    expiresAt: string | null;
    /** A signed, expiring download link once the file is ready. */
    url: string | null;
};

export type PrivacyProps = {
    graceDays: number;
    exportTtlDays: number;
    exports: DataExportRow[];
    deletion: { scheduledFor: string } | null;
    ownedWorkspaces: {
        id: number;
        name: string;
        companies: number;
        deletion: { scheduledFor: string } | null;
    }[];
};

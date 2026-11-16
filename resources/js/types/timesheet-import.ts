export type ImportField =
    | 'member'
    | 'date'
    | 'hours'
    | 'project'
    | 'billable'
    | 'notes';

export type ImportColumn = {
    index: number;
    name: string;
    field: ImportField | null;
    /** How the field was picked: by header name, by its values, by the person, or not imported. */
    how: 'auto' | 'values' | 'chosen' | 'skipped';
    samples: string[];
};

export type PreviewRow = {
    number: number;
    member: string;
    /** "1 Oct 2026", "Sep 2026 · month", or the raw value when it's wrong. */
    date: string;
    /** "2.50", or the raw value when it's wrong. */
    hours: string;
    project: string;
    billable: boolean;
    error: string | null;
};

export type ImportStep = 'upload' | 'map' | 'preview';

export type ImportData = {
    id: number;
    name: string;
    size: number;
    rows: number;
    status: 'uploaded' | 'mapped' | 'queued' | 'done' | 'failed';
    // Map step
    columns?: ImportColumn[];
    autoMatched?: number;
    notes?: string[];
    // Preview step
    summary?: {
        rows: number;
        ok: number;
        errors: number;
        okCenti: number;
        newProjects: string[];
    };
    preview?: {
        filter: 'all' | 'ok' | 'errors';
        page: number;
        pages: number;
        rows: PreviewRow[];
    };
    // Queued / finished
    result?: {
        imported: number;
        centi: number;
        skipped: number;
        hasErrors: boolean;
        failure: string | null;
    };
};

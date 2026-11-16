import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Check,
    Download,
    FileUp,
    RotateCw,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { FieldError } from '@/components/ui/field';
import { FileDrop, FileRow } from '@/components/ui/file-drop';
import { FilterChips } from '@/components/ui/filter-chips';
import { Icon, Spinner } from '@/components/ui/icon';
import { KpiTile } from '@/components/ui/kpi-tile';
import { Pager } from '@/components/ui/pagination';
import { StatusPill } from '@/components/ui/status-pill';
import { useToast } from '@/components/ui/toast';
import { formatIndianNumber } from '@/lib/format';
import { formatCenti } from '@/lib/hours';
import {
    commit as commitImport,
    destroy as removeImport,
    errors as errorsCsv,
    mapping as saveMapping,
    store as uploadImport,
    template as templateCsv,
} from '@/routes/timesheets/import';
import {
    importMethod as importPage,
    index as timesheetsIndex,
} from '@/routes/timesheets';
import type {
    ImportColumn,
    ImportData,
    ImportField,
    PreviewRow,
} from '@/types/timesheet-import';

const FIELD_LABELS: Record<ImportField, string> = {
    member: 'Member',
    date: 'Date',
    hours: 'Hours',
    project: 'Project',
    billable: 'Billable',
    notes: 'Notes',
};

const MAX_BYTES = 5 * 1024 * 1024;

/** The footer bar under every step: the way back on the left, the next step on the right. */
export function ImportFooter({
    back,
    children,
}: {
    back: React.ReactNode;
    children?: React.ReactNode;
}) {
    return (
        <div className="imp-foot">
            {back}
            <div className="cf-row flex-wrap justify-end gap-3">{children}</div>
        </div>
    );
}

/** Step 1 (BulkImport.dc.html "Upload"): the CSV format, a template, and the drop zone. */
export function UploadStep() {
    const [file, setFile] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);

    const next = () => {
        if (!file) {
            return;
        }

        router.post(
            uploadImport().url,
            { file },
            {
                forceFormData: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onError: (bag) =>
                    setError(bag.file ?? 'This file can’t be used.'),
            },
        );
    };

    return (
        <>
            <div className="imp-cols">
                <section className="cf-card imp-format" aria-labelledby="cf-h">
                    <h2 id="cf-h" className="cf-dot-title t-title">
                        CSV format
                    </h2>
                    <p className="t-body t-muted m-0">
                        One row per entry, with a header row. Column names can
                        differ: you’ll match them in the next step.
                    </p>
                    <div
                        className="cf-table"
                        role="table"
                        aria-label="Expected columns"
                    >
                        {[
                            [
                                'developer',
                                'Member name or email, e.g. Rohan Desai',
                            ],
                            [
                                'date',
                                '1-Apr-26, 01/04/2026 or 2026-04-01; 2026-04 for a month’s total',
                            ],
                            [
                                'hours',
                                '2:30 or 2.5 (both are two and a half hours)',
                            ],
                            [
                                'project',
                                'Matched to an existing project, or created',
                            ],
                            ['billable', 'yes / no, true / false or 1 / 0'],
                        ].map(([name, desc]) => (
                            <div
                                key={name}
                                className="cf-tr imp-fmt-row"
                                role="row"
                            >
                                <span
                                    role="cell"
                                    className="t-mono text-[13px]"
                                >
                                    {name}
                                </span>
                                <span role="cell" className="t-body t-muted">
                                    {desc}
                                </span>
                            </div>
                        ))}
                    </div>
                    <pre className="cf-inset t-mono imp-sample">
                        {
                            'developer,date,hours,project,billable\nRohan Desai,1-Apr-26,2:30,Saarthi portal,yes\nSana Qureshi,1-Apr-26,8,Saarthi portal,yes'
                        }
                    </pre>
                    <a
                        href={templateCsv().url}
                        className="cf-btn cf-btn--secondary cf-btn--sm self-start"
                        download
                    >
                        <Icon icon={Download} size={16} />
                        Download template
                    </a>
                </section>
                <section className="cf-card imp-upload" aria-label="Upload">
                    {file ? (
                        <FileRow
                            name={file.name}
                            size={file.size}
                            status="done"
                            removeLabel="Remove file"
                            onRemove={() => {
                                setFile(null);
                                setError(null);
                            }}
                        />
                    ) : (
                        <FileDrop
                            aria-label="Upload timesheet CSV"
                            accept=".csv"
                            maxSize={MAX_BYTES}
                            limits=".csv up to 5 MB · up to 5,000 rows"
                            rejectHint="Upload a .csv file up to 5 MB."
                            onFiles={([chosen]) => {
                                setFile(chosen ?? null);
                                setError(null);
                            }}
                            onReject={() =>
                                setError('Upload a .csv file up to 5 MB.')
                            }
                            error={error ?? undefined}
                        />
                    )}
                    {file && error && <FieldError>{error}</FieldError>}
                </section>
            </div>
            <ImportFooter
                back={
                    <Button
                        variant="secondary"
                        size="lg"
                        onClick={() => router.visit(timesheetsIndex().url)}
                    >
                        Cancel
                    </Button>
                }
            >
                <Button
                    variant="primary"
                    size="lg"
                    disc={ArrowRight}
                    disabled={!file}
                    loading={sending}
                    onClick={next}
                >
                    Next: map columns
                </Button>
            </ImportFooter>
        </>
    );
}

const MATCH_PILL: Record<ImportColumn['how'], { label: string; ok: boolean }> =
    {
        auto: { label: 'Auto-matched', ok: true },
        values: { label: 'Matched by name', ok: true },
        chosen: { label: 'Chosen', ok: true },
        skipped: { label: 'Skipped', ok: false },
    };

/** Step 2 (BulkImport.dc.html "Map columns"): one Coffer field per CSV column. */
export function MapStep({ data }: { data: ImportData }) {
    const columns = data.columns ?? [];
    const [fields, setFields] = useState<(ImportField | null)[]>(() =>
        columns.map((c) => c.field),
    );
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const required: ImportField[] = ['member', 'date', 'hours', 'project'];
    const missing = required.filter((f) => !fields.includes(f));

    const how = (i: number): ImportColumn['how'] => {
        if (fields[i] === null) {
            return 'skipped';
        }

        return fields[i] === columns[i].field ? columns[i].how : 'chosen';
    };

    const pick = (i: number, value: ImportField | null) =>
        setFields((fs) =>
            fs.map((f, j) => {
                if (j === i) {
                    return value;
                }

                // A field comes from one column only: take it off the other one.
                return value !== null && f === value ? null : f;
            }),
        );

    const next = () =>
        router.put(
            saveMapping(data.id).url,
            { mapping: fields },
            {
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (bag) => setError(bag.mapping ?? 'Check the columns.'),
            },
        );

    return (
        <>
            <section
                className="cf-card flex flex-col gap-3.5"
                aria-labelledby="mc-h"
            >
                <div className="cf-kv flex-wrap gap-2">
                    <h2 id="mc-h" className="cf-dot-title t-title">
                        Match your columns
                    </h2>
                    <StatusPill tone="pos" icon={Check}>
                        {data.autoMatched} of {columns.length} matched
                        automatically
                    </StatusPill>
                </div>
                <div className="cf-table-wrap">
                    <div
                        className="cf-table min-w-[820px]"
                        role="table"
                        aria-label="Column mapping"
                    >
                        <div className="cf-tr cf-thead mcols" role="row">
                            <span role="columnheader">
                                Column in {data.name}
                            </span>
                            <span role="columnheader">
                                <span className="cf-sr">Maps to</span>
                            </span>
                            <span role="columnheader">Coffer field</span>
                            <span role="columnheader">Sample values</span>
                            <span role="columnheader">Match</span>
                        </div>
                        {columns.map((c, i) => {
                            const pill = MATCH_PILL[how(i)];

                            return (
                                <div
                                    key={c.index}
                                    className="cf-tr mcols"
                                    role="row"
                                >
                                    <span
                                        role="cell"
                                        className="t-mono text-[13px]"
                                    >
                                        {c.name || `Column ${i + 1}`}
                                    </span>
                                    <span role="cell" className="t-muted">
                                        <Icon icon={ArrowRight} size={16} />
                                    </span>
                                    <span role="cell">
                                        <select
                                            className="cf-select h-10"
                                            aria-label={`Coffer field for ${c.name || `column ${i + 1}`}`}
                                            value={fields[i] ?? ''}
                                            onChange={(e) =>
                                                pick(
                                                    i,
                                                    (e.target.value ||
                                                        null) as ImportField | null,
                                                )
                                            }
                                        >
                                            {(
                                                Object.keys(
                                                    FIELD_LABELS,
                                                ) as ImportField[]
                                            ).map((f) => (
                                                <option key={f} value={f}>
                                                    {FIELD_LABELS[f]}
                                                </option>
                                            ))}
                                            <option value="">
                                                Don’t import
                                            </option>
                                        </select>
                                    </span>
                                    <span
                                        role="cell"
                                        className="t-body t-muted truncate"
                                    >
                                        {c.samples.join(', ') || '—'}
                                    </span>
                                    <span role="cell">
                                        <span
                                            className={
                                                pill.ok
                                                    ? 'cf-pill cf-pill--pos'
                                                    : 'cf-pill'
                                            }
                                        >
                                            {pill.ok && (
                                                <Icon icon={Check} size={13} />
                                            )}
                                            {pill.label}
                                        </span>
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
                {(data.notes ?? []).map((note) => (
                    <p key={note} className="t-caption t-muted m-0">
                        {note}
                    </p>
                ))}
                {missing.length > 0 && (
                    <FieldError>
                        Match a column to{' '}
                        {missing.map((f) => FIELD_LABELS[f]).join(', ')} to go
                        on.
                    </FieldError>
                )}
                {error && missing.length === 0 && (
                    <FieldError>{error}</FieldError>
                )}
            </section>
            <ImportFooter
                back={
                    <Button
                        variant="secondary"
                        size="lg"
                        icon={ArrowLeft}
                        onClick={() => router.delete(removeImport(data.id).url)}
                    >
                        Back
                    </Button>
                }
            >
                <Button
                    variant="primary"
                    size="lg"
                    disc={ArrowRight}
                    disabled={missing.length > 0}
                    loading={saving}
                    onClick={next}
                >
                    Next: preview
                </Button>
            </ImportFooter>
        </>
    );
}

/** Step 3 (BulkImport.dc.html "Preview & import"), then the queued import's progress and result. */
export function PreviewStep({ data }: { data: ImportData }) {
    const toast = useToast();
    const [committing, setCommitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const lastStatus = useRef(data.status);

    // While the queued import runs, check on it every 1.5 s.
    useEffect(() => {
        if (data.status !== 'queued') {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['import'] }),
            1500,
        );

        return () => window.clearInterval(timer);
    }, [data.status]);

    useEffect(() => {
        if (
            lastStatus.current === 'queued' &&
            data.status === 'done' &&
            data.result
        ) {
            toast.success(
                `${formatIndianNumber(data.result.imported)} entries imported · ${formatCenti(data.result.centi)} h added`,
            );
        }

        lastStatus.current = data.status;
    }, [data.status, data.result, toast]);

    if (
        data.status === 'queued' ||
        data.status === 'done' ||
        data.status === 'failed'
    ) {
        return <ImportResult data={data} />;
    }

    const summary = data.summary!;
    const preview = data.preview!;
    const show = (filter: string, page = 1) =>
        router.get(
            importPage().url,
            {
                import: data.id,
                step: 'preview',
                show: filter === 'all' ? undefined : filter,
                page: page > 1 ? page : undefined,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['import'],
            },
        );

    const columns: DataTableColumn<PreviewRow>[] = [
        {
            key: 'row',
            header: 'Row',
            width: '60px',
            cell: (r) => <span className="t-money-sm t-muted">{r.number}</span>,
        },
        {
            key: 'member',
            header: 'Member',
            width: 'minmax(150px,1fr)',
            cell: (r) => (
                <span className="t-label-strong">{r.member || '—'}</span>
            ),
        },
        {
            key: 'date',
            header: 'Date',
            width: '130px',
            cell: (r) => <span className="t-body">{r.date || '—'}</span>,
        },
        {
            key: 'hours',
            header: 'Hours',
            width: '80px',
            numeric: true,
            cell: (r) => r.hours || '—',
        },
        {
            key: 'project',
            header: 'Project',
            width: 'minmax(140px,1fr)',
            cell: (r) => <span className="t-body">{r.project || '—'}</span>,
        },
        {
            key: 'billable',
            header: 'Billable',
            width: '100px',
            cell: (r) => (
                <span className="t-body">{r.billable ? 'Yes' : 'No'}</span>
            ),
        },
        {
            key: 'check',
            header: 'Check',
            width: 'minmax(220px,1.4fr)',
            cell: (r) =>
                r.error ? (
                    <span className="cf-row cf-errtext gap-1.5">
                        <Icon icon={AlertTriangle} size={14} />
                        {r.error}
                    </span>
                ) : (
                    <span className="cf-row gap-1.5">
                        <Icon icon={Check} size={14} />
                        OK
                    </span>
                ),
        },
    ];

    const commit = () =>
        router.post(
            commitImport(data.id).url,
            {},
            {
                onStart: () => setCommitting(true),
                onFinish: () => setCommitting(false),
                onError: (bag) =>
                    setError(bag.import ?? 'The import couldn’t start.'),
            },
        );

    return (
        <>
            <section className="imp-kpis" aria-label="Import summary">
                <KpiTile
                    headingLevel="h2"
                    title="Rows in file"
                    icon={FileUp}
                    value={
                        <span className="t-money-lg">
                            {formatIndianNumber(summary.rows)}
                        </span>
                    }
                    countUp={false}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Ready to import"
                    icon={Check}
                    aside={<StatusPill tone="pos">OK</StatusPill>}
                    value={
                        <span className="t-money-lg">
                            {formatIndianNumber(summary.ok)}
                        </span>
                    }
                    countUp={false}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Need a fix"
                    icon={AlertTriangle}
                    aside={
                        summary.errors > 0 ? (
                            <StatusPill tone="neg">Errors</StatusPill>
                        ) : undefined
                    }
                    value={
                        <span className="t-money-lg">
                            {formatIndianNumber(summary.errors)}
                        </span>
                    }
                    countUp={false}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Hours to add"
                    icon={RotateCw}
                    hours={summary.okCenti / 100}
                    countUp={false}
                />
            </section>

            <section
                className="cf-card flex flex-col gap-3.5"
                aria-labelledby="pv-h"
            >
                <div className="cf-kv flex-wrap gap-2">
                    <h2 id="pv-h" className="cf-dot-title t-title">
                        Preview
                    </h2>
                    <FilterChips
                        aria-label="Show rows"
                        value={preview.filter}
                        onValueChange={(f) => show(f)}
                        options={[
                            { value: 'all', label: 'All', count: summary.rows },
                            { value: 'ok', label: 'OK', count: summary.ok },
                            {
                                value: 'errors',
                                label: 'Errors',
                                count: summary.errors,
                            },
                        ]}
                    />
                </div>
                <DataTable
                    aria-label="Import preview"
                    rows={preview.rows}
                    columns={columns}
                    getRowId={(r) => String(r.number)}
                    rowError={(r) => r.error !== null}
                    minWidth={980}
                    empty={{
                        title:
                            preview.filter === 'errors'
                                ? 'No rows need a fix'
                                : 'No rows',
                    }}
                />
                {preview.pages > 1 && (
                    <div className="cf-row justify-end">
                        <Pager
                            page={preview.page}
                            pageCount={preview.pages}
                            onPageChange={(p) => show(preview.filter, p)}
                        />
                    </div>
                )}
                {summary.newProjects.length > 0 && (
                    <p className="t-caption t-muted m-0">
                        New{' '}
                        {summary.newProjects.length === 1
                            ? 'project'
                            : 'projects'}{' '}
                        to create: {summary.newProjects.join(', ')}.
                    </p>
                )}
                {error && <FieldError>{error}</FieldError>}
            </section>

            <ImportFooter
                back={
                    <Button
                        variant="secondary"
                        size="lg"
                        icon={ArrowLeft}
                        onClick={() =>
                            router.get(importPage().url, {
                                import: data.id,
                                step: 'map',
                            })
                        }
                    >
                        Back
                    </Button>
                }
            >
                {summary.errors > 0 && (
                    <span className="t-caption t-muted">
                        {summary.errors} {summary.errors === 1 ? 'row' : 'rows'}{' '}
                        with errors will be skipped.{' '}
                        <a href={errorsCsv(data.id).url} download>
                            Download them
                        </a>{' '}
                        to fix and re-import.
                    </span>
                )}
                <Button
                    variant="primary"
                    size="lg"
                    disc={ArrowRight}
                    disabled={summary.ok === 0}
                    loading={committing}
                    onClick={commit}
                >
                    Import {formatIndianNumber(summary.ok)}{' '}
                    {summary.ok === 1 ? 'row' : 'rows'}
                </Button>
            </ImportFooter>
        </>
    );
}

function ImportResult({ data }: { data: ImportData }) {
    const result = data.result!;

    return (
        <>
            <section
                className="cf-card flex flex-col items-start gap-3.5"
                aria-live="polite"
            >
                {data.status === 'queued' && (
                    <div className="cf-row gap-3">
                        <Spinner />
                        <div className="cf-stack">
                            <h2 className="t-title m-0">
                                Importing {data.name}…
                            </h2>
                            <span className="t-caption t-muted">
                                You can leave this page; the import keeps going.
                            </span>
                        </div>
                    </div>
                )}
                {data.status === 'done' && (
                    <>
                        <StatusPill tone="pos" icon={Check}>
                            Imported
                        </StatusPill>
                        <h2 className="t-heading m-0">
                            {formatIndianNumber(result.imported)} entries
                            imported · {formatCenti(result.centi)} h added
                        </h2>
                        {result.skipped > 0 && (
                            <p className="t-body t-muted m-0">
                                {result.skipped}{' '}
                                {result.skipped === 1 ? 'row was' : 'rows were'}{' '}
                                skipped.{' '}
                                {result.hasErrors && (
                                    <>
                                        <a
                                            href={errorsCsv(data.id).url}
                                            download
                                        >
                                            Download them
                                        </a>{' '}
                                        to fix and re-import.
                                    </>
                                )}
                            </p>
                        )}
                    </>
                )}
                {data.status === 'failed' && (
                    <>
                        <StatusPill tone="neg" icon={AlertTriangle}>
                            Failed
                        </StatusPill>
                        <p className="t-body m-0">{result.failure}</p>
                    </>
                )}
            </section>
            <ImportFooter
                back={
                    <Button
                        variant="secondary"
                        size="lg"
                        onClick={() => router.visit(importPage().url)}
                    >
                        Import another file
                    </Button>
                }
            >
                <Button
                    variant="primary"
                    size="lg"
                    disc={ArrowRight}
                    disabled={data.status === 'queued'}
                    onClick={() => router.visit(timesheetsIndex().url)}
                >
                    Go to timesheets
                </Button>
            </ImportFooter>
        </>
    );
}

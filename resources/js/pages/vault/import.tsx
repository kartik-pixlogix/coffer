import { Head, router } from '@inertiajs/react';
import { ArrowRight, Check, Copy, TriangleAlert, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { ImportFooter } from '@/components/timesheets/import-steps';
import { Button, ButtonLink } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { FileDrop, FileRow } from '@/components/ui/file-drop';
import { PasswordField } from '@/components/ui/password-field';
import { Select } from '@/components/ui/select';
import { StatusPill } from '@/components/ui/status-pill';
import { Stepper } from '@/components/ui/stepper';
import { VaultGate } from '@/components/vault/vault-gate';
import { formatIndianNumber } from '@/lib/format';
import {
    importMethod as importPage,
    index as vaultIndex,
} from '@/routes/vault';
import {
    commit,
    destroy,
    mapping as saveMapping,
    store,
} from '@/routes/vault/import';
import type {
    VaultImportData,
    VaultImportPreview,
    VaultImportProps,
} from '@/types/vault';

const STEPS = ['upload', 'map', 'preview'] as const;

const FIELD_LABEL: Record<string, string> = {
    name: 'Name',
    url: 'URL',
    username: 'Username or email',
    password: 'Password',
    totp: '2FA setup key',
    notes: 'Notes',
    tags: 'Tags',
};

const FORMATS: [string, string][] = [
    ['Bitwarden', 'JSON (not encrypted) or CSV export'],
    ['1Password', 'CSV export'],
    ['LastPass', 'CSV export'],
    ['Chrome', 'Passwords › Export passwords (CSV)'],
    ['Any CSV', 'A header row; you match the columns next'],
    ['Coffer', 'An encrypted export, with its passphrase'],
];

/**
 * Vault › Import (spec §14.4, BulkImport's steps): Upload → Map columns (plain CSV only)
 * → Preview & import. The preview lists names and which secrets came in, never a value;
 * duplicates (same name, username and password fingerprint) are skipped and reported.
 */
export default function VaultImportPage(props: VaultImportProps) {
    const { step, import: data } = props;
    const current = step === 'done' ? 2 : STEPS.indexOf(step);

    return (
        <>
            <Head title="Import" />
            <PageHeader
                kicker="Vault"
                title="Import"
                crumbs={[
                    { title: 'Credentials Vault', href: vaultIndex().url },
                    { title: 'Import' },
                ]}
            />
            <VaultGate vaultState={props.vaultState}>
                <div className="cf-stack vi-page">
                    <Stepper
                        aria-label="Import steps"
                        steps={['Upload', 'Map columns', 'Preview & import']}
                        current={current}
                    />
                    {step === 'upload' && <UploadStep {...props} />}
                    {step === 'map' && data && (
                        <MapStep
                            key={data.id}
                            data={data}
                            fields={props.fields}
                        />
                    )}
                    {step === 'preview' && data && (
                        <PreviewStep
                            data={data}
                            preview={props.preview}
                            error={props.error}
                        />
                    )}
                    {step === 'done' && data && <DoneStep data={data} />}
                </div>
            </VaultGate>
        </>
    );
}

function UploadStep({ vaults, maxKb }: VaultImportProps) {
    const [file, setFile] = useState<File | null>(null);
    const [vaultId, setVaultId] = useState(String(vaults[0]?.id ?? ''));
    const [passphrase, setPassphrase] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [sending, setSending] = useState(false);
    const isJson = file?.name.toLowerCase().endsWith('.json') ?? false;

    const next = () => {
        if (!file) {
            return;
        }

        router.post(
            store().url,
            { file, vault_id: vaultId, passphrase: isJson ? passphrase : '' },
            {
                forceFormData: true,
                onStart: () => setSending(true),
                onFinish: () => {
                    setSending(false);
                    setPassphrase('');
                },
                onError: (bag) => setErrors(bag),
            },
        );
    };

    return (
        <>
            <div className="imp-cols">
                <section className="cf-card imp-format" aria-labelledby="vi-f">
                    <h2 id="vi-f" className="cf-dot-title t-title">
                        What you can import
                    </h2>
                    <div className="cf-table" role="table" aria-label="Formats">
                        {FORMATS.map(([name, desc]) => (
                            <div
                                key={name}
                                className="cf-tr imp-fmt-row"
                                role="row"
                            >
                                <span role="cell" className="t-label-strong">
                                    {name}
                                </span>
                                <span role="cell" className="t-body t-muted">
                                    {desc}
                                </span>
                            </div>
                        ))}
                    </div>
                    <p className="t-caption t-muted m-0">
                        Coffer works out the format. The file is encrypted the
                        moment it arrives and deleted once the import is done.
                        Delete your own copy too: it holds passwords in plain
                        text.
                    </p>
                </section>
                <section
                    className="cf-card cf-stack imp-upload vi-upload"
                    aria-label="Upload"
                >
                    <Select
                        label="Import into"
                        value={vaultId}
                        onChange={(e) => setVaultId(e.target.value)}
                        options={vaults.map((v) => ({
                            value: String(v.id),
                            label: `${v.name} vault`,
                        }))}
                        error={errors.vault_id}
                    />
                    {file ? (
                        <FileRow
                            name={file.name}
                            size={file.size}
                            status="done"
                            removeLabel="Remove file"
                            onRemove={() => {
                                setFile(null);
                                setErrors({});
                            }}
                        />
                    ) : (
                        <FileDrop
                            aria-label="Upload a file to import"
                            title="Drop the export here"
                            accept=".csv,.json,.txt"
                            maxSize={maxKb * 1024}
                            limits=".csv or .json up to 5 MB · up to 5,000 items"
                            rejectHint="Upload a .csv or .json file up to 5 MB."
                            onFiles={([chosen]) => {
                                setFile(chosen ?? null);
                                setErrors({});
                            }}
                            onReject={() =>
                                setErrors({
                                    file: 'Upload a .csv or .json file up to 5 MB.',
                                })
                            }
                            error={errors.file}
                        />
                    )}
                    {file && errors.file && (
                        <FieldError>{errors.file}</FieldError>
                    )}
                    {isJson && (
                        <PasswordField
                            label="Passphrase (Coffer exports only)"
                            value={passphrase}
                            onChange={(e) =>
                                setPassphrase(e.currentTarget.value)
                            }
                            autoComplete="off"
                            error={errors.passphrase}
                            help="Leave empty for a Bitwarden JSON file."
                        />
                    )}
                </section>
            </div>
            <ImportFooter
                back={
                    <ButtonLink
                        href={vaultIndex().url}
                        variant="secondary"
                        size="lg"
                    >
                        Cancel
                    </ButtonLink>
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
                    Next
                </Button>
            </ImportFooter>
        </>
    );
}

function MapStep({
    data,
    fields,
}: {
    data: VaultImportData;
    fields: string[];
}) {
    const header = data.header ?? [];
    const [map, setMap] = useState<(string | null)[]>(
        header.map((_, i) => data.mapping?.[i] ?? null),
    );
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const save = () =>
        router.put(
            saveMapping(data.id).url,
            { mapping: map },
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (bag) =>
                    setError(bag.mapping ?? Object.values(bag)[0] ?? null),
            },
        );

    return (
        <>
            <section
                className="cf-card cf-stack vi-card"
                aria-labelledby="vi-m"
            >
                <h2 id="vi-m" className="cf-dot-title t-title">
                    Match the columns of {data.name}
                </h2>
                <p className="t-caption t-muted m-0">
                    {formatIndianNumber(data.rows)} rows into the {data.vault}{' '}
                    vault. Leave a column as “Skip” to leave it out.
                </p>
                <div className="vi-map">
                    {header.map((column, i) => (
                        <div key={`${column}-${i}`} className="vi-map-row">
                            <span className="t-label-strong vi-col">
                                {column || `Column ${i + 1}`}
                            </span>
                            <Select
                                aria-label={`Coffer field for ${column || `column ${i + 1}`}`}
                                value={map[i] ?? ''}
                                onChange={(e) => {
                                    const next = [...map];
                                    next[i] = e.target.value || null;
                                    setMap(next);
                                    setError(null);
                                }}
                                options={[
                                    { value: '', label: 'Skip' },
                                    ...fields.map((f) => ({
                                        value: f,
                                        label: FIELD_LABEL[f] ?? f,
                                    })),
                                ]}
                            />
                        </div>
                    ))}
                </div>
                {error && <FieldError>{error}</FieldError>}
            </section>
            <ImportFooter back={<CancelButton id={data.id} />}>
                <Button
                    variant="primary"
                    size="lg"
                    disc={ArrowRight}
                    loading={busy}
                    onClick={save}
                >
                    Next: preview
                </Button>
            </ImportFooter>
        </>
    );
}

function CancelButton({ id }: { id: number }) {
    return (
        <Button
            variant="secondary"
            size="lg"
            onClick={() => router.delete(destroy(id).url)}
        >
            Cancel import
        </Button>
    );
}

const STATUS = {
    new: { tone: 'pos', label: 'New', icon: Check },
    duplicate: { tone: 'neutral', label: 'Duplicate', icon: Copy },
    invalid: { tone: 'neg', label: 'Can’t import', icon: TriangleAlert },
} as const;

function PreviewStep({
    data,
    preview,
    error,
}: {
    data: VaultImportData;
    preview: VaultImportPreview | null;
    error: string | null;
}) {
    const [busy, setBusy] = useState(false);
    const counts = preview?.counts;

    return (
        <>
            <section
                className="cf-card cf-stack vi-card"
                aria-labelledby="vi-p"
            >
                <div className="cf-kv vi-head">
                    <h2 id="vi-p" className="cf-dot-title t-title">
                        {data.name} · {data.formatLabel}
                    </h2>
                    {counts && (
                        <span className="cf-row vi-counts">
                            <StatusPill tone="pos" icon={Check} size="sm">
                                {counts.new} new
                            </StatusPill>
                            <StatusPill tone="neutral" icon={Copy} size="sm">
                                {counts.duplicate} duplicates
                            </StatusPill>
                            <StatusPill
                                tone={
                                    counts.invalid + counts.skipped
                                        ? 'neg'
                                        : 'neutral'
                                }
                                icon={TriangleAlert}
                                size="sm"
                            >
                                {counts.invalid + counts.skipped} skipped
                            </StatusPill>
                        </span>
                    )}
                </div>
                {error && <FieldError>{error}</FieldError>}
                {preview && (
                    <div className="cf-table-wrap">
                        <div
                            className="cf-table vi-table"
                            role="table"
                            aria-label="Items to import"
                        >
                            <div className="cf-tr cf-thead vi-cols" role="row">
                                <span role="columnheader">Row</span>
                                <span role="columnheader">Item</span>
                                <span role="columnheader">Type</span>
                                <span role="columnheader">Comes with</span>
                                <span role="columnheader">Status</span>
                            </div>
                            {preview.rows.map((r) => {
                                const s = STATUS[r.status];

                                return (
                                    <div
                                        key={r.line}
                                        className="cf-tr vi-cols"
                                        role="row"
                                    >
                                        <span
                                            role="cell"
                                            className="t-money-sm t-muted"
                                        >
                                            {r.line}
                                        </span>
                                        <span role="cell" className="cf-stack">
                                            <span className="t-label-strong">
                                                {r.name}
                                            </span>
                                            <span className="t-caption t-muted">
                                                {[r.subtitle, r.url]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </span>
                                        <span role="cell" className="t-body">
                                            {r.typeLabel}
                                        </span>
                                        <span
                                            role="cell"
                                            className="t-caption t-muted"
                                        >
                                            {[
                                                ...r.secrets,
                                                ...(r.customFields
                                                    ? [
                                                          `${r.customFields} custom ${r.customFields === 1 ? 'field' : 'fields'}`,
                                                      ]
                                                    : []),
                                                ...r.notes,
                                            ].join(' · ') || '—'}
                                        </span>
                                        <span role="cell" className="cf-stack">
                                            <StatusPill
                                                tone={s.tone}
                                                icon={s.icon}
                                                size="sm"
                                            >
                                                {s.label}
                                            </StatusPill>
                                            {r.reason && r.status !== 'new' && (
                                                <span className="t-caption t-muted">
                                                    {r.reason}
                                                </span>
                                            )}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
                {preview && preview.skipped.length > 0 && (
                    <p className="t-caption t-muted m-0">
                        Not imported:{' '}
                        {preview.skipped
                            .map((s) => `row ${s.line} (${s.reason})`)
                            .join(', ')}
                        .
                    </p>
                )}
            </section>
            <ImportFooter back={<CancelButton id={data.id} />}>
                <Button
                    variant="primary"
                    size="lg"
                    disc={Upload}
                    disabled={!counts || counts.new === 0}
                    loading={busy}
                    onClick={() =>
                        router.post(
                            commit(data.id).url,
                            {},
                            {
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                            },
                        )
                    }
                >
                    {counts
                        ? `Import ${counts.new} ${counts.new === 1 ? 'item' : 'items'}`
                        : 'Import'}
                </Button>
            </ImportFooter>
        </>
    );
}

function DoneStep({ data }: { data: VaultImportData }) {
    const running = data.status === 'queued' || data.status === 'running';
    const s = data.summary ?? {};

    // A queued import: look again every two seconds until it's done.
    useEffect(() => {
        if (!running) {
            return;
        }

        const t = window.setInterval(
            () => router.reload({ only: ['import', 'step'] }),
            2000,
        );

        return () => window.clearInterval(t);
    }, [running]);

    return (
        <section className="cf-card cf-stack vi-card" aria-live="polite">
            <h2 className="cf-dot-title t-title">
                {running
                    ? `Importing ${data.name}…`
                    : data.status === 'done'
                      ? `Imported ${s.created ?? 0} ${s.created === 1 ? 'item' : 'items'} into ${data.vault}`
                      : data.status === 'cancelled'
                        ? 'Import cancelled'
                        : 'The import stopped'}
            </h2>
            {s.error && <FieldError>{s.error}</FieldError>}
            {(s.duplicates?.length ?? 0) > 0 && (
                <p className="t-body m-0">
                    Skipped {s.duplicates?.length} already in the vault:{' '}
                    {s.duplicates
                        ?.map((d) => `${d.name} (row ${d.line})`)
                        .join(', ')}
                    .
                </p>
            )}
            {(s.skipped?.length ?? 0) > 0 && (
                <p className="t-body t-muted m-0">
                    Not imported:{' '}
                    {s.skipped
                        ?.map((d) => `row ${d.line} (${d.reason})`)
                        .join(', ')}
                    .
                </p>
            )}
            {!running && (
                <p className="t-caption t-muted m-0">
                    The uploaded file has been deleted from Coffer.
                </p>
            )}
            <div className="cf-row vi-done">
                <ButtonLink href={vaultIndex().url} variant="primary">
                    Open the vault
                </ButtonLink>
                <ButtonLink href={importPage().url} variant="secondary">
                    Import another file
                </ButtonLink>
            </div>
        </section>
    );
}

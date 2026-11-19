import { Head, router } from '@inertiajs/react';
import { Check, Lock, LockOpen } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CompanyLifecycleCard } from '@/components/settings/company-lifecycle';
import type { Lifecycle } from '@/components/settings/company-lifecycle';
import { SettingsShell } from '@/components/settings/settings-shell';
import { AlertBanner } from '@/components/ui/alert-banner';
import { AmountInput } from '@/components/ui/amount-input';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { FieldError } from '@/components/ui/field';
import { FilterChip, FilterChipGroup } from '@/components/ui/filter-chips';
import { Icon } from '@/components/ui/icon';
import { Select } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { TextInput } from '@/components/ui/text-input';
import { Toggle } from '@/components/ui/toggle';
import { formatDate, formatInr } from '@/lib/format';
import { SATURDAY, workingDays } from '@/lib/hours';
import {
    close as closeYear,
    reopen as reopenYear,
    update as updateCompany,
} from '@/routes/settings/company';
import {
    destroy as removeLogo,
    store as uploadLogo,
} from '@/routes/settings/company/logo';

type Settings = {
    name: string;
    legalName: string | null;
    gstin: string | null;
    address: string | null;
    shortCode: string;
    type: 'single' | 'jv';
    sides: string[];
    openingBalancePaise: number;
    currency: string;
    attributionMode: 'per_partner' | 'jv_pooled';
    fyStartMonth: number;
    workingDays: number;
    hoursPerDayCenti: number;
    logoUrl: string | null;
};

type FiscalYearRow = {
    id: number;
    label: string;
    startsOn: string;
    endsOn: string;
    closed: boolean;
    closedOn: string | null;
    closedBy: string | null;
    entries: number;
    closable: boolean;
    reopenable: boolean;
};

type ClosingPreview = {
    yearId: number;
    label: string;
    phrase: string;
    startsOn: string;
    endsOn: string;
    nextLabel: string;
    netPaise: number;
    partners: {
        id: number;
        name: string;
        side: string | null;
        openingPaise: number;
        contributionsPaise: number;
        drawingsPaise: number;
        sharePaise: number;
        closingPaise: number;
    }[];
    early: boolean;
    unsettledPaise: number;
    pendingExpenses: number;
};

type Props = {
    settings: Settings;
    locks: { opening: boolean; currency: boolean; fyStart: boolean };
    years: FiscalYearRow[];
    /** Loaded when the Close dialog opens (spec §4.6 preview). */
    closing?: ClosingPreview | null;
    abilities: { closeYear: boolean; reopenYear: boolean };
    /** Archive and delete (V4, D139–D141). */
    lifecycle: Lifecycle;
};

type Form = {
    name: string;
    legal_name: string;
    gstin: string;
    address: string;
    short_code: string;
    opening_balance: number;
    currency: string;
    attribution_mode: string;
    fy_start_month: number;
    working_days: number;
    hours_per_day: string;
};

const toForm = (s: Settings): Form => ({
    name: s.name,
    legal_name: s.legalName ?? '',
    gstin: s.gstin ?? '',
    address: s.address ?? '',
    short_code: s.shortCode,
    opening_balance: s.openingBalancePaise,
    currency: s.currency,
    attribution_mode: s.attributionMode,
    fy_start_month: s.fyStartMonth,
    working_days: s.workingDays,
    hours_per_day: String(s.hoursPerDayCenti / 100),
});

/** Monday first, as the design lists them; bits Monday = 1 … Sunday = 64. */
const DAYS = [
    ['Mon', 1],
    ['Tue', 2],
    ['Wed', 4],
    ['Thu', 8],
    ['Fri', 16],
    ['Sat', 32],
    ['Sun', 64],
] as const;

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const ATTRIBUTION = [
    {
        value: 'per_partner',
        title: 'Per partner',
        body: 'Income counts toward the partner who brought it in. Reports show each partner’s net contribution.',
    },
    {
        value: 'jv_pooled',
        title: 'JV pooled',
        body: 'All income goes into one pool. Profit follows equity only.',
    },
] as const;

/**
 * Settings › Company (SettingsCompany.dc.html, spec §3.2, §4.6): details and logo, money and
 * attribution, working days, and fiscal years with Close (preview, type-to-confirm) and
 * Reopen (Admin only). The opening balance locks once a year is closed, the currency once
 * the books have transactions (D63).
 */
export default function CompanySettings({
    settings,
    locks,
    years,
    closing,
    abilities,
    lifecycle,
}: Props) {
    const [form, setForm] = useState<Form>(() => toForm(settings));
    const [base, setBase] = useState(settings);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [closingId, setClosingId] = useState<number | null>(null);
    const [reopening, setReopening] = useState<FiscalYearRow | null>(null);
    const [busy, setBusy] = useState(false);
    const [closeError, setCloseError] = useState<string | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);

    // Fresh settings from the server (after saving) become the new baseline.
    if (settings !== base) {
        setBase(settings);
        setForm(toForm(settings));
    }

    const initial = toForm(base);
    const dirty = (Object.keys(form) as (keyof Form)[]).some(
        (k) => form[k] !== initial[k],
    );
    const set = (patch: Partial<Form>) => setForm((f) => ({ ...f, ...patch }));

    const save = (event?: FormEvent) => {
        event?.preventDefault();

        if (!dirty) {
            return;
        }

        router.put(
            updateCompany().url,
            {
                ...form,
                opening_balance: (form.opening_balance / 100).toFixed(2),
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setErrors({}),
                onError: (e) => setErrors(e),
            },
        );
    };

    const now = new Date();
    const monthDays = workingDays(
        now.getFullYear(),
        now.getMonth(),
        form.working_days,
    ).length;
    const perWeek = DAYS.filter(([, bit]) => form.working_days & bit).length;
    const hours = Number(form.hours_per_day) || 0;
    const monthName = now.toLocaleString('en-IN', {
        month: 'long',
        year: 'numeric',
    });

    const openClose = (row: FiscalYearRow) => {
        setCloseError(null);
        setClosingId(row.id);
        router.reload({ only: ['closing'], data: { year: row.id } });
    };

    const preview = closing && closing.yearId === closingId ? closing : null;
    const closingRow = years.find((y) => y.id === closingId) ?? null;

    const columns: DataTableColumn<FiscalYearRow>[] = [
        {
            key: 'year',
            header: 'Year',
            width: 'minmax(140px,1fr)',
            className: 't-label-strong',
            cell: (y) => y.label,
        },
        {
            key: 'dates',
            header: 'Dates',
            width: 'minmax(200px,1.4fr)',
            className: 't-body t-muted',
            cell: (y) => `${formatDate(y.startsOn)} – ${formatDate(y.endsOn)}`,
        },
        {
            key: 'status',
            header: 'Status',
            width: '110px',
            skeleton: 'pill',
            cell: (y) => (
                <span
                    className={`cf-pill ${y.closed ? 'cf-pill--graphite' : 'cf-pill--pos'}`}
                >
                    <Icon icon={y.closed ? Lock : LockOpen} size={12} />
                    {y.closed ? 'Closed' : 'Open'}
                </span>
            ),
        },
        {
            key: 'entries',
            header: 'Entries',
            width: '110px',
            numeric: true,
            cell: (y) => y.entries.toLocaleString('en-IN'),
        },
    ];

    return (
        <>
            <Head title="Company settings" />
            <SettingsShell
                tab="company"
                title="Company"
                crumb="Company"
                actions={
                    <>
                        {dirty && (
                            <>
                                <span className="cf-pill cf-pill--warn">
                                    Unsaved changes
                                </span>
                                <Button
                                    variant="ghost"
                                    size="lg"
                                    onClick={() => {
                                        setForm(toForm(base));
                                        setErrors({});
                                    }}
                                >
                                    Discard
                                </Button>
                            </>
                        )}
                        <Button
                            variant="primary"
                            size="lg"
                            disc={Check}
                            disabled={!dirty || lifecycle.archivedAt !== null}
                            loading={saving}
                            onClick={() => save()}
                        >
                            Save changes
                        </Button>
                    </>
                }
            >
                <form onSubmit={save} className="co-form" noValidate>
                    <section
                        className="cf-card co-card"
                        aria-labelledby="co-details"
                    >
                        <div className="cf-kv">
                            <h2
                                id="co-details"
                                className="cf-dot-title t-title"
                            >
                                Company details
                            </h2>
                            <span className="cf-pill cf-pill--lime">
                                {settings.type === 'jv'
                                    ? 'Joint venture'
                                    : 'Single company'}
                            </span>
                        </div>
                        <div className="cf-row co-logo">
                            {settings.logoUrl ? (
                                <img
                                    src={settings.logoUrl}
                                    alt={`${settings.name} logo`}
                                    className="co-logo-img"
                                />
                            ) : (
                                <Avatar
                                    name={settings.name}
                                    size="xl"
                                    tile
                                    label={false}
                                />
                            )}
                            <div className="cf-stack co-logo-text">
                                <span className="t-caption t-muted">
                                    Logo shows on exported reports. PNG, JPG or
                                    WebP, at least 256 px.
                                </span>
                                <div className="cf-row">
                                    <Button
                                        size="sm"
                                        onClick={() => fileRef.current?.click()}
                                    >
                                        {settings.logoUrl
                                            ? 'Change logo'
                                            : 'Add logo'}
                                    </Button>
                                    {settings.logoUrl && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.delete(
                                                    removeLogo().url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    )}
                                    <input
                                        ref={fileRef}
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        className="cf-sr"
                                        tabIndex={-1}
                                        aria-label="Logo file"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];

                                            if (file) {
                                                router.post(
                                                    uploadLogo().url,
                                                    { logo: file },
                                                    {
                                                        forceFormData: true,
                                                        preserveScroll: true,
                                                        onError: (err) =>
                                                            setErrors(
                                                                (prev) => ({
                                                                    ...prev,
                                                                    ...err,
                                                                }),
                                                            ),
                                                    },
                                                );
                                            }

                                            e.target.value = '';
                                        }}
                                    />
                                </div>
                                {errors.logo && (
                                    <FieldError>{errors.logo}</FieldError>
                                )}
                            </div>
                        </div>
                        <div className="co-grid">
                            <TextInput
                                label="Company name"
                                placeholder="Vertex Labs"
                                value={form.name}
                                onChange={(e) => set({ name: e.target.value })}
                                error={errors.name}
                                required
                            />
                            <TextInput
                                label="Legal name"
                                placeholder="Vertex Labs Private Limited"
                                value={form.legal_name}
                                onChange={(e) =>
                                    set({ legal_name: e.target.value })
                                }
                                error={errors.legal_name}
                            />
                            <TextInput
                                label="GSTIN"
                                placeholder="29ABCDE1234F1Z5"
                                value={form.gstin}
                                onChange={(e) =>
                                    set({ gstin: e.target.value.toUpperCase() })
                                }
                                error={errors.gstin}
                                help="Optional. 15 characters, like 27ABCDE1234F1Z5."
                                className="t-mono"
                                maxLength={15}
                            />
                            <TextInput
                                label="Short code"
                                placeholder="VTX"
                                value={form.short_code}
                                onChange={(e) =>
                                    set({
                                        short_code:
                                            e.target.value.toUpperCase(),
                                    })
                                }
                                error={errors.short_code}
                                help="2–6 letters, unique in the workspace."
                                maxLength={6}
                            />
                            <div className="cf-field">
                                <span className="cf-label" id="co-type">
                                    Company type
                                </span>
                                <span
                                    className="cf-inwrap is-readonly"
                                    aria-labelledby="co-type"
                                >
                                    <Icon icon={Lock} size={16} />
                                    <span className="t-body">
                                        {settings.type === 'jv'
                                            ? `Joint venture${settings.sides.length > 0 ? ` · ${settings.sides.join(' and ')}` : ''}`
                                            : 'Single company'}
                                    </span>
                                </span>
                                <span className="cf-help">
                                    Set when the company was created.
                                </span>
                            </div>
                            <TextInput
                                label="Registered address"
                                placeholder="Street, city, state and PIN"
                                value={form.address}
                                onChange={(e) =>
                                    set({ address: e.target.value })
                                }
                                error={errors.address}
                                fieldClassName="co-wide"
                            />
                        </div>
                    </section>

                    <section
                        className="cf-card co-card"
                        aria-labelledby="co-money"
                    >
                        <h2 id="co-money" className="cf-dot-title t-title">
                            Money and reporting
                        </h2>
                        <div className="co-grid">
                            <AmountInput
                                label="Opening balance"
                                placeholder="0"
                                value={form.opening_balance}
                                onValueChange={(paise) =>
                                    set({ opening_balance: paise ?? 0 })
                                }
                                disabled={locks.opening}
                                error={errors.opening_balance}
                                help={
                                    locks.opening
                                        ? 'Locked: a fiscal year is closed.'
                                        : form.opening_balance !==
                                            initial.opening_balance
                                          ? 'Saving reverses the opening entry and posts the new amount.'
                                          : 'Cash the company held before its first entry in Coffer. It feeds the balance and the balance sheet.'
                                }
                            />
                            <Select
                                label="Currency"
                                value={form.currency}
                                onChange={(e) =>
                                    set({ currency: e.target.value })
                                }
                                disabled={locks.currency}
                                error={errors.currency}
                                options={[
                                    {
                                        value: 'INR',
                                        label: 'INR — Indian rupee (₹)',
                                    },
                                    {
                                        value: 'USD',
                                        label: 'USD — US dollar ($)',
                                    },
                                ]}
                                help={
                                    locks.currency
                                        ? 'Locked once the books have transactions.'
                                        : 'Amounts show as ₹12,34,567, with lakh and crore grouping.'
                                }
                            />
                        </div>
                        <div
                            className="cf-field"
                            role="radiogroup"
                            aria-labelledby="co-attr"
                        >
                            <span className="cf-label" id="co-attr">
                                Income attribution
                            </span>
                            <div className="co-rcards">
                                {ATTRIBUTION.map((a) => (
                                    <label
                                        key={a.value}
                                        className={`cf-radio rcard ${form.attribution_mode === a.value ? 'on' : ''}`}
                                    >
                                        <input
                                            className="cf-sr"
                                            type="radio"
                                            name="attribution_mode"
                                            checked={
                                                form.attribution_mode ===
                                                a.value
                                            }
                                            onChange={() =>
                                                set({
                                                    attribution_mode: a.value,
                                                })
                                            }
                                        />
                                        <span className="cf-radio-dot co-rdot" />
                                        <span className="cf-stack co-rtext">
                                            <span className="t-label-strong">
                                                {a.title}
                                            </span>
                                            <span className="t-caption t-muted">
                                                {a.body}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <span className="cf-help">
                                Changing it re-allocates the profit of open
                                years; closed years keep theirs.
                            </span>
                        </div>
                    </section>

                    <section
                        className="cf-card co-card"
                        aria-labelledby="co-days"
                    >
                        <div className="cf-kv">
                            <h2 id="co-days" className="cf-dot-title t-title">
                                Working days
                            </h2>
                            <span className="t-caption t-muted">
                                {perWeek} days a week
                            </span>
                        </div>
                        <FilterChipGroup aria-label="Working days">
                            {DAYS.map(([label, bit]) => (
                                <FilterChip
                                    key={label}
                                    pressed={(form.working_days & bit) !== 0}
                                    onClick={() =>
                                        set({
                                            working_days:
                                                form.working_days ^ bit,
                                        })
                                    }
                                    className="co-day"
                                >
                                    {label}
                                </FilterChip>
                            ))}
                        </FilterChipGroup>
                        {errors.working_days && (
                            <FieldError>{errors.working_days}</FieldError>
                        )}
                        <Toggle
                            checked={(form.working_days & SATURDAY) !== 0}
                            onCheckedChange={(on) =>
                                set({
                                    working_days: on
                                        ? form.working_days | SATURDAY
                                        : form.working_days & ~SATURDAY,
                                })
                            }
                        >
                            Include Saturdays
                        </Toggle>
                        <div className="co-grid">
                            <TextInput
                                label="Standard day (hours)"
                                placeholder="8"
                                inputMode="decimal"
                                value={form.hours_per_day}
                                onChange={(e) =>
                                    set({ hours_per_day: e.target.value })
                                }
                                error={errors.hours_per_day}
                                help="Used for a full month in month-mode timesheets."
                                className="t-mono"
                            />
                        </div>
                        <p className="cf-help m-0">
                            Timesheets spread monthly hours over these days.{' '}
                            {monthName} has{' '}
                            <span className="t-money-sm">{monthDays}</span>{' '}
                            working days, so a full month is{' '}
                            <span className="t-money-sm">
                                {(monthDays * hours)
                                    .toFixed(1)
                                    .replace(/\.0$/u, '')}
                            </span>{' '}
                            hours.
                        </p>
                    </section>

                    <section
                        className="cf-card co-card"
                        aria-labelledby="co-fy"
                    >
                        <div className="cf-kv co-fy-head">
                            <h2 id="co-fy" className="cf-dot-title t-title">
                                Fiscal year
                            </h2>
                            <Select
                                aria-label="Year starts in"
                                pill
                                value={String(form.fy_start_month)}
                                onChange={(e) =>
                                    set({
                                        fy_start_month: Number(e.target.value),
                                    })
                                }
                                disabled={locks.fyStart}
                                options={MONTHS.map((m, i) => ({
                                    value: String(i + 1),
                                    label: `Starts in ${m}`,
                                }))}
                            />
                        </div>
                        {errors.fy_start_month && (
                            <FieldError>{errors.fy_start_month}</FieldError>
                        )}
                        {errors.year && <FieldError>{errors.year}</FieldError>}
                        <DataTable
                            aria-label="Fiscal years"
                            rows={years}
                            columns={columns}
                            getRowId={(y) => String(y.id)}
                            minWidth={640}
                            actionsHeader="Actions"
                            actionsWidth="200px"
                            rowActions={(y) =>
                                !y.closed ? (
                                    y.closable && abilities.closeYear ? (
                                        <Button
                                            size="sm"
                                            variant="danger"
                                            onClick={() => openClose(y)}
                                        >
                                            Close {y.label}
                                        </Button>
                                    ) : y.closable ? null : (
                                        <span className="t-caption t-muted">
                                            Close earlier years first
                                        </span>
                                    )
                                ) : (
                                    <>
                                        <span className="t-caption t-muted">
                                            Locked{' '}
                                            {y.closedOn
                                                ? formatDate(y.closedOn)
                                                : ''}
                                        </span>
                                        {y.reopenable &&
                                            abilities.reopenYear && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setReopening(y)
                                                    }
                                                >
                                                    Reopen
                                                </Button>
                                            )}
                                    </>
                                )
                            }
                        />
                        <p className="cf-help m-0">
                            Closing a year locks its entries, moves profit into
                            capital accounts and carries balances forward. Only
                            Admins can close or reopen a year.
                        </p>
                    </section>
                </form>

                <CompanyLifecycleCard
                    companyName={base.name}
                    lifecycle={lifecycle}
                />
            </SettingsShell>

            <ConfirmDialog
                open={closingRow !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setClosingId(null);
                    }
                }}
                icon={Lock}
                title={`Close ${closingRow?.label ?? ''}?`}
                description={
                    closingRow
                        ? `Entries dated ${formatDate(closingRow.startsOn)} – ${formatDate(closingRow.endsOn)} lock, profit moves to capital accounts and opening balances roll forward to ${preview?.nextLabel ?? 'the next year'}.`
                        : undefined
                }
                confirmLabel="Close FY"
                confirmPhrase={
                    preview?.phrase ??
                    `CLOSE ${closingRow?.label.toUpperCase() ?? ''}`
                }
                loading={busy}
                onConfirm={() => {
                    if (!closingRow) {
                        return;
                    }

                    router.post(
                        closeYear(closingRow.id).url,
                        {
                            confirm:
                                preview?.phrase ??
                                `CLOSE ${closingRow.label.toUpperCase()}`,
                        },
                        {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                            onSuccess: () => setClosingId(null),
                            onError: (e) =>
                                setCloseError(
                                    e.confirm ?? Object.values(e)[0] ?? null,
                                ),
                        },
                    );
                }}
            >
                {preview === null ? (
                    <div
                        className="cf-stack"
                        style={{ gap: 8 }}
                        aria-busy="true"
                    >
                        <Skeleton variant="line" width="80%" />
                        <Skeleton variant="line" width="60%" />
                        <span className="cf-sr" role="status">
                            Loading the closing preview
                        </span>
                    </div>
                ) : (
                    <>
                        {(preview.early ||
                            preview.unsettledPaise > 0 ||
                            preview.pendingExpenses > 0) && (
                            <AlertBanner variant="warn" slim>
                                {preview.early && (
                                    <span className="t-body block">
                                        The year ends on{' '}
                                        {formatDate(preview.endsOn)}. Closing
                                        now locks it early.
                                    </span>
                                )}
                                {preview.unsettledPaise > 0 && (
                                    <span className="t-body block">
                                        <span className="t-money">
                                            {formatInr(preview.unsettledPaise)}
                                        </span>{' '}
                                        is still owed between sides. It carries
                                        forward if you close now.
                                    </span>
                                )}
                                {preview.pendingExpenses > 0 && (
                                    <span className="t-body block">
                                        {preview.pendingExpenses}{' '}
                                        {preview.pendingExpenses === 1
                                            ? 'expense is'
                                            : 'expenses are'}{' '}
                                        still pending; once closed they can’t be
                                        approved into this year.
                                    </span>
                                )}
                            </AlertBanner>
                        )}
                        <div
                            className="co-preview"
                            role="table"
                            aria-label="Closing preview"
                        >
                            <div className="cf-tr cf-thead co-pcols" role="row">
                                <span role="columnheader">Partner</span>
                                <span role="columnheader" className="cf-num">
                                    Share of profit
                                </span>
                                <span role="columnheader" className="cf-num">
                                    Closing capital
                                </span>
                            </div>
                            {preview.partners.map((p) => (
                                <div
                                    key={p.id}
                                    className="cf-tr co-pcols"
                                    role="row"
                                >
                                    <span role="cell" className="cf-stack">
                                        <span className="t-label-strong">
                                            {p.name}
                                        </span>
                                        {p.side && (
                                            <span className="t-caption t-muted">
                                                {p.side}
                                            </span>
                                        )}
                                    </span>
                                    <span role="cell" className="cf-num">
                                        {formatInr(p.sharePaise)}
                                    </span>
                                    <span role="cell" className="cf-num">
                                        {formatInr(p.closingPaise)}
                                    </span>
                                </div>
                            ))}
                            <div className="cf-tr is-total co-pcols" role="row">
                                <span role="cell">Net profit</span>
                                <span role="cell" className="cf-num">
                                    {formatInr(preview.netPaise)}
                                </span>
                                <span role="cell" className="cf-num">
                                    {formatInr(
                                        preview.partners.reduce(
                                            (s, p) => s + p.closingPaise,
                                            0,
                                        ),
                                    )}
                                </span>
                            </div>
                        </div>
                        <p className="t-caption t-muted m-0">
                            Closing capital becomes {preview.nextLabel}’s
                            opening capital.
                        </p>
                    </>
                )}
                {closeError && <FieldError>{closeError}</FieldError>}
            </ConfirmDialog>

            <ConfirmDialog
                open={reopening !== null}
                onOpenChange={(open) => !open && setReopening(null)}
                icon={LockOpen}
                title={`Reopen ${reopening?.label ?? ''}?`}
                description="Its closing entries are reversed and the year unlocks, so its entries can change again. This is recorded in the activity log."
                confirmLabel="Reopen year"
                loading={busy}
                onConfirm={() => {
                    if (!reopening) {
                        return;
                    }

                    router.post(
                        reopenYear(reopening.id).url,
                        {},
                        {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                            onSuccess: () => setReopening(null),
                            onError: (e) => {
                                setReopening(null);
                                setErrors(e);
                            },
                        },
                    );
                }}
            />
        </>
    );
}

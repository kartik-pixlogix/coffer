import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { VaultGate } from '@/components/vault/vault-gate';
import { cn } from '@/lib/utils';
import { index as vaultIndex } from '@/routes/vault';
import { update } from '@/routes/vault/policies';
import type {
    VaultExportWho,
    VaultPoliciesProps,
    VaultPolicyForm,
} from '@/types/vault';

const minutes = (m: number) =>
    m % 60 === 0 ? `${m / 60} ${m === 60 ? 'hour' : 'hours'}` : `${m} min`;

const EXPORT: { value: VaultExportWho; label: string; body: string }[] = [
    {
        value: 'admins',
        label: 'Admins only',
        body: 'Admins can export every vault they manage.',
    },
    {
        value: 'admins_partners',
        label: 'Admins and partners',
        body: 'Partners can export the items they can use.',
    },
    {
        value: 'none',
        label: 'No one',
        body: 'Exports are switched off for everyone.',
    },
];

/** "What people will see" (VaultLockPolicies.dc.html summary). */
function summary(p: VaultPolicyForm): string[] {
    const clip =
        p.clipboard_clear_seconds === 60
            ? '1 minute'
            : `${p.clipboard_clear_seconds} seconds`;
    const exportLine =
        p.export_who === 'none'
            ? 'Nobody can export vaults'
            : `${p.export_who === 'admins' ? 'Only Admins can export' : 'Admins and partners can export'}, with 2FA${p.export_reason ? ' and a reason' : ''}`;

    return [
        `The vault locks after ${minutes(p.idle_lock_minutes).replace('min', 'minutes')} without activity${p.lock_on_close ? ', and when the tab closes' : ''}`,
        p.fresh_code_each_reveal
            ? 'A fresh code before any password is shown'
            : `Revealing asks for a code again after ${minutes(p.reauth_minutes).replace('min', 'minutes')}`,
        `Revealed passwords hide after ${p.reveal_seconds} seconds; copied ones are cleared after ${clip}`,
        exportLine,
        `Generated passwords are at least ${p.generator_min_length} characters`,
    ];
}

function SwitchRow({
    title,
    body,
    checked,
    onChange,
    disabled = false,
}: {
    title: string;
    body: ReactNode;
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <div className="vp-row">
            <span className="cf-stack">
                <span className="t-label-strong">{title}</span>
                <span className="t-caption t-muted">{body}</span>
            </span>
            <label className="cf-switch">
                <input
                    className="cf-sr"
                    type="checkbox"
                    role="switch"
                    checked={checked}
                    disabled={disabled}
                    onChange={(e) => onChange(e.currentTarget.checked)}
                    aria-label={title}
                />
                <span className="cf-switch-track" />
            </label>
        </div>
    );
}

function SegField<T extends number>({
    label,
    help,
    options,
    value,
    onChange,
    format,
}: {
    label: string;
    help: string;
    options: readonly T[];
    value: T;
    onChange: (value: T) => void;
    format: (value: T) => string;
}) {
    const id = useId();

    return (
        <div className="cf-field">
            <span className="cf-label" id={id}>
                {label}
            </span>
            <SegmentedControl
                aria-labelledby={id}
                options={options.map((o) => ({
                    value: String(o),
                    label: format(o),
                }))}
                value={String(value)}
                onValueChange={(v) => onChange(Number(v) as T)}
            />
            <span className="cf-help">{help}</span>
        </div>
    );
}

/**
 * Vault policies (VaultLockPolicies.dc.html "Policies", spec §14.4): re-auth interval,
 * idle lock, reveal and clipboard timers, who can export, breach checks and the
 * generator's minimum length, for this company. Admins only; every save is audited.
 */
export default function VaultPolicies({
    vaultState,
    policy,
    options,
    vaultCount,
    breachAvailable,
}: VaultPoliciesProps) {
    const [form, setForm] = useState<VaultPolicyForm>(policy);
    const [base, setBase] = useState<VaultPolicyForm>(policy);
    const [saving, setSaving] = useState(false);

    if (policy !== base) {
        setBase(policy);
        setForm(policy);
    }

    const dirty = (Object.keys(form) as (keyof VaultPolicyForm)[]).some(
        (k) => form[k] !== base[k],
    );
    const set = (patch: Partial<VaultPolicyForm>) =>
        setForm((f) => ({ ...f, ...patch }));

    const save = () =>
        router.put(update().url, form, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
        });

    return (
        <>
            <Head title="Vault policies" />
            <PageHeader
                kicker="Vault"
                title="Policies"
                crumbs={[
                    { title: 'Credentials Vault', href: vaultIndex().url },
                    { title: 'Policies' },
                ]}
                actions={
                    vaultState.unlocked && (
                        <>
                            {dirty && (
                                <Button
                                    variant="ghost"
                                    size="lg"
                                    onClick={() => setForm(base)}
                                >
                                    Discard
                                </Button>
                            )}
                            <Button
                                variant="primary"
                                size="lg"
                                disc={Check}
                                disabled={!dirty}
                                loading={saving}
                                onClick={save}
                            >
                                Save policies
                            </Button>
                        </>
                    )
                }
            />
            <VaultGate vaultState={vaultState}>
                <div className="vp-layout">
                    <div className="cf-stack vp-main">
                        <section
                            className="cf-card cf-stack vp-card"
                            aria-labelledby="vp-1"
                        >
                            <h2 id="vp-1" className="cf-dot-title t-title">
                                Unlock and re-ask
                            </h2>
                            <SegField
                                label="Ask for a 2FA code again after"
                                help="Revealing or copying a password needs a 2FA code this recent."
                                options={options.reauth}
                                value={form.reauth_minutes}
                                onChange={(reauth_minutes) =>
                                    set({ reauth_minutes })
                                }
                                format={minutes}
                            />
                            <SegField
                                label="Lock the vault after"
                                help="How long the vault stays open without activity. Shorter is safer on shared desks."
                                options={options.idle}
                                value={form.idle_lock_minutes}
                                onChange={(idle_lock_minutes) =>
                                    set({ idle_lock_minutes })
                                }
                                format={minutes}
                            />
                            <SwitchRow
                                title="Ask again before revealing a password"
                                body="Even when the vault is open, showing or copying a password needs a fresh code."
                                checked={form.fresh_code_each_reveal}
                                onChange={(fresh_code_each_reveal) =>
                                    set({ fresh_code_each_reveal })
                                }
                            />
                            <SwitchRow
                                title="Lock when the tab closes"
                                body="Closing or reloading Coffer locks the vault straight away."
                                checked={form.lock_on_close}
                                onChange={(lock_on_close) =>
                                    set({ lock_on_close })
                                }
                            />
                            <SwitchRow
                                title="Two-factor sign-in is required"
                                body="Always on: people without 2FA can’t open the vault."
                                checked
                                disabled
                                onChange={() => undefined}
                            />
                        </section>

                        <section
                            className="cf-card cf-stack vp-card"
                            aria-labelledby="vp-2"
                        >
                            <h2 id="vp-2" className="cf-dot-title t-title">
                                Reveal and clipboard
                            </h2>
                            <SegField
                                label="Clear copied passwords after"
                                help="Coffer wipes the clipboard if it still holds the password it copied."
                                options={options.clipboard}
                                value={form.clipboard_clear_seconds}
                                onChange={(clipboard_clear_seconds) =>
                                    set({ clipboard_clear_seconds })
                                }
                                format={(s) => `${s} s`}
                            />
                            <SegField
                                label="Hide revealed passwords after"
                                help="A revealed value turns back into dots on its own."
                                options={options.reveal}
                                value={form.reveal_seconds}
                                onChange={(reveal_seconds) =>
                                    set({ reveal_seconds })
                                }
                                format={(s) => `${s} s`}
                            />
                        </section>

                        <section
                            className="cf-card cf-stack vp-card"
                            aria-labelledby="vp-3"
                        >
                            <h2 id="vp-3" className="cf-dot-title t-title">
                                Who can export
                            </h2>
                            <div
                                role="radiogroup"
                                aria-labelledby="vp-3"
                                className="vp-radios"
                            >
                                {EXPORT.map((o) => (
                                    <label
                                        key={o.value}
                                        className={cn(
                                            'cf-radio vp-rcard',
                                            form.export_who === o.value && 'on',
                                        )}
                                    >
                                        <input
                                            className="cf-sr"
                                            type="radio"
                                            name="export_who"
                                            checked={
                                                form.export_who === o.value
                                            }
                                            onChange={() =>
                                                set({ export_who: o.value })
                                            }
                                        />
                                        <span className="cf-radio-dot vp-dot" />
                                        <span className="cf-stack vp-rtext">
                                            <span className="t-label-strong">
                                                {o.label}
                                            </span>
                                            <span className="t-caption t-muted">
                                                {o.body}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <SwitchRow
                                title="Ask for a reason on every export"
                                body="Exports always need a fresh 2FA code. The reason is saved in the audit log with the file."
                                checked={form.export_reason}
                                onChange={(export_reason) =>
                                    set({ export_reason })
                                }
                            />
                        </section>

                        <section
                            className="cf-card cf-stack vp-card"
                            aria-labelledby="vp-4"
                        >
                            <h2 id="vp-4" className="cf-dot-title t-title">
                                Passwords
                            </h2>
                            <SegField
                                label="Generated passwords are at least"
                                help="The generator’s shortest length for new and rotated passwords."
                                options={options.generator}
                                value={form.generator_min_length}
                                onChange={(generator_min_length) =>
                                    set({ generator_min_length })
                                }
                                format={(n) => `${n} characters`}
                            />
                            <SwitchRow
                                title="Check passwords against breach lists"
                                body={
                                    breachAvailable
                                        ? 'Weekly, with Have I Been Pwned. Only the first 5 characters of a hash leave Coffer.'
                                        : 'Switched off on this server (VAULT_HIBP_ENABLED). This setting applies once it’s on.'
                                }
                                checked={form.breach_checks}
                                onChange={(breach_checks) =>
                                    set({ breach_checks })
                                }
                            />
                        </section>
                    </div>

                    <aside
                        className="cf-card cf-card--graphite cf-stack vp-summary"
                        aria-labelledby="vp-sum"
                    >
                        <h2 id="vp-sum" className="t-title m-0">
                            What people will see
                        </h2>
                        {summary(form).map((line) => (
                            <div key={line} className="cf-row vp-sum-row">
                                <span className="vp-tick" aria-hidden="true">
                                    <Icon
                                        icon={Check}
                                        size={12}
                                        strokeWidth={2.5}
                                    />
                                </span>
                                <span className="t-body vp-on">{line}</span>
                            </div>
                        ))}
                        <span className="t-caption vh-on-muted">
                            Applies to all {vaultCount}{' '}
                            {vaultCount === 1 ? 'vault' : 'vaults'} in this
                            company from the next unlock. Only Admins can change
                            these.
                        </span>
                    </aside>
                </div>
            </VaultGate>
        </>
    );
}

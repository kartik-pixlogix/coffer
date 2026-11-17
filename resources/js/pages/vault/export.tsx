import { Head } from '@inertiajs/react';
import { Download, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { CodeInput } from '@/components/ui/code-input';
import { EmptyState } from '@/components/ui/empty-state';
import { FieldError } from '@/components/ui/field';
import { PasswordField } from '@/components/ui/password-field';
import { TextInput } from '@/components/ui/text-input';
import { toast } from '@/components/ui/toast';
import { VaultGate } from '@/components/vault/vault-gate';
import { downloadPost } from '@/lib/vault-api';
import { index as vaultIndex } from '@/routes/vault';
import { store } from '@/routes/vault/export';
import type { VaultExportProps } from '@/types/vault';

/**
 * Vault › Export (spec §14.4): an encrypted, passphrase-protected JSON file of the items
 * you can use, after a fresh 2FA code and (by policy) a reason. Audited. The passphrase
 * stays in this form and the request; it is never stored.
 */
export default function VaultExportPage({
    vaultState,
    vaults,
    needsCode,
    requireReason,
    minPassphrase,
    kdf,
}: VaultExportProps) {
    const [chosen, setChosen] = useState<number[]>(vaults.map((v) => v.id));
    const [passphrase, setPassphrase] = useState('');
    const [confirm, setConfirm] = useState('');
    const [reason, setReason] = useState('');
    const [code, setCode] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [codeNeeded, setCodeNeeded] = useState(needsCode);
    const total = vaults
        .filter((v) => chosen.includes(v.id))
        .reduce((n, v) => n + v.items, 0);

    const submit = async () => {
        setBusy(true);
        setErrors({});
        const result = await downloadPost(
            store().url,
            {
                passphrase,
                passphrase_confirmation: confirm,
                reason,
                vault_ids: chosen.length === vaults.length ? [] : chosen,
                code: codeNeeded ? code : '',
            },
            'coffer-vault-export.coffer.json',
        );
        setBusy(false);

        if (result.ok) {
            setPassphrase('');
            setConfirm('');
            setCode('');
            setCodeNeeded(true);
            toast.success(
                'Export downloaded. Keep the file and the passphrase apart.',
            );

            return;
        }

        if (result.locked) {
            window.location.reload();

            return;
        }

        setErrors(
            Object.fromEntries(
                Object.entries(result.errors).map(([k, v]) => [k, v[0] ?? '']),
            ),
        );

        if (Object.keys(result.errors).length === 0) {
            toast.error(result.message);
        }
    };

    return (
        <>
            <Head title="Export" />
            <PageHeader
                kicker="Vault"
                title="Export"
                crumbs={[
                    { title: 'Credentials Vault', href: vaultIndex().url },
                    { title: 'Export' },
                ]}
            />
            <VaultGate vaultState={vaultState}>
                {vaults.length === 0 ? (
                    <EmptyState
                        icon={Download}
                        headingLevel={2}
                        title="Nothing to export"
                        description="There are no items you can use in this company's vaults."
                    />
                ) : (
                    <div className="vx-layout">
                        <form
                            className="cf-card cf-stack vx-main"
                            aria-labelledby="vx-h"
                            onSubmit={(e) => {
                                e.preventDefault();
                                void submit();
                            }}
                        >
                            <h2 id="vx-h" className="cf-dot-title t-title">
                                Encrypted export
                            </h2>
                            <fieldset className="cf-field vx-vaults">
                                <legend className="cf-label">Vaults</legend>
                                {vaults.map((v) => (
                                    <Checkbox
                                        key={v.id}
                                        checked={chosen.includes(v.id)}
                                        onCheckedChange={(on) =>
                                            setChosen((list) =>
                                                on
                                                    ? [...list, v.id]
                                                    : list.filter(
                                                          (id) => id !== v.id,
                                                      ),
                                            )
                                        }
                                    >
                                        {v.name} · {v.items}{' '}
                                        {v.items === 1 ? 'item' : 'items'}
                                    </Checkbox>
                                ))}
                                {errors.vault_ids && (
                                    <FieldError>{errors.vault_ids}</FieldError>
                                )}
                            </fieldset>
                            <PasswordField
                                label="Passphrase for the file"
                                value={passphrase}
                                onChange={(e) =>
                                    setPassphrase(e.currentTarget.value)
                                }
                                autoComplete="new-password"
                                error={errors.passphrase}
                                help={`At least ${minPassphrase} characters. Without it the file can’t be opened, by anyone.`}
                            />
                            <PasswordField
                                label="Passphrase again"
                                value={confirm}
                                onChange={(e) =>
                                    setConfirm(e.currentTarget.value)
                                }
                                autoComplete="new-password"
                            />
                            {requireReason && (
                                <TextInput
                                    label="Reason"
                                    value={reason}
                                    maxLength={200}
                                    placeholder="Moving the Asha Retail logins to their own manager"
                                    onChange={(e) =>
                                        setReason(e.currentTarget.value)
                                    }
                                    error={errors.reason}
                                    help="Saved in the audit log with the file name."
                                />
                            )}
                            {codeNeeded && (
                                <CodeInput
                                    legend="6-digit code from your authenticator app"
                                    value={code}
                                    onChange={setCode}
                                    error={errors.code}
                                />
                            )}
                            <Button
                                type="submit"
                                variant="primary"
                                size="lg"
                                disc={Download}
                                loading={busy}
                                disabled={chosen.length === 0}
                            >
                                Export {total} {total === 1 ? 'item' : 'items'}
                            </Button>
                        </form>
                        <aside
                            className="cf-card cf-card--graphite cf-stack vx-aside"
                            aria-label="About the file"
                        >
                            <h2 className="t-title m-0">About the file</h2>
                            <p className="t-body vp-on m-0">
                                A JSON file encrypted with AES-256-GCM. The key
                                comes from your passphrase by{' '}
                                {kdf === 'argon2id'
                                    ? 'Argon2id'
                                    : 'PBKDF2 (600,000 rounds)'}
                                , so the file is only as strong as the
                                passphrase.
                            </p>
                            <p className="t-body vp-on m-0">
                                It holds passwords, keys, 2FA setup keys and
                                notes. Attachments are listed by name, not
                                included.
                            </p>
                            <p className="t-caption vh-on-muted m-0">
                                Import it back from Vault › Import with the same
                                passphrase.
                            </p>
                        </aside>
                        <AlertBanner variant="info" slim icon={ShieldCheck}>
                            Every export is written to the audit log.
                        </AlertBanner>
                    </div>
                )}
            </VaultGate>
        </>
    );
}

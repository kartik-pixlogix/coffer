import { useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useId } from 'react';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { LEVEL_LABEL } from '@/components/vault/home/vault-home.utils';
import { store as storeRequest } from '@/routes/vault/requests';
import type { VaultAccessDuration, VaultLevel } from '@/types/vault';

const LEVELS: VaultLevel[] = ['view', 'use', 'edit', 'manage'];

const DURATIONS: { value: VaultAccessDuration; label: string }[] = [
    { value: '24h', label: '24 hours' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: 'never', label: 'No end date' },
];

export type RequestTarget =
    | { kind: 'item'; id: number; name: string; level: VaultLevel | null }
    | {
          kind: 'vault';
          vaults: { id: number; name: string; level: VaultLevel | null }[];
      };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    target: RequestTarget;
    /** The most the role allows. */
    ceiling: VaultLevel;
};

/** Levels above what you have, up to what your role allows. */
function levelsFor(current: VaultLevel | null, ceiling: VaultLevel) {
    const from = current === null ? 0 : LEVELS.indexOf(current) + 1;

    return LEVELS.slice(from, LEVELS.indexOf(ceiling) + 1);
}

/**
 * "Ask for access" (spec §14.2): a level, how long and a reason. Its Managers and the
 * Admins get a notification and decide in the requests inbox.
 */
export function RequestAccessModal({
    open,
    onOpenChange,
    target,
    ceiling,
}: Props) {
    const firstVault = target.kind === 'vault' ? target.vaults[0] : undefined;
    const form = useForm<{
        item_id: number | null;
        vault_id: number | null;
        level: VaultLevel;
        duration: VaultAccessDuration;
        reason: string;
    }>({
        item_id: target.kind === 'item' ? target.id : null,
        vault_id: firstVault?.id ?? null,
        level:
            levelsFor(
                target.kind === 'item'
                    ? target.level
                    : (firstVault?.level ?? null),
                ceiling,
            )[0] ?? 'view',
        duration: '7d',
        reason: '',
    });
    const levelId = useId();
    const durationId = useId();

    const current =
        target.kind === 'item'
            ? target.level
            : (target.vaults.find((v) => v.id === form.data.vault_id)?.level ??
              null);
    const levels = levelsFor(current, ceiling);

    const submit = () =>
        form.post(storeRequest().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('reason');
                onOpenChange(false);
            },
        });

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            size="sm"
            icon={KeyRound}
            title="Ask for access"
            subtitle={
                target.kind === 'item'
                    ? `${target.name}${current ? ` · you have ${LEVEL_LABEL[current]}` : ''}`
                    : 'A Manager of the vault or an Admin decides.'
            }
            dirty={form.isDirty}
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            footer={
                <>
                    <Button type="button" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        disabled={levels.length === 0}
                    >
                        Send request
                    </Button>
                </>
            }
        >
            <div className="cf-stack" style={{ gap: 16 }}>
                {target.kind === 'vault' && (
                    <Select
                        label="Vault"
                        value={String(form.data.vault_id ?? '')}
                        onChange={(e) => {
                            const id = Number(e.target.value);
                            const next = levelsFor(
                                target.vaults.find((v) => v.id === id)?.level ??
                                    null,
                                ceiling,
                            );
                            form.setData((d) => ({
                                ...d,
                                vault_id: id,
                                level: next.includes(d.level)
                                    ? d.level
                                    : (next[0] ?? d.level),
                            }));
                        }}
                        options={target.vaults.map((v) => ({
                            value: String(v.id),
                            label: v.level
                                ? `${v.name} · you have ${LEVEL_LABEL[v.level]}`
                                : v.name,
                        }))}
                        error={form.errors.vault_id}
                    />
                )}
                <div className="cf-field">
                    <span className="cf-label" id={levelId}>
                        Access you need
                    </span>
                    <SegmentedControl
                        aria-labelledby={levelId}
                        options={levels.map((l) => ({
                            value: l,
                            label: LEVEL_LABEL[l],
                        }))}
                        value={form.data.level}
                        onValueChange={(level) => form.setData('level', level)}
                    />
                    {form.errors.level && (
                        <span className="cf-errtext">{form.errors.level}</span>
                    )}
                    {levels.length === 0 && (
                        <span className="cf-help">
                            You already have all the access your role allows.
                        </span>
                    )}
                </div>
                <div className="cf-field">
                    <span className="cf-label" id={durationId}>
                        For how long
                    </span>
                    <SegmentedControl
                        aria-labelledby={durationId}
                        size="sm"
                        options={DURATIONS}
                        value={form.data.duration}
                        onValueChange={(duration) =>
                            form.setData('duration', duration)
                        }
                    />
                </div>
                <Textarea
                    label="Why you need it"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="Rotating the S3 keys for the Asha Retail bucket."
                    limit={500}
                    rows={3}
                    error={form.errors.reason}
                    help="Don’t paste passwords here. The reason goes to the people who decide."
                />
            </div>
        </Modal>
    );
}

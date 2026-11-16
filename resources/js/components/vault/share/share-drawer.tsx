import { Search, X } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Avatar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { Skeleton, SkeletonGroup } from '@/components/ui/skeleton';
import { StatusPill } from '@/components/ui/status-pill';
import { toast } from '@/components/ui/toast';
import { LevelPill } from '@/components/vault/home/vault-bits';
import {
    formatDateIn,
    LEVEL_LABEL,
} from '@/components/vault/home/vault-home.utils';
import { vaultRequest } from '@/lib/vault-api';
import {
    destroy as destroyGrant,
    update as updateGrant,
} from '@/routes/vault/grants';
import { share as itemShare } from '@/routes/vault/items';
import { store as itemGrants } from '@/routes/vault/items/grants';
import { share as vaultShare } from '@/routes/vault/vaults';
import { store as vaultGrants } from '@/routes/vault/vaults/grants';
import type {
    VaultAccessDuration,
    VaultAccessRow,
    VaultLevel,
    VaultShareCandidate,
    VaultSharePanel,
    VaultShareTarget,
} from '@/types/vault';

const LEVELS: VaultLevel[] = ['view', 'use', 'edit', 'manage'];

/** Spec §14.2 levels (D72: the board's Use copy said "without seeing it"; the spec's Use reveals). */
const LEVEL_HELP: Record<VaultLevel, string> = {
    view: 'Sees the item and its non-secret fields. Can’t reveal or copy secrets.',
    use: 'Reveals and copies secrets and one-time codes. Every reveal and copy is logged.',
    edit: 'Also changes fields and rotates the password.',
    manage: 'Also shares with others and deletes the item.',
};

const ENDS: { value: VaultAccessDuration; label: string; days: number }[] = [
    { value: 'never', label: 'Never', days: 0 },
    { value: '24h', label: '24 hours', days: 1 },
    { value: '7d', label: '7 days', days: 7 },
    { value: '30d', label: '30 days', days: 30 },
];

const partnerOf = (colour: number | null): PartnerColour | undefined =>
    colour !== null && colour >= 1 && colour <= 6
        ? (colour as PartnerColour)
        : undefined;

function endsHelp(ends: VaultAccessDuration, timeZone?: string): string {
    const option = ENDS.find((e) => e.value === ends);

    if (!option || option.days === 0) {
        return 'Access lasts until you remove it.';
    }

    const end = new Date(Date.now() + option.days * 86_400_000).toISOString();

    return `Access stops on ${formatDateIn(end, timeZone)}. They can ask again.`;
}

function rowCaption(
    row: VaultAccessRow,
    vault: string,
    timeZone?: string,
): string {
    const about = row.about ?? '';

    if (row.via === 'admin') {
        return 'Admin · can do everything';
    }

    if (row.via === 'owner') {
        return `${about} · their personal vault`;
    }

    const until = row.endsAt
        ? `until ${formatDateIn(row.endsAt, timeZone)}`
        : 'no end date';
    const from = row.via === 'vault' ? ` · from the ${vault} vault` : '';
    const limited =
        row.limited && row.granted
            ? ` · ${LEVEL_LABEL[row.granted]} given, their role allows ${LEVEL_LABEL[row.level]}`
            : '';

    return `${about} · ${until}${from}${limited}`;
}

type Props = {
    target: VaultShareTarget | null;
    onOpenChange: (open: boolean) => void;
    /** Something changed: reload the list's people. */
    onChanged: () => void;
    /** The vault locked on the server (423). */
    onLocked?: () => void;
    timeZone?: string;
};

/**
 * The share drawer (VaultShare.dc.html, spec §14.2): add people at View, Use, Edit or
 * Manage with an optional end, then who has access and why, with level changes and
 * removal. Talks to the share endpoints as JSON; no secret is ever involved.
 */
export function ShareDrawer({
    target,
    onOpenChange,
    onChanged,
    onLocked,
    timeZone,
}: Props) {
    const [panel, setPanel] = useState<VaultSharePanel | null>(null);
    const [loadedFor, setLoadedFor] = useState<string | null>(null);
    const key = target ? `${target.kind}:${target.id}` : null;

    useEffect(() => {
        if (target === null) {
            return;
        }

        let live = true;
        const url =
            target.kind === 'item'
                ? itemShare(target.id).url
                : vaultShare(target.id).url;

        void vaultRequest<VaultSharePanel>(url).then((result) => {
            if (!live) {
                return;
            }

            if (result.ok) {
                setPanel(result.data);
                setLoadedFor(`${target.kind}:${target.id}`);
            } else if (result.locked) {
                onLocked?.();
                onOpenChange(false);
            } else {
                toast.error(result.message);
                onOpenChange(false);
            }
        });

        return () => {
            live = false;
        };
    }, [target, onLocked, onOpenChange]);

    const ready = panel !== null && loadedFor === key;
    const count = ready ? panel.access.length : 0;

    return (
        <Drawer
            open={target !== null}
            onOpenChange={onOpenChange}
            title={`Share ${target?.name ?? ''}`}
            subtitle={
                ready
                    ? `${panel.target.kind === 'item' ? `${panel.target.vault} vault · ` : ''}Shared with ${count} ${count === 1 ? 'person' : 'people'}`
                    : 'Loading who has access'
            }
            media={
                <Avatar
                    name={target?.name ?? ''}
                    initials={ready ? panel.target.monogram : undefined}
                    size="lg"
                    tile
                    label={false}
                    className="vs-tile"
                />
            }
            closeLabel="Close sharing"
            className="vs-drawer"
        >
            {ready && target ? (
                <SharePanelBody
                    key={key}
                    panel={panel}
                    target={target}
                    onPanel={setPanel}
                    onChanged={onChanged}
                    onLocked={() => {
                        onLocked?.();
                        onOpenChange(false);
                    }}
                    timeZone={timeZone}
                />
            ) : (
                <SkeletonGroup aria-label="Loading sharing">
                    <div className="cf-stack" style={{ gap: 14 }}>
                        <Skeleton height={48} />
                        <Skeleton height={40} />
                        <Skeleton height={40} />
                        <Skeleton height={52} />
                        <Skeleton height={52} />
                    </div>
                </SkeletonGroup>
            )}
        </Drawer>
    );
}

function SharePanelBody({
    panel,
    target,
    onPanel,
    onChanged,
    onLocked,
    timeZone,
}: {
    panel: VaultSharePanel;
    target: VaultShareTarget;
    onPanel: (panel: VaultSharePanel) => void;
    onChanged: () => void;
    onLocked: () => void;
    timeZone?: string;
}) {
    const [query, setQuery] = useState('');
    const [picked, setPicked] = useState<VaultShareCandidate[]>([]);
    const [level, setLevel] = useState<VaultLevel>('use');
    const [ends, setEnds] = useState<VaultAccessDuration>('7d');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const listId = useId();
    const inputId = useId();
    const levelId = useId();
    const endsId = useId();

    const q = query.trim().toLocaleLowerCase('en-IN');
    const suggestions = useMemo(
        () =>
            q === ''
                ? []
                : panel.candidates
                      .filter(
                          (c) =>
                              !picked.some((p) => p.userId === c.userId) &&
                              `${c.name} ${c.about ?? ''}`
                                  .toLocaleLowerCase('en-IN')
                                  .includes(q),
                      )
                      .slice(0, 6),
        [q, panel.candidates, picked],
    );
    const open = q !== '';

    const apply = (
        result: Awaited<ReturnType<typeof vaultRequest<VaultSharePanel>>>,
    ): boolean => {
        if (result.ok) {
            onPanel(result.data);

            if (result.data.message) {
                toast.success(result.data.message);
            }

            onChanged();

            return true;
        }

        if (result.locked) {
            onLocked();
        } else {
            setError(result.message);
            toast.error(result.message);
        }

        return false;
    };

    const pick = (candidate: VaultShareCandidate) => {
        setPicked((list) => [...list, candidate]);
        setQuery('');
        setActive(0);
        setError(undefined);
        inputRef.current?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (!open || suggestions.length === 0) {
            if (event.key === 'Backspace' && query === '' && picked.length) {
                setPicked((list) => list.slice(0, -1));
            }

            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((i) => (i + 1) % suggestions.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((i) => (i - 1 + suggestions.length) % suggestions.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const choice = suggestions[active];

            if (choice) {
                pick(choice);
            }
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            setQuery('');
        }
    };

    const share = async () => {
        if (picked.length === 0 || busy) {
            return;
        }

        setBusy(true);
        setError(undefined);
        const url =
            target.kind === 'item'
                ? itemGrants(target.id).url
                : vaultGrants(target.id).url;
        const ok = apply(
            await vaultRequest<VaultSharePanel>(url, {
                user_ids: picked.map((p) => p.userId),
                level,
                ends,
            }),
        );
        setBusy(false);

        if (ok) {
            setPicked([]);
        }
    };

    const change = async (row: VaultAccessRow, next: VaultLevel) => {
        if (row.grantId === null) {
            return;
        }

        apply(
            await vaultRequest<VaultSharePanel>(
                updateGrant(row.grantId).url,
                { level: next },
                'PATCH',
            ),
        );
    };

    const remove = async (row: VaultAccessRow) => {
        if (row.grantId === null) {
            return;
        }

        apply(
            await vaultRequest<VaultSharePanel>(
                destroyGrant(row.grantId).url,
                {},
                'DELETE',
            ),
        );
    };

    const capped = picked.filter(
        (p) => LEVELS.indexOf(p.ceiling) < LEVELS.indexOf(level),
    );
    const noun = target.kind === 'item' ? 'item' : 'vault’s items';

    return (
        <div className="cf-stack vs-body">
            <div className="cf-field vs-add">
                <label className="cf-label" htmlFor={inputId}>
                    Add people
                </label>
                <span className="cf-inwrap">
                    <Icon icon={Search} size={16} />
                    <input
                        ref={inputRef}
                        id={inputId}
                        type="text"
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setActive(0);
                        }}
                        onKeyDown={onKeyDown}
                        placeholder="Name or role"
                        role="combobox"
                        aria-expanded={open}
                        aria-controls={listId}
                        aria-autocomplete="list"
                        aria-activedescendant={
                            open && suggestions[active]
                                ? `${listId}-${suggestions[active].userId}`
                                : undefined
                        }
                        autoComplete="off"
                    />
                </span>
                {open && (
                    <div
                        id={listId}
                        className="cf-pop cf-listbox vs-suggest"
                        role="listbox"
                        aria-label="Suggestions"
                    >
                        {suggestions.map((c, i) => (
                            <button
                                key={c.userId}
                                id={`${listId}-${c.userId}`}
                                type="button"
                                className="cf-option vs-option"
                                role="option"
                                aria-selected={i === active}
                                onMouseEnter={() => setActive(i)}
                                onClick={() => pick(c)}
                            >
                                <Avatar
                                    name={c.name}
                                    size="sm"
                                    partner={partnerOf(c.colour)}
                                    label={false}
                                />
                                <span className="cf-stack vs-option-text">
                                    <span className="t-label-strong">
                                        {c.name}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {c.about}
                                        {!c.twoFactor &&
                                            ' · needs 2FA before using the vault'}
                                    </span>
                                </span>
                            </button>
                        ))}
                        {suggestions.length === 0 && (
                            <span className="t-caption t-muted vs-none">
                                No one matches “{query.trim()}”.
                            </span>
                        )}
                    </div>
                )}
            </div>

            {picked.length > 0 && (
                <div className="cf-row vs-chips">
                    {picked.map((p) => (
                        <span key={p.userId} className="cf-chip">
                            {p.name}
                            <button
                                type="button"
                                className="cf-chip-x"
                                aria-label={`Remove ${p.name}`}
                                onClick={() =>
                                    setPicked((list) =>
                                        list.filter(
                                            (x) => x.userId !== p.userId,
                                        ),
                                    )
                                }
                            >
                                <Icon icon={X} size={12} strokeWidth={2.5} />
                            </button>
                        </span>
                    ))}
                </div>
            )}

            <div className="cf-field">
                <span className="cf-label" id={levelId}>
                    Permission
                </span>
                <SegmentedControl
                    aria-labelledby={levelId}
                    options={LEVELS.map((l) => ({
                        value: l,
                        label: LEVEL_LABEL[l],
                    }))}
                    value={level}
                    onValueChange={setLevel}
                />
                <span className="cf-help">
                    {LEVEL_HELP[level].replace('the item', `the ${noun}`)}
                    {capped.length > 0 &&
                        ` ${capped.map((p) => p.name).join(', ')}: their role allows ${LEVEL_LABEL[capped[0].ceiling]} at most.`}
                </span>
            </div>

            <div className="cf-field">
                <span className="cf-label" id={endsId}>
                    Access ends
                </span>
                <SegmentedControl
                    aria-labelledby={endsId}
                    size="sm"
                    options={ENDS.map((e) => ({
                        value: e.value,
                        label: e.label,
                    }))}
                    value={ends}
                    onValueChange={setEnds}
                />
                <span className="cf-help">{endsHelp(ends, timeZone)}</span>
            </div>

            <Button
                variant="primary"
                disabled={picked.length === 0}
                loading={busy}
                onClick={() => void share()}
            >
                {picked.length === 0
                    ? 'Pick someone to share with'
                    : `Share with ${picked.length === 1 ? picked[0].name : `${picked.length} people`}`}
            </Button>
            {error && (
                <span className="cf-errtext" role="alert">
                    {error}
                </span>
            )}

            <div className="cf-divider" role="separator" />

            <div className="cf-stack vs-access">
                <span className="cf-label">Who has access</span>
                {panel.access.map((row) => (
                    <div key={row.userId} className="vs-acc">
                        <Avatar
                            name={row.name}
                            size="sm"
                            partner={partnerOf(row.colour)}
                            label={false}
                        />
                        <span className="cf-stack vs-acc-text">
                            <span className="t-label-strong">
                                {row.name}
                                {row.you && ' (you)'}
                            </span>
                            <span className="t-caption t-muted">
                                {rowCaption(row, panel.target.vault, timeZone)}
                            </span>
                        </span>
                        {row.editable && row.grantId !== null ? (
                            <>
                                <Select
                                    pill
                                    aria-label={`Permission for ${row.name}`}
                                    value={row.granted ?? row.level}
                                    onChange={(e) =>
                                        void change(
                                            row,
                                            e.target.value as VaultLevel,
                                        )
                                    }
                                    options={LEVELS.map((l) => ({
                                        value: l,
                                        label: LEVEL_LABEL[l],
                                    }))}
                                    wrapClassName="vs-acc-select"
                                />
                                <IconButton
                                    icon={X}
                                    size="sm"
                                    iconSize={14}
                                    aria-label={`Remove ${row.name}`}
                                    onClick={() => void remove(row)}
                                />
                            </>
                        ) : (
                            <>
                                {row.via === 'admin' || row.via === 'owner' ? (
                                    <StatusPill tone="graphite" size="sm">
                                        {row.via === 'owner'
                                            ? 'Owner'
                                            : 'Admin'}
                                    </StatusPill>
                                ) : (
                                    <LevelPill level={row.level} />
                                )}
                                <span />
                            </>
                        )}
                    </div>
                ))}
            </div>

            {panel.target.restricted && (
                <p className="t-caption t-muted m-0">
                    Admins only: vault sharing doesn’t reach this item. People
                    added here get it directly.
                </p>
            )}
            <AlertBanner variant="info" slim>
                Everyone with Use or higher has each copy and reveal written to
                the audit log.
            </AlertBanner>
        </div>
    );
}

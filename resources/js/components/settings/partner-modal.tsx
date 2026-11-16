import { router } from '@inertiajs/react';
import { Check, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { initialsFrom } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { TextInput } from '@/components/ui/text-input';
import { useToast } from '@/components/ui/toast';
import {
    WHOLE,
    formatEquity,
    formatEquityShort,
    parseEquity,
} from '@/lib/equity';
import { cn } from '@/lib/utils';
import {
    destroy as removePartner,
    store as addPartner,
    update as updatePartner,
} from '@/routes/settings/partners';

export type PartnerData = {
    id: number;
    name: string;
    email: string | null;
    role: 'admin' | 'partner';
    colour: number;
    equityBps: number;
    sideId: number | null;
    overallBps: number;
    linked: boolean;
    hasRecords: boolean;
};

export type SideData = { id: number; name: string; equityBps: number };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null: "Add partner". */
    partner: PartnerData | null;
    partners: PartnerData[];
    sides: SideData[];
    companyName: string;
    jv: boolean;
};

type Draft = {
    name: string;
    email: string;
    role: 'admin' | 'partner';
    colour: number;
    sideId: number | null;
    equity: string;
    /** Rebalanced shares of the other partners in the affected split (D31). */
    shares: Record<number, string>;
};

function startDraft(
    partner: PartnerData | null,
    partners: PartnerData[],
    sides: SideData[],
): Draft {
    const used = new Set(partners.map((p) => p.colour));
    const free = [1, 2, 3, 4, 5, 6].find((c) => !used.has(c)) ?? 1;

    return {
        name: partner?.name ?? '',
        email: '',
        role: partner?.role ?? 'partner',
        colour: partner?.colour ?? free,
        sideId: partner?.sideId ?? sides[0]?.id ?? null,
        equity: formatEquity(partner?.equityBps ?? 0),
        shares: {},
    };
}

/**
 * "Edit partner" (SettingsPartners.dc.html modal, spec §16): name, role, side, share,
 * colour and Remove. The side checks show each split's total live; because a share
 * can only change if the split still totals 100%, the other partners' shares can be
 * adjusted here too (D31). The same modal adds a partner (with an optional invite).
 */
export function PartnerModal({
    open,
    onOpenChange,
    partner,
    partners,
    sides,
    companyName,
    jv,
}: Props) {
    const [draft, setDraft] = useState<Draft>(() =>
        startDraft(partner, partners, sides),
    );
    const [key, setKey] = useState(partner?.id ?? null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const toast = useToast();

    // Opening for another partner: start over from their values.
    if (key !== (partner?.id ?? null)) {
        setKey(partner?.id ?? null);
        setDraft(startDraft(partner, partners, sides));
        setErrors({});
    }

    const others = partners.filter((p) => p.id !== partner?.id);
    const shareOf = (p: PartnerData) =>
        parseEquity(draft.shares[p.id] ?? formatEquity(p.equityBps)) ?? 0;
    const selfBps = parseEquity(draft.equity) ?? 0;

    // The splits to check: each side in a JV (with its proposed members), or the whole company.
    const groups = (
        jv ? sides : [{ id: -1, name: 'Partners', equityBps: WHOLE }]
    ).map((side) => {
        const members = others.filter((p) => !jv || p.sideId === side.id);
        const inGroup = !jv || draft.sideId === side.id;
        const total =
            members.reduce((sum, p) => sum + shareOf(p), 0) +
            (inGroup ? selfBps : 0);

        return {
            side,
            members,
            inGroup,
            total,
            ok: total === WHOLE || (members.length === 0 && !inGroup),
        };
    });

    const allOk = groups.every((g) => g.ok);
    const side = sides.find((s) => s.id === draft.sideId);
    const overall = jv ? ((side?.equityBps ?? 0) * selfBps) / WHOLE : selfBps;
    const canSave =
        draft.name.trim() !== '' && parseEquity(draft.equity) !== null && allOk;
    const title = partner ? 'Edit partner' : 'Add partner';

    const save = () => {
        const payload = {
            name: draft.name,
            role: draft.role,
            colour: draft.colour,
            side_id: jv ? draft.sideId : null,
            equity: draft.equity,
            shares: draft.shares,
            ...(partner ? {} : { email: draft.email.trim() || null }),
        };
        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onOpenChange(false),
            onError: (bag: Record<string, string>) => setErrors(bag),
        };

        if (partner) {
            router.put(updatePartner(partner.id).url, payload, options);
        } else {
            router.post(addPartner().url, payload, options);
        }
    };

    const remove = () => {
        if (!partner) {
            return;
        }

        if (partner.equityBps > 0) {
            toast.error(
                `Move ${partner.name}’s ${formatEquityShort(partner.equityBps).replace('%', '')}% to another partner before removing them.`,
            );

            return;
        }

        router.delete(removePartner(partner.id).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (bag) =>
                toast.error(bag.partner ?? 'This partner can’t be removed.'),
        });
    };

    const usedBy = new Map(others.map((p) => [p.colour, p.name]));

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            subtitle={`${partner?.name ?? 'New partner'} · ${companyName}`}
            dirty
            footer={
                <div className="flex w-full flex-wrap items-center justify-between gap-2.5">
                    {partner ? (
                        <Button
                            variant="ghost"
                            size="lg"
                            icon={Trash2}
                            className="invite-revoke"
                            onClick={remove}
                        >
                            Remove partner
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="cf-row">
                        <Button
                            variant="secondary"
                            size="lg"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            size="lg"
                            disc={Check}
                            loading={saving}
                            disabled={!canSave}
                            onClick={save}
                        >
                            Save partner
                        </Button>
                    </div>
                </div>
            }
        >
            <div className="cf-row gap-3">
                <span className="cf-avatar cf-avatar--lg" aria-hidden="true">
                    {initialsFrom(draft.name || '?')}
                    <span
                        className="cf-avatar-dot"
                        style={{ background: `var(--partner-${draft.colour})` }}
                    />
                </span>
                <span className="t-caption t-muted">
                    {partner?.linked
                        ? 'Linked to a user. Their company role follows this partner’s role.'
                        : 'Not linked to a user yet.'}
                </span>
            </div>
            <div className="wiz-grid-2">
                <TextInput
                    id="ep-n"
                    label="Name"
                    value={draft.name}
                    maxLength={120}
                    onChange={(e) =>
                        setDraft((d) => ({ ...d, name: e.target.value }))
                    }
                    error={errors.name}
                />
                <div className="cf-field">
                    <span className="cf-label" id="ep-r">
                        Role
                    </span>
                    <SegmentedControl<'admin' | 'partner'>
                        aria-labelledby="ep-r"
                        value={draft.role}
                        options={[
                            { value: 'admin', label: 'Admin' },
                            { value: 'partner', label: 'Partner' },
                        ]}
                        onValueChange={(role) =>
                            setDraft((d) => ({ ...d, role }))
                        }
                    />
                </div>
                {jv && (
                    <div className="cf-field">
                        <span className="cf-label" id="ep-s">
                            Side
                        </span>
                        <SegmentedControl<string>
                            aria-labelledby="ep-s"
                            value={String(draft.sideId ?? '')}
                            options={sides.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            }))}
                            onValueChange={(value) =>
                                setDraft((d) => ({
                                    ...d,
                                    sideId: Number(value),
                                }))
                            }
                        />
                    </div>
                )}
                <div className="cf-field">
                    <label className="cf-label" htmlFor="ep-q">
                        {jv ? 'Share of side' : 'Share of company'}
                    </label>
                    <span className={cn('cf-inwrap', !allOk && 'is-error')}>
                        <input
                            id="ep-q"
                            type="text"
                            inputMode="decimal"
                            value={draft.equity}
                            onChange={(e) =>
                                setDraft((d) => ({
                                    ...d,
                                    equity: e.target.value.replace(
                                        /[^0-9.]/g,
                                        '',
                                    ),
                                }))
                            }
                            aria-invalid={!allOk || undefined}
                            aria-describedby="ep-q-h"
                            className="wiz-num"
                        />
                        <span
                            className="cf-prefix wiz-suffix"
                            aria-hidden="true"
                        >
                            %
                        </span>
                    </span>
                    <span id="ep-q-h" className="cf-help">
                        Overall equity{' '}
                        <span className="t-money-sm">
                            {formatEquityShort(overall)}
                        </span>
                    </span>
                </div>
                {!partner && (
                    <TextInput
                        id="ep-e"
                        type="email"
                        label="Email (to invite)"
                        placeholder="Optional"
                        value={draft.email}
                        onChange={(e) =>
                            setDraft((d) => ({ ...d, email: e.target.value }))
                        }
                        error={errors.email}
                    />
                )}
            </div>

            <div className="cf-inset partner-checks">
                {groups
                    .filter((g) => g.inGroup || g.members.length > 0)
                    .map((g) => (
                        <div key={g.side.id} className="flex flex-col gap-1.5">
                            <div className="cf-kv t-body">
                                <span>
                                    {jv
                                        ? `${g.side.name} side`
                                        : 'Partners total'}
                                </span>
                                <span className="cf-row gap-1.5">
                                    <span className="t-money-sm">
                                        {formatEquity(g.total)}%
                                    </span>
                                    <span
                                        className={cn(
                                            'cf-pill cf-pill--sm',
                                            g.ok
                                                ? 'cf-pill--pos'
                                                : 'cf-pill--neg',
                                        )}
                                    >
                                        {g.ok
                                            ? 'OK'
                                            : g.total > WHOLE
                                              ? 'Over 100%'
                                              : 'Under 100%'}
                                    </span>
                                </span>
                            </div>
                            {g.members.length > 0 && (
                                <div className="partner-shares">
                                    {g.members.map((p) => (
                                        <label
                                            key={p.id}
                                            className="partner-share"
                                        >
                                            <span className="t-caption">
                                                {initialsFrom(p.name)}
                                            </span>
                                            <span className="cf-inwrap cf-inwrap--sm">
                                                <input
                                                    type="text"
                                                    inputMode="decimal"
                                                    value={
                                                        draft.shares[p.id] ??
                                                        formatEquity(
                                                            p.equityBps,
                                                        )
                                                    }
                                                    onChange={(e) =>
                                                        setDraft((d) => ({
                                                            ...d,
                                                            shares: {
                                                                ...d.shares,
                                                                [p.id]: e.target.value.replace(
                                                                    /[^0-9.]/g,
                                                                    '',
                                                                ),
                                                            },
                                                        }))
                                                    }
                                                    aria-label={`Share for ${p.name}`}
                                                    className="wiz-num"
                                                />
                                                <span
                                                    className="cf-prefix wiz-suffix"
                                                    aria-hidden="true"
                                                >
                                                    %
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                {!allOk && (
                    <span className="t-caption t-muted">
                        Adjust the shares above so{' '}
                        {jv ? 'each side' : 'the partners'} total 100%.
                    </span>
                )}
            </div>
            {errors.equity && (
                <span className="cf-errtext">{errors.equity}</span>
            )}

            <div className="cf-field">
                <span className="cf-label" id="ep-c">
                    Colour
                </span>
                <div
                    className="cf-row gap-1.5"
                    role="radiogroup"
                    aria-labelledby="ep-c"
                >
                    {[1, 2, 3, 4, 5, 6].map((colour) => {
                        const owner = usedBy.get(colour);
                        const label = owner
                            ? `Partner colour ${colour}, used by ${owner}`
                            : `Partner colour ${colour}`;

                        return (
                            <label
                                key={colour}
                                className={cn(
                                    'sw',
                                    draft.colour === colour && 'on',
                                    owner && 'used',
                                )}
                                title={label}
                            >
                                <input
                                    className="cf-sr"
                                    type="radio"
                                    name="pcolor"
                                    checked={draft.colour === colour}
                                    disabled={owner !== undefined}
                                    onChange={() =>
                                        setDraft((d) => ({ ...d, colour }))
                                    }
                                    aria-label={label}
                                />
                                <span
                                    style={{
                                        background: `var(--partner-${colour})`,
                                    }}
                                />
                            </label>
                        );
                    })}
                </div>
                <span className="cf-help">
                    Used for this partner in charts, avatars and tables.
                </span>
            </div>
        </Modal>
    );
}

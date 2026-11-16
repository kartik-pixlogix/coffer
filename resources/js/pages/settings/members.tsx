import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    Ellipsis,
    Mail,
    Minus,
    Plus,
    ShieldOff,
    UserMinus,
} from 'lucide-react';
import { useState } from 'react';
import { InviteModal } from '@/components/settings/invite-modal';
import type {
    PendingInvite,
    ShareLink,
} from '@/components/settings/invite-modal';
import { SettingsShell } from '@/components/settings/settings-shell';
import { initialsFrom } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Money } from '@/components/ui/money';
import { SearchField } from '@/components/ui/search-field';
import { Select } from '@/components/ui/select';
import { StatusPill } from '@/components/ui/status-pill';
import { useShell } from '@/components/shell/shell-context';
import { OffboardingCard } from '@/components/vault/offboarding/offboarding-card';
import { formatDate, formatRole } from '@/lib/format';
import {
    destroy as removeMember,
    resetTwoFactor,
    role as updateRole,
} from '@/routes/settings/members';
import type { CompanyRole } from '@/types';
import type { VaultOffboardingRow } from '@/types/vault';

type UserRow = {
    id: number;
    name: string;
    email: string;
    avatarUrl: string | null;
    colour: number | null;
    role: CompanyRole;
    you: boolean;
    isOwner: boolean;
    twoFactor: boolean;
    linked: { label: string; kind: string } | null;
    joined: string | null;
    lastActive: string | null;
    /** Vault items they can use now (the remove dialog, D73). */
    vaultItems: number;
};

type TeamGroup = {
    partner: { id: number; name: string; colour: number };
    members: {
        id: number;
        name: string;
        designation: string | null;
        costType: 'hourly' | 'fixed_monthly';
        ratePaise: number;
        status: 'active' | 'inactive';
        hasUser: boolean;
    }[];
};

type Props = {
    users: UserRow[];
    team: TeamGroup[];
    invites: PendingInvite[];
    shareLink: ShareLink | null;
    linkOptions: { value: string; label: string }[];
    expiryDays: number[];
    defaultExpiryDays: number;
    viewerIsAdmin: boolean;
    offboardings: VaultOffboardingRow[];
};

const ROLES: CompanyRole[] = ['admin', 'partner', 'member', 'viewer'];

function waitingText(count: number): string {
    if (count === 0) {
        return 'No invites waiting';
    }

    return count === 1 ? '1 invite is waiting' : `${count} invites are waiting`;
}

/**
 * Settings › Members & invites (design/screens/SettingsMembers.dc.html, spec §16):
 * users with their role (changeable inline), link and join date; team members by
 * partner; pending invites and the invite modal.
 */
export default function Members({
    users,
    team,
    invites,
    shareLink,
    linkOptions,
    expiryDays,
    defaultExpiryDays,
    viewerIsAdmin,
    offboardings,
}: Props) {
    const { currentCompany } = useShell();
    const { auth } = usePage().props;
    const [query, setQuery] = useState('');
    const [inviteOpen, setInviteOpen] = useState(false);
    const [removing, setRemoving] = useState<UserRow | null>(null);
    const [resetting, setResetting] = useState<UserRow | null>(null);

    const q = query.trim().toLowerCase();
    const shown =
        q === ''
            ? users
            : users.filter(
                  (u) =>
                      u.name.toLowerCase().includes(q) ||
                      u.email.toLowerCase().includes(q),
              );
    const pending = invites.length + (shareLink ? 1 : 0);

    const changeRole = (user: UserRow, role: CompanyRole) =>
        router.patch(
            updateRole(user.id).url,
            { role },
            { preserveScroll: true },
        );

    return (
        <SettingsShell
            tab="members"
            title="Members"
            crumb="Members & invites"
            actions={
                <Button
                    variant="primary"
                    size="lg"
                    disc={Plus}
                    onClick={() => setInviteOpen(true)}
                >
                    Invite user
                </Button>
            }
        >
            <Head title="Members & invites" />

            <section
                className="cf-card flex flex-col gap-3.5"
                aria-labelledby="mb-h"
            >
                <div className="cf-kv flex-wrap items-center gap-3">
                    <h2 id="mb-h" className="cf-dot-title t-title">
                        Members{' '}
                        <span className="cf-tab-count">{users.length}</span>
                    </h2>
                    <SearchField
                        aria-label="Search members"
                        placeholder="Search name or email"
                        value={query}
                        onValueChange={setQuery}
                        wrapClassName="w-[280px] max-w-full"
                    />
                </div>
                <div className="cf-table-wrap">
                    <div
                        className="cf-table min-w-[940px]"
                        role="table"
                        aria-label="Members"
                    >
                        <div className="cf-tr cf-thead ucols" role="row">
                            <span role="columnheader">User</span>
                            <span role="columnheader">Email</span>
                            <span role="columnheader">Role</span>
                            <span role="columnheader">Linked to</span>
                            <span role="columnheader">Joined</span>
                            <span role="columnheader">
                                <span className="cf-sr">Actions</span>
                            </span>
                        </div>
                        {shown.map((user) => {
                            const locked =
                                user.you ||
                                user.isOwner ||
                                (!viewerIsAdmin && user.role === 'admin');
                            const roles = viewerIsAdmin
                                ? ROLES
                                : ROLES.filter((r) => r !== 'admin');

                            return (
                                <div
                                    key={user.id}
                                    className="cf-tr ucols"
                                    role="row"
                                >
                                    <span
                                        role="cell"
                                        className="cf-row gap-2.5"
                                    >
                                        <span
                                            className={`cf-avatar ${user.colour === null ? 'cf-avatar--graphite' : ''}`}
                                            aria-hidden="true"
                                        >
                                            {user.avatarUrl ? (
                                                <img
                                                    src={user.avatarUrl}
                                                    alt=""
                                                />
                                            ) : (
                                                initialsFrom(user.name)
                                            )}
                                            {user.colour !== null && (
                                                <span
                                                    className="cf-avatar-dot"
                                                    style={{
                                                        background: `var(--partner-${user.colour})`,
                                                    }}
                                                />
                                            )}
                                        </span>
                                        <span className="t-label-strong">
                                            {user.name}
                                        </span>
                                        {user.you && (
                                            <span className="cf-pill cf-pill--outline cf-pill--sm">
                                                You
                                            </span>
                                        )}
                                    </span>
                                    <span
                                        role="cell"
                                        className="t-body t-muted truncate"
                                    >
                                        {user.email}
                                    </span>
                                    <span role="cell">
                                        <Select
                                            pill
                                            aria-label={`Role for ${user.name}`}
                                            value={user.role}
                                            disabled={locked}
                                            title={
                                                user.isOwner && !user.you
                                                    ? 'Owns the workspace, so stays an Admin'
                                                    : undefined
                                            }
                                            onChange={(e) =>
                                                changeRole(
                                                    user,
                                                    e.target
                                                        .value as CompanyRole,
                                                )
                                            }
                                            options={(locked
                                                ? ROLES
                                                : roles
                                            ).map((r) => ({
                                                value: r,
                                                label: formatRole(r),
                                            }))}
                                        />
                                    </span>
                                    <span role="cell" className="cf-stack">
                                        <span className="t-body">
                                            {user.linked?.label ?? 'Not linked'}
                                        </span>
                                        {user.linked && (
                                            <span className="t-caption t-muted">
                                                {user.linked.kind}
                                            </span>
                                        )}
                                    </span>
                                    <span
                                        role="cell"
                                        className="t-body t-muted"
                                    >
                                        {user.joined
                                            ? formatDate(user.joined)
                                            : '—'}
                                    </span>
                                    <span role="cell" className="cf-rowacts">
                                        {!user.you && (
                                            <DropdownMenu
                                                aria-label={`Actions for ${user.name}`}
                                                align="end"
                                                trigger={
                                                    <IconButton
                                                        icon={Ellipsis}
                                                        size="sm"
                                                        aria-label={`More for ${user.name}`}
                                                    />
                                                }
                                                entries={[
                                                    {
                                                        label: 'Reset two-factor',
                                                        icon: ShieldOff,
                                                        disabled:
                                                            !viewerIsAdmin ||
                                                            !user.twoFactor,
                                                        onSelect: () =>
                                                            setResetting(user),
                                                    },
                                                    { type: 'separator' },
                                                    {
                                                        label: `Remove from ${currentCompany.name}`,
                                                        icon: UserMinus,
                                                        danger: true,
                                                        disabled:
                                                            user.isOwner ||
                                                            (!viewerIsAdmin &&
                                                                user.role ===
                                                                    'admin'),
                                                        onSelect: () =>
                                                            setRemoving(user),
                                                    },
                                                ]}
                                            />
                                        )}
                                    </span>
                                </div>
                            );
                        })}
                        {shown.length === 0 && (
                            <div className="cf-empty-row t-body t-muted">
                                No one matches “{query}”.
                            </div>
                        )}
                    </div>
                </div>
            </section>

            {team.length > 0 && (
                <section
                    className="cf-card flex flex-col gap-3.5"
                    aria-labelledby="tm-h"
                >
                    <h2 id="tm-h" className="cf-dot-title t-title">
                        Team members by partner
                    </h2>
                    <div className="cf-table-wrap">
                        <div
                            className="cf-table min-w-[640px]"
                            role="table"
                            aria-label="Team members by partner"
                        >
                            <div className="cf-tr cf-thead tcols" role="row">
                                <span role="columnheader">Name</span>
                                <span role="columnheader">Designation</span>
                                <span role="columnheader">Rate</span>
                                <span role="columnheader">Status</span>
                            </div>
                            {team.map((group) => (
                                <div
                                    key={group.partner.id}
                                    role="rowgroup"
                                    className="contents"
                                >
                                    <div
                                        className="pmg cf-row gap-2"
                                        role="row"
                                    >
                                        <span
                                            role="rowheader"
                                            className="cf-row gap-2"
                                        >
                                            <span
                                                className="cf-swatch"
                                                style={{
                                                    background: `var(--partner-${group.partner.colour})`,
                                                }}
                                            />
                                            {group.partner.name}
                                        </span>
                                    </div>
                                    {group.members.map((m) => (
                                        <div
                                            key={m.id}
                                            className="cf-tr tcols"
                                            role="row"
                                        >
                                            <span
                                                role="cell"
                                                className="t-label-strong"
                                            >
                                                {m.name}
                                            </span>
                                            <span
                                                role="cell"
                                                className="t-body t-muted"
                                            >
                                                {m.designation ?? '—'}
                                            </span>
                                            <span
                                                role="cell"
                                                className="t-money-sm"
                                            >
                                                <Money
                                                    paise={m.ratePaise}
                                                    size="sm"
                                                />
                                                {m.costType === 'hourly'
                                                    ? ' / hour'
                                                    : ' / month'}
                                            </span>
                                            <span role="cell">
                                                {m.status === 'active' ? (
                                                    <StatusPill
                                                        tone="pos"
                                                        icon={Check}
                                                        size="sm"
                                                    >
                                                        Active
                                                    </StatusPill>
                                                ) : (
                                                    <StatusPill
                                                        tone="neutral"
                                                        icon={Minus}
                                                        size="sm"
                                                    >
                                                        Inactive
                                                    </StatusPill>
                                                )}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            <OffboardingCard
                offboardings={offboardings}
                canDismiss={viewerIsAdmin}
                timeZone={auth.user.timezone}
            />

            <section
                className="cf-alert cf-alert--info cf-alert--slim"
                aria-label="Pending invites"
            >
                <div className="cf-alert-main">
                    <span className="cf-alert-ic members-alert-ic">
                        <Icon icon={Mail} size={18} />
                    </span>
                    <div>
                        <div className="cf-alert-title">
                            {waitingText(pending)}
                        </div>
                        <div className="cf-alert-sub">
                            Invites and share links expire on their own. Revoke
                            one to stop it working straight away.
                        </div>
                    </div>
                </div>
                <Button variant="secondary" onClick={() => setInviteOpen(true)}>
                    Manage invites
                </Button>
            </section>

            <InviteModal
                open={inviteOpen}
                onOpenChange={setInviteOpen}
                companyName={currentCompany.name}
                invites={invites}
                shareLink={shareLink}
                linkOptions={linkOptions}
                expiryDays={expiryDays}
                defaultExpiryDays={defaultExpiryDays}
                canInviteAdmins={viewerIsAdmin}
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                icon={UserMinus}
                title={`Remove ${removing?.name ?? ''} from ${currentCompany.name}?`}
                description={`They lose access to this company straight away. Their account, and their records here, stay.${removing && removing.vaultItems > 0 ? ` They can use ${removing.vaultItems} vault ${removing.vaultItems === 1 ? 'item' : 'items'}: you’ll get a list to rotate.` : ''}`}
                confirmLabel="Remove"
                cancelLabel="Keep them"
                onConfirm={() => {
                    if (removing) {
                        router.delete(removeMember(removing.id).url, {
                            preserveScroll: true,
                        });
                    }

                    setRemoving(null);
                }}
            />

            <ConfirmDialog
                open={resetting !== null}
                onOpenChange={(open) => !open && setResetting(null)}
                icon={ShieldOff}
                title={`Reset two-factor for ${resetting?.name ?? ''}?`}
                description="Use this when they’ve lost their phone. They sign in with their password and set up 2FA again from Profile."
                confirmLabel="Reset 2FA"
                cancelLabel="Cancel"
                onConfirm={() => {
                    if (resetting) {
                        router.post(
                            resetTwoFactor(resetting.id).url,
                            {},
                            { preserveScroll: true },
                        );
                    }

                    setResetting(null);
                }}
            />
        </SettingsShell>
    );
}

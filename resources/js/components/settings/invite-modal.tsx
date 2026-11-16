import { router } from '@inertiajs/react';
import { ArrowRight, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { formatDate, formatRole } from '@/lib/format';
import {
    destroy as revokeInvite,
    link as makeLink,
    resend as resendInvite,
    store as sendInvites,
} from '@/routes/settings/invites';
import type { CompanyRole } from '@/types';

export type PendingInvite = {
    id: number;
    email: string;
    role: CompanyRole;
    expiresAt: string;
};

export type ShareLink = {
    id: number;
    url: string;
    role: CompanyRole;
    expiresAt: string;
    uses: number;
    tail: string;
};

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const ROLE_DESCRIPTIONS: Record<CompanyRole, string> = {
    admin: 'Everything, including settings, billing and closing the fiscal year.',
    partner:
        'All finances and reports. Records income, expenses and settlements.',
    member: 'Logs their own hours and expenses. Sees vault items shared with them.',
    viewer: 'Read-only reports and statements. Good for your accountant.',
};

/** "expires in 5 days", "expires today" */
function expiresIn(iso: string): string {
    const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);

    if (days <= 0) {
        return 'expires today';
    }

    return days === 1 ? 'expires tomorrow' : `expires in ${days} days`;
}

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyName: string;
    invites: PendingInvite[];
    shareLink: ShareLink | null;
    linkOptions: { value: string; label: string }[];
    expiryDays: number[];
    defaultExpiryDays: number;
    /** Only Admins can invite Admins. */
    canInviteAdmins: boolean;
};

/**
 * "Invite people" (SettingsMembers.dc.html invite modal, spec §2.3): email invites with
 * a role and optional link, the share link with its expiry, and the pending list with
 * Resend and Revoke.
 */
export function InviteModal({
    open,
    onOpenChange,
    companyName,
    invites,
    shareLink,
    linkOptions,
    expiryDays,
    defaultExpiryDays,
    canInviteAdmins,
}: Props) {
    const [emails, setEmails] = useState('');
    const [role, setRole] = useState<CompanyRole>('member');
    const [link, setLink] = useState('');
    const [days, setDays] = useState(defaultExpiryDays);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [sending, setSending] = useState(false);
    const [copied, setCopied] = useState(false);

    // "Copy" reads "Copied ✓" for 1.8 s (design).
    const copy = (url: string) => {
        void navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        });
    };

    const list = emails
        .split(/[\s,;]+/)
        .map((e) => e.trim())
        .filter(Boolean);
    const emailBad = list.some((e) => !EMAIL.test(e));
    const roles: CompanyRole[] = canInviteAdmins
        ? ['admin', 'partner', 'member', 'viewer']
        : ['partner', 'member', 'viewer'];

    const send = () => {
        router.post(
            sendInvites().url,
            { emails, role, link: link || null, days },
            {
                preserveScroll: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onSuccess: () => {
                    setEmails('');
                    setLink('');
                    setErrors({});
                },
                onError: setErrors,
            },
        );
    };

    const pendingCount = invites.length + (shareLink ? 1 : 0);

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            className="invite-modal"
            icon={UserPlus}
            title="Invite people"
            subtitle={`${companyName} · they get an email with a sign-up link`}
            dirty={emails !== ''}
            footer={
                <>
                    <Button
                        variant="secondary"
                        size="lg"
                        onClick={() => onOpenChange(false)}
                    >
                        Done
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={ArrowRight}
                        loading={sending}
                        disabled={list.length === 0 || emailBad}
                        onClick={send}
                    >
                        Send invite
                    </Button>
                </>
            }
        >
            <div className="invite-cols">
                <div className="flex flex-col gap-3.5">
                    <TextInput
                        id="iv-e"
                        label="Email"
                        placeholder="name@company.in"
                        value={emails}
                        onChange={(e) => setEmails(e.target.value)}
                        help="Separate several emails with commas."
                        error={
                            emailBad
                                ? 'Check the email address.'
                                : errors.emails
                        }
                    />
                    <div className="cf-field">
                        <span className="cf-label" id="iv-r">
                            Role
                        </span>
                        <SegmentedControl<CompanyRole>
                            aria-labelledby="iv-r"
                            value={role}
                            options={roles.map((r) => ({
                                value: r,
                                label: formatRole(r),
                            }))}
                            onValueChange={setRole}
                        />
                        <span className="cf-inset t-caption invite-desc">
                            {ROLE_DESCRIPTIONS[role]}
                        </span>
                    </div>
                    <Select
                        id="iv-l"
                        label="Link to"
                        value={link}
                        onChange={(e) => setLink(e.target.value)}
                        placeholder="Not linked"
                        options={linkOptions}
                        help="Linking puts their hours and expenses under their name."
                        error={errors.link}
                    />
                </div>

                <div className="invite-side">
                    <div className="cf-field">
                        <span className="cf-label" id="iv-s">
                            Or share a link
                        </span>
                        {shareLink ? (
                            <>
                                <div className="cf-row gap-2">
                                    <span
                                        className="cf-inwrap is-readonly min-w-0 flex-1"
                                        aria-labelledby="iv-s"
                                    >
                                        <span className="t-money-sm truncate">
                                            {shareLink.url}
                                        </span>
                                    </span>
                                    <Button
                                        variant="secondary"
                                        aria-live="polite"
                                        onClick={() => copy(shareLink.url)}
                                    >
                                        {copied ? 'Copied ✓' : 'Copy'}
                                    </Button>
                                </div>
                                <span className="cf-help">
                                    Anyone with this link joins as{' '}
                                    {roleArticle(shareLink.role)}. It stops
                                    working on {formatDate(shareLink.expiresAt)}
                                    .
                                </span>
                            </>
                        ) : (
                            <span className="cf-help">
                                No share link yet. Make one to let people join
                                as {roleArticle(role)}.
                            </span>
                        )}
                    </div>
                    <div className="cf-field">
                        <span className="cf-label" id="iv-x">
                            Link expires after
                        </span>
                        <div className="cf-row flex-wrap gap-2">
                            <SegmentedControl<string>
                                size="sm"
                                aria-labelledby="iv-x"
                                value={String(days)}
                                options={expiryDays.map((d) => ({
                                    value: String(d),
                                    label: d === 1 ? '1 day' : `${d} days`,
                                }))}
                                onValueChange={(value) =>
                                    setDays(Number(value))
                                }
                            />
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        makeLink().url,
                                        { role, days },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {shareLink ? 'New link' : 'Make a link'}
                            </Button>
                        </div>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <div className="cf-kv">
                            <span className="cf-label">Pending invites</span>
                            <span className="t-caption t-muted">
                                {pendingCount}
                            </span>
                        </div>
                        {invites.map((invite) => (
                            <div
                                key={invite.id}
                                className="cf-inset invite-pending"
                            >
                                <span className="cf-stack min-w-0 flex-1">
                                    <span className="t-label-strong truncate">
                                        {invite.email}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {formatRole(invite.role)} ·{' '}
                                        {expiresIn(invite.expiresAt)}
                                    </span>
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            resendInvite(invite.id).url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    aria-label={`Resend the invite to ${invite.email}`}
                                >
                                    Resend
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="invite-revoke"
                                    onClick={() =>
                                        router.delete(
                                            revokeInvite(invite.id).url,
                                            { preserveScroll: true },
                                        )
                                    }
                                    aria-label={`Revoke the invite for ${invite.email}`}
                                >
                                    Revoke
                                </Button>
                            </div>
                        ))}
                        {shareLink && (
                            <div className="cf-inset invite-pending">
                                <span className="cf-stack min-w-0 flex-1">
                                    <span className="t-label-strong truncate">
                                        Share link ··{shareLink.tail}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {formatRole(shareLink.role)} ·{' '}
                                        {shareLink.uses} joined ·{' '}
                                        {expiresIn(shareLink.expiresAt)}
                                    </span>
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="invite-revoke"
                                    onClick={() =>
                                        router.delete(
                                            revokeInvite(shareLink.id).url,
                                            { preserveScroll: true },
                                        )
                                    }
                                    aria-label="Revoke the share link"
                                >
                                    Revoke
                                </Button>
                            </div>
                        )}
                        {pendingCount === 0 && (
                            <span className="t-caption t-muted">
                                No pending invites.
                            </span>
                        )}
                    </div>
                </div>
            </div>
        </Modal>
    );
}

const roleArticle = (role: CompanyRole) =>
    `${role === 'admin' ? 'an' : 'a'} ${formatRole(role)}`;

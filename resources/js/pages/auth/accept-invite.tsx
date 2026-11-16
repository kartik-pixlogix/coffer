import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Check, Clock, X } from 'lucide-react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button, ButtonLink } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { PasswordField } from '@/components/ui/password-field';
import { TextInput } from '@/components/ui/text-input';
import {
    AuthWordmark,
    GuestThemeToggle,
} from '@/layouts/auth/auth-split-layout';
import { formatDate, formatPercent, formatRole } from '@/lib/format';
import { initialsFrom } from '@/components/ui/avatar';
import { login, logout } from '@/routes';
import { redirect as googleRedirect } from '@/routes/google';
import {
    accept as acceptInvite,
    decline as declineInvite,
    renew as renewInvite,
} from '@/routes/invites';
import type { CompanyRole, CompanyType } from '@/types';

type Props = {
    token: string;
    valid: boolean;
    invite: {
        email: string | null;
        role: CompanyRole;
        sentAt: string | null;
        expiresAt: string;
        endedAt: string;
        lifetimeDays: number;
        isLink: boolean;
    };
    inviter: { name: string };
    company: {
        name: string;
        initials: string;
        type: CompanyType;
        sides: string[];
    };
    partner: { name: string; side: string | null; equityBps: number } | null;
    abilities: { can: string[]; cannot: string[] };
    /** Signed in: whose account, and whether the invite is for that email. */
    account: { email: string; matches: boolean } | null;
    /** An account already uses the invite's email: sign in, then join. */
    existingAccount: boolean;
    googleEnabled: boolean;
    renewalRequested: boolean;
};

/** "Joint Venture · Vertex Labs and Bluefin Infotech" */
function companyLine(company: Props['company']): string {
    if (company.type !== 'jv') {
        return 'Single company';
    }

    const sides =
        company.sides.length > 1
            ? `${company.sides.slice(0, -1).join(', ')} and ${company.sides[company.sides.length - 1]}`
            : (company.sides[0] ?? '');

    return sides ? `Joint Venture · ${sides}` : 'Joint Venture';
}

function InviteCard({
    inviter,
    company,
    invite,
    partner,
}: Pick<Props, 'inviter' | 'company' | 'invite' | 'partner'>) {
    return (
        <div className="cf-card--lime invite-card">
            <div aria-hidden="true" className="invite-blob" />
            <div className="invite-row">
                <span
                    className="cf-avatar cf-avatar--lg invite-inviter"
                    aria-hidden="true"
                >
                    {initialsFrom(inviter.name)}
                </span>
                <span className="t-body">
                    <span className="font-semibold">{inviter.name}</span>{' '}
                    invited you to join
                </span>
            </div>
            <div className="invite-row gap-3.5">
                <span
                    className="cf-switcher-ini invite-tile"
                    aria-hidden="true"
                >
                    {company.initials}
                </span>
                <span className="cf-stack">
                    <span className="t-heading invite-company">
                        {company.name}
                    </span>
                    <span className="t-body">{companyLine(company)}</span>
                </span>
            </div>
            <div className="invite-pills">
                <span className="cf-pill invite-pill--strong">
                    Role: {formatRole(invite.role)}
                </span>
                {partner?.side && (
                    <span className="cf-pill invite-pill">
                        Side: {partner.side}
                    </span>
                )}
                {partner && (
                    <span className="cf-pill invite-pill">
                        Equity {formatPercent(partner.equityBps / 100, 0)}
                        {partner.side ? ' of side' : ''}
                    </span>
                )}
            </div>
        </div>
    );
}

function Abilities({
    role,
    abilities,
}: {
    role: CompanyRole;
    abilities: Props['abilities'];
}) {
    return (
        <div className="cf-card flex flex-col gap-2.5">
            <span className="t-title">As {roleArticle(role)} you can</span>
            <ul className="t-body invite-list">
                {abilities.can.map((line) => (
                    <li key={line} className="cf-row">
                        <Icon
                            icon={Check}
                            size={16}
                            strokeWidth={2}
                            className="invite-yes"
                        />
                        {line}
                    </li>
                ))}
                {abilities.cannot.map((line) => (
                    <li key={line} className="cf-row t-muted">
                        <Icon icon={X} size={16} strokeWidth={2} />
                        {line}
                    </li>
                ))}
            </ul>
        </div>
    );
}

const roleArticle = (role: CompanyRole) =>
    `${role === 'admin' ? 'an' : 'a'} ${formatRole(role)}`;

function JoinForm(props: Props) {
    const { token, invite, company, account, existingAccount } = props;
    const join = `Join ${company.name}`;

    if (account !== null && !account.matches) {
        return (
            <>
                <AlertBanner variant="warn" slim role="status">
                    This invite is for {invite.email}. You’re signed in as{' '}
                    {account.email}.
                </AlertBanner>
                <Form {...logout.form()}>
                    <Button type="submit" variant="secondary" size="lg">
                        Sign out and switch account
                    </Button>
                </Form>
            </>
        );
    }

    if (account !== null) {
        return (
            <Form {...acceptInvite.form(token)} className="flex flex-col gap-3">
                {({ processing, errors }) => (
                    <>
                        {errors.invite && (
                            <AlertBanner variant="neg" slim role="alert">
                                {errors.invite}
                            </AlertBanner>
                        )}
                        <p className="t-body t-muted m-0">
                            You’ll join as {account.email}.
                        </p>
                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={processing}
                            className="w-full justify-between"
                        >
                            {join}
                        </Button>
                    </>
                )}
            </Form>
        );
    }

    if (existingAccount) {
        return (
            <>
                <TextInput
                    id="ai-email"
                    label="Email"
                    type="email"
                    value={invite.email ?? ''}
                    readOnly
                />
                <p className="t-body t-muted m-0">
                    You already have a Coffer account. Sign in and you’ll join{' '}
                    {company.name} straight away.
                </p>
                <ButtonLink
                    href={login().url}
                    variant="primary"
                    size="lg"
                    disc={ArrowRight}
                    className="w-full justify-between"
                >
                    Sign in to join
                </ButtonLink>
            </>
        );
    }

    return (
        <Form
            {...acceptInvite.form(token)}
            resetOnError={['password']}
            className="flex flex-col gap-5"
        >
            {({ processing, errors }) => (
                <>
                    {errors.invite && (
                        <AlertBanner variant="neg" slim role="alert">
                            {errors.invite}
                        </AlertBanner>
                    )}
                    {invite.isLink && (
                        <TextInput
                            id="ai-name"
                            name="name"
                            label="Your name"
                            autoComplete="name"
                            required
                            error={errors.name}
                        />
                    )}
                    <TextInput
                        id="ai-email"
                        name={invite.isLink ? 'email' : undefined}
                        label="Email"
                        type="email"
                        autoComplete="email"
                        defaultValue={invite.email ?? ''}
                        readOnly={!invite.isLink}
                        required={invite.isLink}
                        error={errors.email}
                    />
                    <PasswordField
                        id="ai-pass"
                        name="password"
                        label="Create a password"
                        autoComplete="new-password"
                        placeholder="At least 12 characters"
                        revealable={false}
                        required
                        error={errors.password}
                    />
                    <div className="flex flex-wrap gap-2.5">
                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={processing}
                            className="justify-between"
                            style={{ flex: '1 1 220px' }}
                        >
                            {join}
                        </Button>
                        <a
                            href={
                                googleRedirect({
                                    query: { intent: 'invite', invite: token },
                                }).url
                            }
                            className="cf-btn cf-btn--secondary cf-btn--lg"
                            style={{ flex: '1 1 200px' }}
                        >
                            <span
                                className="cf-avatar cf-avatar--sm auth-google"
                                aria-hidden="true"
                            >
                                G
                            </span>
                            Join with Google
                        </a>
                    </div>
                </>
            )}
        </Form>
    );
}

function Expired({
    token,
    inviter,
    company,
    invite,
    renewalRequested,
}: Pick<
    Props,
    'token' | 'inviter' | 'company' | 'invite' | 'renewalRequested'
>) {
    const first = inviter.name.split(/\s+/u)[0] ?? inviter.name;

    return (
        <div className="cf-empty invite-expired">
            <span className="cf-empty-ic cf-empty-ic--muted">
                <Icon icon={Clock} size={24} />
            </span>
            <h1 className="t-heading m-0">This invite has expired</h1>
            <p>
                {inviter.name}’s invite to {company.name} ended on{' '}
                {formatDate(invite.endedAt)}. Invites last {invite.lifetimeDays}{' '}
                days so old links can’t be reused.
            </p>
            {renewalRequested ? (
                <AlertBanner variant="pos" slim role="status">
                    We asked {first} for a new link. It will come to your email.
                </AlertBanner>
            ) : (
                <div className="cf-actions justify-center">
                    <Button
                        variant="primary"
                        size="lg"
                        onClick={() => router.post(renewInvite(token).url)}
                    >
                        Ask {first} for a new link
                    </Button>
                    <ButtonLink
                        href={login().url}
                        variant="secondary"
                        size="lg"
                    >
                        Sign in instead
                    </ButtonLink>
                </div>
            )}
            <p className="t-caption m-0">
                We’ll email {first} at the address that sent the invite. Nothing
                is shared with them but your request.
            </p>
        </div>
    );
}

/**
 * The invite page (design/screens/AcceptInvite.dc.html): who invited you, to which
 * company, as what, and what the role can do; then join with a password, Google, or
 * as the account you're signed in with. Expired, revoked or used links show the
 * expired state with "Ask for a new link".
 */
export default function AcceptInvite(props: Props) {
    const { token, valid, invite, company, account } = props;

    return (
        <div className="cf-page">
            <Head title={`Join ${company.name}`} />
            <div className="invite-frame">
                <div className="invite-head">
                    <AuthWordmark />
                    <GuestThemeToggle />
                </div>

                <main className="invite-main">
                    {valid ? (
                        <div className="invite-col">
                            <InviteCard {...props} />
                            <Abilities
                                role={invite.role}
                                abilities={props.abilities}
                            />
                            <JoinForm {...props} />
                            <p className="t-caption t-muted m-0">
                                {invite.sentAt &&
                                    `This invite was sent on ${formatDate(invite.sentAt)} and works until ${formatDate(invite.expiresAt)}. `}
                                {!invite.isLink && account === null && (
                                    <>
                                        Not you?{' '}
                                        <Link
                                            href={declineInvite(token).url}
                                            method="post"
                                            as="button"
                                            className="cf-link p-0"
                                        >
                                            Decline it
                                        </Link>
                                        .
                                    </>
                                )}
                            </p>
                        </div>
                    ) : (
                        <Expired {...props} />
                    )}
                </main>
            </div>
        </div>
    );
}

import { Head, router } from '@inertiajs/react';
import { Laptop, Smartphone } from 'lucide-react';
import { useRef, useState } from 'react';
import { MeterDots, StrengthLabel } from '@/components/auth/auth-parts';
import { TwoFactorCard } from '@/components/account/two-factor-card';
import type { TwoFactorProps } from '@/components/account/two-factor-card';
import { PageHeader } from '@/components/shell/page-header';
import { initialsFrom } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { PasswordField } from '@/components/ui/password-field';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { formatDate } from '@/lib/format';
import { profileStrength } from '@/lib/password-strength';
import { update as updateProfile } from '@/routes/profile';
import {
    destroy as removeAvatar,
    update as uploadAvatar,
} from '@/routes/profile/avatar';
import { update as updatePassword } from '@/routes/profile/password';
import {
    destroy as signOutSession,
    destroyOthers as signOutOthers,
} from '@/routes/profile/sessions';
import type { NumberFormat } from '@/types';

type Profile = {
    name: string;
    email: string;
    verified: boolean;
    timezone: string;
    numberFormat: NumberFormat;
    avatarUrl: string | null;
    colour: number | null;
};

type Session = {
    id: string;
    device: string;
    mobile: boolean;
    ip: string | null;
    current: boolean;
    lastActive: string;
};

type Props = {
    profile: Profile;
    timezones: { value: string; label: string }[];
    password: { changedAt: string | null };
    twoFactor: TwoFactorProps;
    sessions: Session[];
};

/** "Active now", "Last active 2 hours ago", "12 Nov 2026" */
function lastActive(session: Session): string {
    if (session.current) {
        return 'Active now';
    }

    const minutes = Math.round(
        (Date.now() - new Date(session.lastActive).getTime()) / 60_000,
    );

    if (minutes < 5) {
        return 'Active now';
    }

    if (minutes < 60) {
        return `Last active ${minutes} minutes ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `Last active ${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;
    }

    const days = Math.round(hours / 24);

    return days < 7
        ? `Last active ${days} ${days === 1 ? 'day' : 'days'} ago`
        : formatDate(session.lastActive);
}

function ProfileDetails({
    form,
    setForm,
    profile,
    timezones,
    errors,
}: {
    form: Pick<Profile, 'name' | 'email' | 'timezone' | 'numberFormat'>;
    setForm: (patch: Partial<Props['profile']>) => void;
    profile: Profile;
    timezones: Props['timezones'];
    errors: Record<string, string>;
}) {
    const file = useRef<HTMLInputElement>(null);

    return (
        <section className="cf-card flex flex-col gap-4" aria-labelledby="pd-h">
            <h2 id="pd-h" className="cf-dot-title t-title">
                Profile details
            </h2>
            <div className="cf-row gap-4">
                <span
                    className="cf-avatar cf-avatar--xl profile-avatar"
                    aria-hidden="true"
                >
                    {profile.avatarUrl ? (
                        <img src={profile.avatarUrl} alt="" />
                    ) : (
                        initialsFrom(profile.name)
                    )}
                    {profile.colour !== null && (
                        <span
                            className="cf-avatar-dot"
                            style={{
                                background: `var(--partner-${profile.colour})`,
                            }}
                        />
                    )}
                </span>
                <div className="cf-stack gap-2">
                    <span className="t-caption t-muted">
                        Your colour comes from your partner settings.
                    </span>
                    <div className="cf-row">
                        <input
                            ref={file}
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            className="cf-sr"
                            aria-label="Upload photo"
                            onChange={(e) => {
                                const chosen = e.target.files?.[0];

                                if (chosen) {
                                    router.post(
                                        uploadAvatar().url,
                                        { avatar: chosen },
                                        {
                                            forceFormData: true,
                                            preserveScroll: true,
                                        },
                                    );
                                }

                                e.target.value = '';
                            }}
                        />
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => file.current?.click()}
                        >
                            Upload photo
                        </Button>
                        {profile.avatarUrl && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.delete(removeAvatar().url, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                    {errors.avatar && (
                        <span className="cf-errtext">{errors.avatar}</span>
                    )}
                </div>
            </div>
            <div className="profile-grid">
                <TextInput
                    id="pf-n"
                    label="Full name"
                    value={form.name}
                    maxLength={255}
                    onChange={(e) => setForm({ name: e.target.value })}
                    error={errors.name}
                />
                <TextInput
                    id="pf-e"
                    type="email"
                    label="Email"
                    value={form.email}
                    onChange={(e) => setForm({ email: e.target.value })}
                    help={
                        form.email !== profile.email
                            ? 'We’ll send a link to confirm the new address.'
                            : undefined
                    }
                    error={errors.email}
                    labelAction={
                        profile.verified ? (
                            <span className="cf-pill cf-pill--sm cf-pill--pos">
                                Verified
                            </span>
                        ) : (
                            <span className="cf-pill cf-pill--sm cf-pill--warn">
                                Not verified
                            </span>
                        )
                    }
                />
                <Select
                    id="pf-t"
                    label="Time zone"
                    value={form.timezone}
                    onChange={(e) => setForm({ timezone: e.target.value })}
                    options={timezones}
                    error={errors.timezone}
                />
                <div className="cf-field">
                    <span className="cf-label" id="pf-f">
                        Number format
                    </span>
                    <SegmentedControl<NumberFormat>
                        aria-labelledby="pf-f"
                        value={form.numberFormat}
                        options={[
                            { value: 'indian', label: 'Indian · 12,34,567' },
                            {
                                value: 'international',
                                label: 'International · 1,234,567',
                            },
                        ]}
                        onValueChange={(numberFormat) =>
                            setForm({ numberFormat })
                        }
                    />
                    <span className="cf-help">
                        {form.numberFormat === 'indian'
                            ? 'Lakh and crore grouping, like ₹18,42,350.'
                            : 'Thousands grouping, like ₹1,842,350.'}
                    </span>
                </div>
            </div>
        </section>
    );
}

function PasswordCard({ changedAt }: { changedAt: string | null }) {
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [repeat, setRepeat] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const meter = profileStrength(next);
    const mismatch = repeat !== '' && repeat !== next;
    const cant = current === '' || !meter.ok || repeat !== next;

    const change = () =>
        router.put(
            updatePassword().url,
            {
                current_password: current,
                password: next,
                password_confirmation: repeat,
            },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    setCurrent('');
                    setNext('');
                    setRepeat('');
                    setErrors({});
                },
                onError: setErrors,
            },
        );

    return (
        <section className="cf-card flex flex-col gap-3" aria-labelledby="pw-h">
            <div className="cf-kv">
                <h2 id="pw-h" className="cf-dot-title t-title">
                    Password
                </h2>
                <span className="t-caption t-muted">
                    {changedAt
                        ? `Last changed ${formatDate(changedAt)}`
                        : 'Never changed'}
                </span>
            </div>
            <PasswordField
                id="pw-c"
                label="Current password"
                autoComplete="current-password"
                value={current}
                onChange={(e) => setCurrent(e.target.value)}
                error={errors.current_password}
            />
            <div className="profile-grid">
                <PasswordField
                    id="pw-n"
                    label="New password"
                    autoComplete="new-password"
                    revealable={false}
                    value={next}
                    onChange={(e) => setNext(e.target.value)}
                    labelAction={<StrengthLabel meter={meter} />}
                    meter={<MeterDots meter={meter} />}
                    error={errors.password}
                />
                <PasswordField
                    id="pw-r"
                    label="Repeat new password"
                    autoComplete="new-password"
                    revealable={false}
                    value={repeat}
                    onChange={(e) => setRepeat(e.target.value)}
                    error={
                        mismatch ? 'The two passwords don’t match.' : undefined
                    }
                />
            </div>
            <div className="cf-row flex-wrap justify-between">
                <span className="t-caption t-muted">
                    At least 12 characters. Changing it signs out your other
                    sessions.
                </span>
                <Button
                    variant="secondary"
                    loading={busy}
                    disabled={cant}
                    onClick={change}
                >
                    Change password
                </Button>
            </div>
        </section>
    );
}

function SessionsCard({ sessions }: { sessions: Session[] }) {
    const others = sessions.filter((s) => !s.current).length;

    return (
        <section className="cf-card flex flex-col gap-2" aria-labelledby="ss-h">
            <div className="cf-kv mb-1">
                <h2 id="ss-h" className="cf-dot-title t-title">
                    Sessions and devices
                </h2>
                <span className="t-caption t-muted">
                    {sessions.length}{' '}
                    {sessions.length === 1 ? 'session' : 'sessions'}
                </span>
            </div>
            {sessions.map((s) => (
                <div key={s.id} className="sess">
                    <span
                        className="cf-avatar cf-avatar--tile cf-avatar--graphite"
                        aria-hidden="true"
                    >
                        <Icon icon={s.mobile ? Smartphone : Laptop} size={18} />
                    </span>
                    <span className="cf-stack min-w-0">
                        <span className="cf-row flex-wrap gap-1.5">
                            <span className="t-label-strong">{s.device}</span>
                            {s.current && (
                                <span className="cf-pill cf-pill--sm cf-pill--pos">
                                    This device
                                </span>
                            )}
                        </span>
                        <span className="t-caption t-muted">
                            {s.ip ?? 'Unknown IP'} · {lastActive(s)}
                        </span>
                    </span>
                    {!s.current && (
                        <Button
                            variant="secondary"
                            size="sm"
                            aria-label={`Sign out ${s.device}`}
                            onClick={() =>
                                router.delete(signOutSession(s.id).url, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Sign out
                        </Button>
                    )}
                </div>
            ))}
            <Button
                variant="danger"
                size="sm"
                className="mt-1.5 self-start"
                disabled={others === 0}
                onClick={() =>
                    router.delete(signOutOthers().url, { preserveScroll: true })
                }
            >
                Sign out of all other sessions
            </Button>
        </section>
    );
}

/**
 * Account › Profile and security (design/screens/Profile.dc.html, spec §17): profile
 * details with number format, password, sessions, and two-factor authentication.
 */
export default function ProfilePage({
    profile,
    timezones,
    password,
    twoFactor,
    sessions,
}: Props) {
    const saved = {
        name: profile.name,
        email: profile.email,
        timezone: profile.timezone,
        numberFormat: profile.numberFormat,
    };
    const [form, setFormState] = useState(saved);
    const [last, setLast] = useState(profile);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    if (last !== profile) {
        setLast(profile);
        setFormState(saved);
    }

    const dirty = (Object.keys(saved) as (keyof typeof saved)[]).some(
        (k) => form[k] !== saved[k],
    );

    const save = () =>
        router.patch(
            updateProfile().url,
            {
                name: form.name,
                email: form.email,
                timezone: form.timezone,
                number_format: form.numberFormat,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setErrors({}),
                onError: setErrors,
            },
        );

    return (
        <>
            <Head title="Profile and security" />
            <PageHeader
                bare
                kicker="Account"
                title="Profile"
                crumbs={[
                    { title: 'Account' },
                    { title: 'Profile and security' },
                ]}
                actions={
                    <>
                        {dirty && (
                            <Button
                                variant="ghost"
                                size="lg"
                                onClick={() => setFormState(saved)}
                            >
                                Discard
                            </Button>
                        )}
                        <Button
                            variant="primary"
                            size="lg"
                            loading={saving}
                            disabled={!dirty}
                            onClick={save}
                        >
                            Save profile
                        </Button>
                    </>
                }
            />

            <div className="profile-body">
                <div className="profile-col">
                    <ProfileDetails
                        form={form}
                        setForm={(patch) =>
                            setFormState((f) => ({ ...f, ...patch }))
                        }
                        profile={profile}
                        timezones={timezones}
                        errors={errors}
                    />
                    <PasswordCard changedAt={password.changedAt} />
                    <SessionsCard sessions={sessions} />
                </div>
                <TwoFactorCard twoFactor={twoFactor} email={profile.email} />
            </div>
        </>
    );
}

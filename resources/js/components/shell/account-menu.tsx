import { usePage } from '@inertiajs/react';
import { ChevronDown, LogOut, Palette, UserRound } from 'lucide-react';
import type { ButtonHTMLAttributes, Ref } from 'react';
import { signOut } from '@/components/shell/sign-out';
import { useShell } from '@/components/shell/shell-context';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { useInitials } from '@/hooks/use-initials';
import { formatRole } from '@/lib/format';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';

type AccountPillProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    name: string;
    role: string;
    initials: string;
    ref?: Ref<HTMLButtonElement>;
};

/** The `cf-account` pill; spreads the trigger props Radix passes through `asChild`. */
function AccountPill({ name, role, initials, ...rest }: AccountPillProps) {
    return (
        <button
            type="button"
            className="cf-account"
            aria-label={`Account, ${name}`}
            {...rest}
        >
            <span
                className="cf-avatar"
                style={{ width: 40, height: 40 }}
                aria-hidden="true"
            >
                {initials}
                <span
                    className="cf-avatar-dot"
                    style={{ background: 'var(--partner-1)' }}
                />
            </span>
            <span className="cf-stack">
                <span className="t-label-strong">{name}</span>
                <span className="t-caption t-muted">{role}</span>
            </span>
            <Icon icon={ChevronDown} size={16} />
        </button>
    );
}

/** The account pill at the right of the top bar, with a cf-menu dropdown. */
export function AccountMenu() {
    const { auth } = usePage().props;
    const { currentCompany } = useShell();
    const getInitials = useInitials();

    return (
        <DropdownMenu
            aria-label="Account"
            align="end"
            className="w-[260px]"
            trigger={
                <AccountPill
                    name={auth.user.name}
                    role={formatRole(currentCompany.role)}
                    initials={getInitials(auth.user.name)}
                />
            }
            entries={[
                { type: 'label', label: auth.user.email },
                {
                    label: 'Profile & security',
                    icon: UserRound,
                    href: editProfile().url,
                },
                {
                    label: 'Appearance',
                    icon: Palette,
                    href: editAppearance().url,
                },
                { type: 'separator' },
                {
                    label: 'Sign out',
                    icon: LogOut,
                    danger: true,
                    onSelect: signOut,
                },
            ]}
        />
    );
}

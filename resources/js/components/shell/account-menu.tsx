import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut, Palette, UserRound } from 'lucide-react';
import { useShell } from '@/components/shell/shell-context';
import { useInitials } from '@/hooks/use-initials';
import { formatRole } from '@/lib/format';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';

const ICON = { size: 16, strokeWidth: 1.5, 'aria-hidden': true } as const;

/** The account pill at the right of the top bar, with a cf-menu dropdown. */
export function AccountMenu() {
    const { auth } = usePage().props;
    const { currentCompany } = useShell();
    const getInitials = useInitials();
    const role = formatRole(currentCompany.role);

    return (
        <DropdownMenu.Root>
            <DropdownMenu.Trigger
                className="cf-account"
                aria-label={`Account, ${auth.user.name}`}
            >
                <span className="cf-avatar" style={{ width: 40, height: 40 }}>
                    {getInitials(auth.user.name)}
                    <span
                        className="cf-avatar-dot"
                        style={{ background: 'var(--partner-1)' }}
                        aria-hidden="true"
                    />
                </span>
                <span className="cf-stack">
                    <span className="t-label-strong">{auth.user.name}</span>
                    <span className="t-caption t-muted">{role}</span>
                </span>
                <ChevronDown {...ICON} />
            </DropdownMenu.Trigger>

            <DropdownMenu.Portal>
                <DropdownMenu.Content
                    align="end"
                    sideOffset={8}
                    className="cf-pop cf-menu z-40 w-[260px]"
                >
                    <DropdownMenu.Label className="cf-menu-label">
                        {auth.user.email}
                    </DropdownMenu.Label>
                    <DropdownMenu.Item asChild className="cf-menu-item">
                        <Link href={editProfile()} prefetch>
                            <UserRound {...ICON} />
                            Profile & security
                        </Link>
                    </DropdownMenu.Item>
                    <DropdownMenu.Item asChild className="cf-menu-item">
                        <Link href={editAppearance()} prefetch>
                            <Palette {...ICON} />
                            Appearance
                        </Link>
                    </DropdownMenu.Item>
                    <DropdownMenu.Separator className="cf-menu-sep" />
                    <DropdownMenu.Item
                        className="cf-menu-item cf-menu-item--danger"
                        onSelect={() => {
                            router.flushAll();
                            router.post(logout());
                        }}
                    >
                        <LogOut {...ICON} />
                        Sign out
                    </DropdownMenu.Item>
                </DropdownMenu.Content>
            </DropdownMenu.Portal>
        </DropdownMenu.Root>
    );
}

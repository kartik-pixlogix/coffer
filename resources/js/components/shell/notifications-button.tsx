import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Bell } from 'lucide-react';
import { placeholderUnreadNotifications } from '@/lib/placeholder-shell';

/**
 * Top-bar bell. Phase 0 stub: shows the unread badge and an empty panel.
 * Phase 8 replaces it with the NotificationPanel (filters, inline actions).
 */
export function NotificationsButton() {
    const unread = placeholderUnreadNotifications;
    const label =
        unread > 0 ? `Notifications, ${unread} unread` : 'Notifications';

    return (
        <DropdownMenu.Root>
            <DropdownMenu.Trigger
                className="cf-iconbtn cf-iconbtn--lg"
                aria-label={label}
            >
                <Bell size={22} strokeWidth={1.5} aria-hidden="true" />
                {unread > 0 && (
                    <span
                        className="cf-badge"
                        style={{
                            top: 7,
                            right: 7,
                            border: 0,
                        }}
                        aria-hidden="true"
                    >
                        {unread}
                    </span>
                )}
            </DropdownMenu.Trigger>

            <DropdownMenu.Portal>
                <DropdownMenu.Content
                    align="end"
                    sideOffset={8}
                    className="cf-pop cf-menu z-40 w-[320px]"
                >
                    <DropdownMenu.Label className="cf-menu-label">
                        Notifications
                    </DropdownMenu.Label>
                    <p className="t-body t-muted m-0 px-[10px] pt-1 pb-3">
                        Approvals, vault alerts and settlement reminders will
                        show up here.
                    </p>
                </DropdownMenu.Content>
            </DropdownMenu.Portal>
        </DropdownMenu.Root>
    );
}

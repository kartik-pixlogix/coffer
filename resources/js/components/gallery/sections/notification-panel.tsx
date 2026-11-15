import { Bell } from 'lucide-react';
import { useState } from 'react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Icon } from '@/components/ui/icon';
import {
    NotificationPanel,
    NotificationPopover,
} from '@/components/ui/notification-panel';
import type { NotificationItem } from '@/components/ui/notification-panel';

const ITEMS: NotificationItem[] = [
    {
        id: 1,
        kind: 'approval',
        title: 'Meta ads — October needs your approval',
        caption: (
            <>
                Meera Iyer · <span className="t-money-sm">₹25,000</span> · 2h
            </>
        ),
        href: '#',
        unread: true,
    },
    {
        id: 2,
        kind: 'security',
        title: 'Vercel team API token expires in 9 days',
        caption: 'Credentials Vault · Internal · 5h',
        href: '#',
        unread: true,
    },
    {
        id: 3,
        kind: 'money',
        title: (
            <>
                Settlement due: Vertex Labs owes Bluefin Infotech{' '}
                <span className="t-money-sm">₹3,24,600</span>
            </>
        ),
        caption: 'Saarthi Portal JV · yesterday',
        href: '#',
        unread: false,
    },
];

function LiveBell() {
    const [items, setItems] = useState(ITEMS);
    const unread = items.filter((item) => item.unread).length;

    return (
        <NotificationPopover
            items={items}
            onItemClick={(_, event) => event.preventDefault()}
            onMarkAllRead={() =>
                setItems((list) =>
                    list.map((item) => ({ ...item, unread: false })),
                )
            }
            trigger={
                <button
                    type="button"
                    className="cf-iconbtn cf-iconbtn--lg"
                    aria-label={
                        unread > 0
                            ? `Notifications, ${unread} unread`
                            : 'Notifications'
                    }
                >
                    <Icon icon={Bell} size={22} />
                    {unread > 0 && (
                        <span
                            className="cf-badge"
                            style={{ top: 7, right: 7, border: 0 }}
                            aria-hidden="true"
                        >
                            {unread}
                        </span>
                    )}
                </button>
            }
        />
    );
}

export default {
    name: 'NotificationPanel',
    group: 'Feedback and overlays',
    order: 8,
    subtitle:
        'Bell panel shown open · approvals, expiring credentials, settlement due',
    render: () => (
        <>
            <NotificationPanel
                items={ITEMS}
                onMarkAllRead={() => undefined}
                onItemClick={(_, event) => event.preventDefault()}
            />
            <NotificationPanel items={[]} />
            <PvRow>
                <LiveBell />
                <PvNote>
                    Live: the bell opens the panel; Mark all read clears the
                    badge
                </PvNote>
            </PvRow>
        </>
    ),
} satisfies GallerySection;

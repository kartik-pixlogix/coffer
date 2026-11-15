import {
    ArrowLeftRight,
    ChartColumn,
    ChartPie,
    Clock,
    CreditCard,
    LayoutGrid,
    Lock,
    Receipt,
    TrendingUp,
} from 'lucide-react';
import { PvGrid, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { toast } from '@/components/ui/toast';
import { NavRail, NavRailItem } from '@/components/ui/nav-rail';

const STATES = ['Default', 'Hover', 'Focus', 'Active', 'Badge', 'Disabled'];

export default {
    name: 'NavRail',
    group: 'Actions and navigation',
    order: 23,
    subtitle:
        'Rail items · default, hover, focus, active, with badge, disabled',
    render: () => (
        <>
            <PvGrid
                columns="repeat(6,minmax(0,1fr))"
                style={{ justifyItems: 'center', textAlign: 'center' }}
            >
                {STATES.map((s) => (
                    <PvState key={s}>{s}</PvState>
                ))}
                <NavRailItem
                    tooltip={false}
                    item={{ label: 'Timesheets', icon: Clock, href: '#' }}
                />
                <NavRailItem
                    tooltip={false}
                    className="is-hover"
                    item={{ label: 'Income', icon: TrendingUp, href: '#' }}
                />
                <NavRailItem
                    tooltip={false}
                    className="is-focus"
                    item={{ label: 'Reports', icon: ChartColumn, href: '#' }}
                />
                <NavRailItem
                    tooltip={false}
                    item={{
                        label: 'Expenses',
                        icon: Receipt,
                        href: '#',
                        current: true,
                    }}
                />
                <NavRailItem
                    tooltip={false}
                    item={{
                        label: 'Settlements',
                        icon: ArrowLeftRight,
                        href: '#',
                        badge: { count: 1, label: '1 due' },
                    }}
                />
                <NavRailItem
                    tooltip={false}
                    item={{
                        label: 'Billing',
                        icon: CreditCard,
                        href: null,
                        disabledReason: 'admins only',
                    }}
                />
            </PvGrid>
            <NavRail
                aria-label="Main (excerpt)"
                direction="row"
                signOutSeparator={false}
                homeHref="#"
                onSignOut={() => toast.info('Sign out')}
                style={{
                    flex: 'none',
                    width: 'auto',
                    flexWrap: 'nowrap',
                    alignSelf: 'flex-start',
                }}
                groups={[
                    [
                        {
                            label: 'Dashboard',
                            icon: LayoutGrid,
                            href: '#',
                            current: true,
                        },
                        { label: 'Partners', icon: ChartPie, href: '#' },
                        { label: 'Credentials Vault', icon: Lock, href: '#' },
                    ],
                ]}
            />
        </>
    ),
} satisfies GallerySection;

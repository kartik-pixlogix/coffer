import {
    ArrowLeftRight,
    LayoutGrid,
    Moon,
    Plus,
    Search,
    SlidersHorizontal,
    TrendingUp,
} from 'lucide-react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import {
    CommandPalette,
    CommandPalettePanel,
    useCommandPalette,
} from '@/components/ui/command-palette';
import type { CommandGroup } from '@/components/ui/command-palette';
import { Icon } from '@/components/ui/icon';
import { toast } from '@/components/ui/toast';

const say = (text: string) => () => toast.info(text);

const GROUPS: CommandGroup[] = [
    {
        label: 'Pages',
        items: [
            {
                id: 'dashboard',
                label: 'Dashboard',
                icon: LayoutGrid,
                onSelect: say('Dashboard'),
            },
            {
                id: 'income',
                label: 'Income',
                icon: TrendingUp,
                onSelect: say('Income'),
            },
            {
                id: 'settlements',
                label: 'Settlements',
                icon: ArrowLeftRight,
                onSelect: say('Settlements'),
            },
            {
                id: 'settings',
                label: 'Settings · Company',
                icon: SlidersHorizontal,
                onSelect: say('Settings · Company'),
            },
        ],
    },
    {
        label: 'Actions',
        items: [
            {
                id: 'transfer',
                label: 'Record transfer',
                hint: 'Vertex Labs owes ₹3,24,600',
                keywords: ['settlement'],
                icon: Plus,
                onSelect: say('Record transfer'),
            },
            {
                id: 'theme',
                label: 'Switch theme',
                keywords: ['dark', 'light'],
                icon: Moon,
                onSelect: say('Switch theme'),
            },
        ],
    },
    {
        label: 'Vault',
        items: [
            {
                id: 'gw',
                label: 'Google Workspace — super admin',
                hint: 'settings',
                monogram: 'GW',
                onSelect: say('Google Workspace — super admin'),
            },
        ],
    },
];

/** The gallery's own palette; the app shell mounts the real ⌘K one. */
function LivePalette() {
    const { open, setOpen } = useCommandPalette();

    return (
        <>
            <button
                type="button"
                className="cf-search"
                style={{ maxWidth: 320 }}
                onClick={() => setOpen(true)}
            >
                <Icon icon={Search} size={18} />
                <span className="t-label flex-1 text-left">
                    Search or jump to…
                </span>
                <span className="cf-kbd" aria-hidden="true">
                    ⌘K
                </span>
            </button>
            <CommandPalette
                open={open}
                onOpenChange={setOpen}
                groups={GROUPS}
            />
        </>
    );
}

export default {
    name: 'CommandPalette',
    group: 'Actions and navigation',
    order: 21,
    subtitle: '⌘K · jump to pages, records and actions',
    render: (theme) => (
        <>
            <CommandPalettePanel groups={GROUPS} defaultQuery="sett" />
            {theme === 'light' && (
                <PvRow>
                    <LivePalette />
                    <PvNote>Live: click, or press ⌘K / Ctrl K</PvNote>
                </PvRow>
            )}
        </>
    ),
} satisfies GallerySection;

import {
    ChevronDown,
    Copy,
    Download,
    Ellipsis,
    FileText,
    LayoutGrid,
    Mail,
    Pencil,
    Trash2,
} from 'lucide-react';
import type {
    ButtonHTMLAttributes,
    CSSProperties,
    ReactNode,
    Ref,
} from 'react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { buttonClass } from '@/components/ui/button';
import { DropdownMenu, MenuPanel } from '@/components/ui/dropdown-menu';
import type { MenuEntry } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { toast } from '@/components/ui/toast';

const COLUMN: CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
    alignItems: 'flex-start',
};

const exportEntries = (live: boolean): MenuEntry[] => [
    { type: 'label', label: 'FY 2026-27 P&L' },
    {
        label: 'PDF report',
        icon: FileText,
        onSelect: live ? () => toast.info('Exporting P&L as PDF') : undefined,
    },
    {
        label: 'Excel workbook',
        icon: LayoutGrid,
        shortcut: '⌘E',
        onSelect: live ? () => toast.info('Exporting P&L as Excel') : undefined,
    },
    {
        label: 'Email to partners (Growth plan)',
        icon: Mail,
        disabled: true,
    },
];

const rowEntries = (live: boolean): MenuEntry[] => [
    { label: 'Edit', icon: Pencil },
    { label: 'Duplicate', icon: Copy },
    { type: 'separator' },
    {
        label: 'Delete',
        icon: Trash2,
        danger: true,
        onSelect: live
            ? () =>
                  toast.success('Expense deleted', {
                      icon: Trash2,
                      action: { label: 'Undo', onClick: () => undefined },
                  })
            : undefined,
    },
];

/* Triggers spread the props Radix passes through `asChild` (handlers, aria, ref). */
type TriggerProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    ref?: Ref<HTMLButtonElement>;
};

function ExportTrigger(props: TriggerProps): ReactNode {
    return (
        <button
            type="button"
            className={buttonClass({ variant: 'secondary' })}
            {...props}
        >
            <Icon icon={Download} size={16} />
            Export
            <Icon icon={ChevronDown} size={14} />
        </button>
    );
}

function MoreTrigger(props: TriggerProps): ReactNode {
    return (
        <button type="button" className="cf-iconbtn cf-iconbtn--sm" {...props}>
            <Icon icon={Ellipsis} size={15} strokeWidth={2.5} />
        </button>
    );
}

export default {
    name: 'DropdownMenu',
    group: 'Actions and navigation',
    order: 20,
    subtitle:
        'Row actions and the Export menu · hover, shortcut, disabled, destructive',
    render: () => (
        <>
            <PvRow style={{ alignItems: 'flex-start' }}>
                <div style={COLUMN}>
                    <ExportTrigger aria-haspopup="menu" aria-expanded />
                    <MenuPanel
                        aria-label="Export"
                        entries={exportEntries(false)}
                        activeIndex={1}
                    />
                </div>
                <div style={COLUMN}>
                    <MoreTrigger
                        aria-haspopup="menu"
                        aria-expanded
                        aria-label="More actions for Meta ads"
                    />
                    <MenuPanel
                        aria-label="Row actions"
                        entries={rowEntries(false)}
                    />
                </div>
            </PvRow>
            <PvRow>
                <DropdownMenu
                    aria-label="Export"
                    entries={exportEntries(true)}
                    trigger={<ExportTrigger />}
                />
                <DropdownMenu
                    aria-label="Row actions"
                    align="end"
                    entries={rowEntries(true)}
                    trigger={
                        <MoreTrigger aria-label="More actions for Meta ads" />
                    }
                />
                <PvNote>
                    Live: arrows move, Enter runs, Esc returns focus
                </PvNote>
            </PvRow>
        </>
    ),
} satisfies GallerySection;

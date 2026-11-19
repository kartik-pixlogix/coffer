import { Link } from '@inertiajs/react';
import * as MenuPrimitive from '@radix-ui/react-dropdown-menu';
import type { LucideIcon } from 'lucide-react';
import type { CSSProperties, ReactElement, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type MenuItemEntry = {
    type?: 'item';
    /** Stable key; defaults to the label. */
    key?: string;
    label: string;
    icon?: LucideIcon;
    /** Shown in a `cf-kbd` at the end, e.g. "⌘E". Display only. */
    shortcut?: string;
    /** Inertia link instead of a command. */
    href?: string;
    onSelect?: () => void;
    /** Disabled items say why in their label: "Email to partners (Growth plan)". */
    disabled?: boolean;
    /** Destructive: goes last, below a separator, in `negative`. */
    danger?: boolean;
};

export type MenuEntry =
    | MenuItemEntry
    | { type: 'label'; key?: string; label: ReactNode }
    | { type: 'separator'; key?: string };

const ITEM_ICON = { size: 16 } as const;

const entryKey = (entry: MenuEntry, index: number): string =>
    entry.key ??
    (entry.type === 'separator'
        ? `sep-${index}`
        : typeof entry.label === 'string'
          ? entry.label
          : `entry-${index}`);

function ItemContent({ entry }: { entry: MenuItemEntry }) {
    return (
        <>
            {entry.icon && <Icon icon={entry.icon} {...ITEM_ICON} />}
            {entry.label}
            {entry.shortcut && <span className="cf-kbd">{entry.shortcut}</span>}
        </>
    );
}

const itemClass = (entry: MenuItemEntry, className?: string) =>
    cn('cf-menu-item', entry.danger && 'cf-menu-item--danger', className);

export type MenuPanelProps = {
    entries: MenuEntry[];
    'aria-label': string;
    /** Index of an item drawn highlighted (documentation only, like `.is-active`). */
    activeIndex?: number;
    className?: string;
    style?: CSSProperties;
};

/** The static open menu (`cf-pop cf-menu`), for the gallery and docs. */
export function MenuPanel({
    entries,
    activeIndex,
    className,
    style,
    ...rest
}: MenuPanelProps) {
    return (
        <div
            className={cn('cf-pop cf-menu', className)}
            role="menu"
            aria-label={rest['aria-label']}
            style={style}
        >
            {entries.map((entry, index) => {
                const key = entryKey(entry, index);

                if (entry.type === 'separator') {
                    return (
                        <div
                            key={key}
                            className="cf-menu-sep"
                            role="separator"
                        />
                    );
                }

                if (entry.type === 'label') {
                    return (
                        <div key={key} className="cf-menu-label">
                            {entry.label}
                        </div>
                    );
                }

                const cls = itemClass(
                    entry,
                    index === activeIndex ? 'is-active' : undefined,
                );

                return entry.href && !entry.disabled ? (
                    <Link
                        key={key}
                        href={entry.href}
                        className={cls}
                        role="menuitem"
                    >
                        <ItemContent entry={entry} />
                    </Link>
                ) : (
                    <button
                        key={key}
                        type="button"
                        className={cls}
                        role="menuitem"
                        disabled={entry.disabled}
                        onClick={entry.onSelect}
                    >
                        <ItemContent entry={entry} />
                    </button>
                );
            })}
        </div>
    );
}

/*
 * Radix items are divs that take focus as they are highlighted, so the cf
 * `:hover`/`.is-active` and `:disabled` looks are mirrored on its data attributes.
 */
const LIVE_ITEM =
    'cursor-pointer select-none data-[highlighted]:bg-surface-muted data-[disabled]:cursor-not-allowed data-[disabled]:bg-transparent data-[disabled]:text-ink-faint';

export type DropdownMenuProps = {
    /** The button that opens the menu (gets `aria-haspopup="menu"` and `aria-expanded`). */
    trigger: ReactElement;
    entries: MenuEntry[];
    /** Names the menu, e.g. "Export" or "Row actions". */
    'aria-label': string;
    align?: 'start' | 'center' | 'end';
    side?: 'top' | 'right' | 'bottom' | 'left';
    sideOffset?: number;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    /** Width etc. of the panel, e.g. `w-[260px]`. */
    className?: string;
};

/**
 * Commands behind one button (Export, a row's ⋯ actions, the account menu). Arrow
 * keys move, Enter runs, Esc closes and returns focus to the trigger.
 */
export function DropdownMenu({
    trigger,
    entries,
    align = 'start',
    side = 'bottom',
    sideOffset = 8,
    open,
    onOpenChange,
    className,
    ...rest
}: DropdownMenuProps) {
    return (
        <MenuPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <MenuPrimitive.Trigger asChild>{trigger}</MenuPrimitive.Trigger>
            <MenuPrimitive.Portal>
                <MenuPrimitive.Content
                    align={align}
                    side={side}
                    sideOffset={sideOffset}
                    collisionPadding={16}
                    aria-label={rest['aria-label']}
                    className={cn(
                        'cf-pop cf-menu z-50 max-w-[calc(100vw-32px)] animate-[cfFade_var(--duration-panel)_var(--ease-out)] focus-visible:shadow-pop',
                        className,
                    )}
                >
                    {entries.map((entry, index) => {
                        const key = entryKey(entry, index);

                        if (entry.type === 'separator') {
                            return (
                                <MenuPrimitive.Separator
                                    key={key}
                                    className="cf-menu-sep"
                                />
                            );
                        }

                        if (entry.type === 'label') {
                            return (
                                <MenuPrimitive.Label
                                    key={key}
                                    className="cf-menu-label"
                                >
                                    {entry.label}
                                </MenuPrimitive.Label>
                            );
                        }

                        const cls = itemClass(entry, LIVE_ITEM);

                        if (entry.href && !entry.disabled) {
                            return (
                                <MenuPrimitive.Item
                                    key={key}
                                    asChild
                                    className={cls}
                                    onSelect={entry.onSelect}
                                >
                                    <Link href={entry.href} prefetch>
                                        <ItemContent entry={entry} />
                                    </Link>
                                </MenuPrimitive.Item>
                            );
                        }

                        return (
                            <MenuPrimitive.Item
                                key={key}
                                className={cls}
                                disabled={entry.disabled}
                                onSelect={entry.onSelect}
                            >
                                <ItemContent entry={entry} />
                            </MenuPrimitive.Item>
                        );
                    })}
                </MenuPrimitive.Content>
            </MenuPrimitive.Portal>
        </MenuPrimitive.Root>
    );
}

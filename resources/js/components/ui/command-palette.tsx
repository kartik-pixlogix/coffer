import { router } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Search } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Fragment,
    useCallback,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { CSSProperties, KeyboardEvent } from 'react';
import {
    highlightParts,
    searchCommands,
} from '@/components/ui/command-palette-search';
import type { SearchableItem } from '@/components/ui/command-palette-search';
import { Icon } from '@/components/ui/icon';
import { SCRIM_CLASS } from '@/components/ui/modal';
import { cn } from '@/lib/utils';

export type CommandItem = SearchableItem & {
    icon?: LucideIcon;
    /** Monogram tile instead of an icon (vault items, people): "GW". */
    monogram?: string;
    /** Navigate with Inertia… */
    href?: string;
    /** …or run a command. */
    onSelect?: () => void;
};

export type CommandGroup = {
    /** "Pages", "Actions", "People" or "Vault". Vault items carry names only, never secrets. */
    label: string;
    items: CommandItem[];
};

/** Highlight style from the preview (lime mark, `on-lime` text). */
const MARK_STYLE: CSSProperties = {
    background: 'var(--lime)',
    color: 'var(--on-lime)',
    borderRadius: 3,
};

function Label({
    item,
    ranges,
}: {
    item: CommandItem;
    ranges: [number, number][];
}) {
    return (
        <span className="min-w-0 truncate">
            {highlightParts(item.label, ranges).map((part, index) =>
                part.match ? (
                    <mark key={index} style={MARK_STYLE}>
                        {part.text}
                    </mark>
                ) : (
                    part.text
                ),
            )}
            {item.hint && (
                <>
                    {' '}
                    <span className="t-muted">· {item.hint}</span>
                </>
            )}
        </span>
    );
}

type CommandPaletteBodyProps = {
    groups: CommandGroup[];
    /** Start with this query (gallery states). */
    defaultQuery?: string;
    /** Runs after an item is chosen (closes the dialog). */
    onDone?: () => void;
    autoFocus?: boolean;
};

/** Input, grouped listbox and key hints; shared by the dialog and the static panel. */
function CommandPaletteBody({
    groups,
    defaultQuery = '',
    onDone,
    autoFocus = false,
}: CommandPaletteBodyProps) {
    const [query, setQuery] = useState(defaultQuery);
    const [active, setActive] = useState(0);
    const listRef = useRef<HTMLDivElement>(null);
    const listId = useId();
    const optionId = useId();

    const results = useMemo(
        () => searchCommands(groups, query),
        [groups, query],
    );
    const flat = useMemo(
        () => results.flatMap((group) => group.items),
        [results],
    );
    const activeIndex =
        flat.length === 0 ? -1 : Math.min(active, flat.length - 1);

    useEffect(() => {
        listRef.current
            ?.querySelector('[aria-selected="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex]);

    const choose = (item: CommandItem) => {
        onDone?.();

        if (item.onSelect) {
            item.onSelect();
        } else if (item.href) {
            router.visit(item.href);
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (flat.length === 0) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                setActive((activeIndex + 1) % flat.length);
                break;
            case 'ArrowUp':
                event.preventDefault();
                setActive((activeIndex - 1 + flat.length) % flat.length);
                break;
            case 'Home':
                if (event.ctrlKey || event.metaKey) {
                    event.preventDefault();
                    setActive(0);
                }

                break;
            case 'End':
                if (event.ctrlKey || event.metaKey) {
                    event.preventDefault();
                    setActive(flat.length - 1);
                }

                break;
            case 'Enter':
                event.preventDefault();
                choose(flat[activeIndex].item);
                break;
        }
    };

    let index = -1;

    return (
        <>
            <div className="cf-cmdk-input">
                <Icon icon={Search} size={18} />
                <input
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        activeIndex >= 0
                            ? `${optionId}-${activeIndex}`
                            : undefined
                    }
                    aria-label="Search or jump to"
                    placeholder="Search or jump to…"
                    autoComplete="off"
                    spellCheck={false}
                    autoFocus={autoFocus}
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActive(0);
                    }}
                    onKeyDown={onKeyDown}
                />
                <span className="cf-kbd">Esc</span>
            </div>
            <div
                ref={listRef}
                id={listId}
                className="cf-menu max-h-[min(420px,60dvh)] overflow-y-auto px-2 pt-1.5 pb-2"
                role="listbox"
                aria-label="Results"
            >
                {results.length === 0 ? (
                    <p
                        className="t-body t-muted m-0 px-2.5 py-3"
                        role="presentation"
                    >
                        No results for “{query.trim()}”.
                    </p>
                ) : (
                    results.map((group) => (
                        <Fragment key={group.label}>
                            <div className="cf-menu-label">{group.label}</div>
                            {group.items.map(({ item, ranges }) => {
                                index += 1;
                                const position = index;
                                const selected = position === activeIndex;

                                return (
                                    <button
                                        key={item.id}
                                        id={`${optionId}-${position}`}
                                        type="button"
                                        tabIndex={-1}
                                        className={cn(
                                            'cf-menu-item',
                                            selected && 'is-active',
                                        )}
                                        role="option"
                                        aria-selected={selected}
                                        onMouseMove={() => setActive(position)}
                                        onClick={() => choose(item)}
                                    >
                                        {item.monogram ? (
                                            <span
                                                className="cf-avatar cf-avatar--sm cf-avatar--tile cf-avatar--graphite"
                                                aria-hidden="true"
                                            >
                                                {item.monogram}
                                            </span>
                                        ) : (
                                            item.icon && (
                                                <Icon
                                                    icon={item.icon}
                                                    size={16}
                                                />
                                            )
                                        )}
                                        <Label item={item} ranges={ranges} />
                                        {selected && (
                                            <span
                                                className="cf-kbd"
                                                aria-hidden="true"
                                            >
                                                ↵
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </Fragment>
                    ))
                )}
            </div>
            <div className="cf-cmdk-foot" aria-hidden="true">
                <span>
                    <span className="cf-kbd">↑</span>{' '}
                    <span className="cf-kbd">↓</span> move
                </span>
                <span>
                    <span className="cf-kbd">↵</span> open
                </span>
                <span>
                    <span className="cf-kbd">Esc</span> close
                </span>
            </div>
        </>
    );
}

export type CommandPalettePanelProps = {
    groups: CommandGroup[];
    defaultQuery?: string;
    className?: string;
};

/** The static open palette (no scrim, no portal), for the gallery and docs. It still filters. */
export function CommandPalettePanel({
    groups,
    defaultQuery,
    className,
}: CommandPalettePanelProps) {
    return (
        <div
            className={cn('cf-pop cf-cmdk', className)}
            role="dialog"
            aria-label="Command palette"
        >
            <CommandPaletteBody groups={groups} defaultQuery={defaultQuery} />
        </div>
    );
}

export type CommandPaletteProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    groups: CommandGroup[];
};

/**
 * ⌘K / Ctrl K: jump to any page, record or action by typing. Results are grouped
 * (Pages, Actions, People, Vault) and the first one is active; ↑ ↓ move, ↵ opens,
 * Esc closes and focus returns to where it was. Pair with `useCommandPalette()`.
 */
export function CommandPalette({
    open,
    onOpenChange,
    groups,
}: CommandPaletteProps) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className={SCRIM_CLASS} />
                <DialogPrimitive.Content
                    className="cf-pop cf-cmdk fixed top-[12dvh] left-1/2 z-[41] max-w-[calc(100vw-32px)] [transform:translateX(-50%)] animate-[cfPop_var(--duration-panel)_var(--ease-out)_both] focus-visible:shadow-pop max-sm:top-4"
                    aria-describedby={undefined}
                >
                    <DialogPrimitive.Title className="cf-sr">
                        Command palette
                    </DialogPrimitive.Title>
                    {open && (
                        <CommandPaletteBody
                            groups={groups}
                            onDone={() => onOpenChange(false)}
                            autoFocus
                        />
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

/** True when the platform's command key is ⌘ (Apple devices). */
export function isApplePlatform(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad|iPod/.test(navigator.userAgent)
    );
}

/**
 * Open state for the command palette plus the global ⌘K / Ctrl K shortcut
 * (toggles it; works from inside inputs too).
 */
export function useCommandPalette(): {
    open: boolean;
    setOpen: (open: boolean) => void;
    toggle: () => void;
} {
    const [open, setOpen] = useState(false);
    const toggle = useCallback(() => setOpen((value) => !value), []);

    useEffect(() => {
        const onKeyDown = (event: globalThis.KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                !event.altKey &&
                !event.shiftKey &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                toggle();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [toggle]);

    return { open, setOpen, toggle };
}

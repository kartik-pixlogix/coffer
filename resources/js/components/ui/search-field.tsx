import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent, InputHTMLAttributes, Ref } from 'react';
import { mergeRefs } from '@/components/ui/field';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { cn } from '@/lib/utils';

function detectMac(): boolean {
    const nav = navigator as Navigator & {
        userAgentData?: { platform?: string };
    };
    const platform = nav.userAgentData?.platform ?? nav.platform ?? '';

    return /mac|iphone|ipad|ipod/i.test(platform);
}

/**
 * "⌘K" on Apple devices, "Ctrl K" elsewhere. The server and the first client
 * render both say "⌘K"; the platform's label swaps in after mount, so SSR
 * hydration never sees a mismatch (D91).
 */
export function useShortcutLabel(): string {
    const [mac, setMac] = useState(true);

    useEffect(() => setMac(detectMac()), []);

    return mac ? '⌘K' : 'Ctrl K';
}

export type SearchFieldProps = {
    /**
     * `topbar`: 54 px global search with the ⌘K hint (`cf-search`).
     * `inline`: 40 px pill that filters the list on the current page.
     */
    variant?: 'topbar' | 'inline';
    /** Names what is searched: "Search expenses". Required, there is no visible label. */
    'aria-label': string;
    value?: string;
    defaultValue?: string;
    onValueChange?: (query: string) => void;
    /** Topbar only: focus this field on ⌘K / Ctrl K (when no command palette owns it). */
    hotkey?: boolean;
    /** Topbar only: show the ⌘K hint while empty (default true). */
    showShortcut?: boolean;
    /** Class for the wrapping label (e.g. a max width). */
    wrapClassName?: string;
    ref?: Ref<HTMLInputElement>;
} & Omit<
    InputHTMLAttributes<HTMLInputElement>,
    'type' | 'value' | 'defaultValue' | 'aria-label'
>;

/** Global top-bar search and the in-page filter search, with a clear button once there's a query. */
export function SearchField({
    variant = 'inline',
    value,
    defaultValue = '',
    onValueChange,
    hotkey = false,
    showShortcut = true,
    wrapClassName,
    className,
    disabled,
    onChange,
    ref,
    ...rest
}: SearchFieldProps) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [uncontrolled, setUncontrolled] = useState(defaultValue);
    const query = value ?? uncontrolled;
    const shortcut = useShortcutLabel();
    const topbar = variant === 'topbar';

    useEffect(() => {
        if (!topbar || !hotkey) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                inputRef.current?.focus();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [topbar, hotkey]);

    const handleChange = (event: ChangeEvent<HTMLInputElement>) => {
        setUncontrolled(event.currentTarget.value);
        onChange?.(event);
        onValueChange?.(event.currentTarget.value);
    };

    const clear = () => {
        setUncontrolled('');
        onValueChange?.('');
        inputRef.current?.focus();
    };

    const input = (
        <input
            ref={mergeRefs(ref, inputRef)}
            type="search"
            className={cn(
                '[&::-webkit-search-cancel-button]:appearance-none',
                className,
            )}
            value={query}
            disabled={disabled}
            onChange={handleChange}
            {...rest}
        />
    );

    const clearButton = query !== '' && !disabled && (
        <IconButton
            icon={X}
            size="sm"
            variant="bare"
            iconSize={14}
            aria-label="Clear search"
            onClick={clear}
        />
    );

    if (topbar) {
        return (
            <label className={cn('cf-search', wrapClassName)}>
                <Icon icon={Search} size={18} />
                {input}
                {clearButton ||
                    (showShortcut && (
                        <span className="cf-kbd" aria-hidden="true">
                            {shortcut}
                        </span>
                    ))}
            </label>
        );
    }

    return (
        <label
            className={cn(
                'cf-inwrap cf-inwrap--sm',
                disabled && 'is-disabled',
                wrapClassName,
            )}
            style={{ borderRadius: 'var(--radius-full)' }}
        >
            <Icon icon={Search} size={16} />
            {input}
            {clearButton}
        </label>
    );
}

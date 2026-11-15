import * as Popover from '@radix-ui/react-popover';
import { Check, ChevronDown, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import {
    filterOptions,
    highlightMatch,
    nextEnabledIndex,
} from '@/components/ui/combobox.utils';
import type {
    FilterableOption,
    FilteredOption,
    HighlightSegment,
} from '@/components/ui/combobox.utils';
import { Field, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { Icon } from '@/components/ui/icon';
import { InputSpinner } from '@/components/ui/text-input';
import { cn } from '@/lib/utils';

export type ComboboxOption = FilterableOption & {
    value: string;
    disabled?: boolean;
    /** Before the text, e.g. a `cf-avatar cf-avatar--sm`. */
    leading?: ReactNode;
};

export type ComboboxProps = FieldMessageProps & {
    label?: ReactNode;
    /** When there is no visible label. */
    'aria-label'?: string;
    options: ComboboxOption[];
    value?: string | null;
    defaultValue?: string | null;
    onValueChange?: (
        value: string | null,
        option: ComboboxOption | null,
    ) => void;
    /** Accessible name of the list: "Partners". */
    listLabel: string;
    /**
     * `input`: always a text input (type to filter).
     * `select`: closed it is a `cf-selectbox` button showing the value; opening turns it
     * into the search input.
     */
    variant?: 'input' | 'select';
    placeholder?: string;
    /** Search icon at the start of the input (default true). */
    searchIcon?: boolean;
    /** Results are loading (async search): spinner in the field. */
    loading?: boolean;
    /** Every edit of the query (for async search). */
    onQueryChange?: (query: string) => void;
    /** Filter `options` here (default). Set false when the parent filters (async). */
    filter?: boolean;
    /** Used in the no-match text: "No project called “Saarthi mobile”." */
    noun?: string;
    noMatchText?: (query: string) => ReactNode;
    /** Offer to create the typed thing when nothing matches. */
    onCreate?: (query: string) => void;
    createLabel?: string;
    disabled?: boolean;
    /** Hidden input name carrying the value, for plain form posts. */
    name?: string;
    id?: string;
    /** Class for the `cf-inwrap` (doc states: is-focus). */
    wrapClassName?: string;
    fieldClassName?: string;
    /** Start open, optionally with a query typed (demos, prefilled searches). */
    defaultOpen?: boolean;
    defaultQuery?: string;
    /** Text for the chosen option when closed (default "Kabir Shah — Bluefin Infotech"). */
    displayValue?: (option: ComboboxOption) => string;
};

const defaultDisplayValue = (option: ComboboxOption): string =>
    option.meta ? `${option.label} — ${option.meta}` : option.label;

function Segments({ segments }: { segments: HighlightSegment[] }) {
    return segments.map((segment, index) =>
        segment.match ? <mark key={index}>{segment.text}</mark> : segment.text,
    );
}

/**
 * Searchable select for long or growing lists (members, partners, projects): type to
 * filter, arrows to move, Enter to pick, Escape to close. Matched text is marked.
 */
export function Combobox({
    label,
    'aria-label': ariaLabel,
    options,
    value,
    defaultValue = null,
    onValueChange,
    listLabel,
    variant = 'input',
    placeholder,
    searchIcon = true,
    loading = false,
    onQueryChange,
    filter = true,
    noun = 'item',
    noMatchText,
    onCreate,
    createLabel = 'Create it',
    disabled = false,
    name,
    help,
    error,
    id: idProp,
    wrapClassName,
    fieldClassName,
    defaultOpen = false,
    defaultQuery,
    displayValue = defaultDisplayValue,
}: ComboboxProps) {
    const [uncontrolled, setUncontrolled] = useState(defaultValue);
    const selectedValue = value !== undefined ? value : uncontrolled;
    const selected =
        options.find((option) => option.value === selectedValue) ?? null;
    const selectedText = selected ? displayValue(selected) : '';

    const [open, setOpen] = useState(defaultOpen);
    // null = nothing typed since opening (show every option, keep the value's label).
    const [text, setText] = useState<string | null>(defaultQuery ?? null);
    const [active, setActive] = useState(() =>
        defaultOpen
            ? Math.max(
                  0,
                  filterOptions(options, defaultQuery ?? '').findIndex(
                      (result) => result.option.value === selectedValue,
                  ),
              )
            : -1,
    );
    const [focusTarget, setFocusTarget] = useState<{
        to: 'input' | 'button';
    } | null>(null);
    const anchorRef = useRef<HTMLSpanElement | null>(null);
    const inputRef = useRef<HTMLInputElement | null>(null);
    const buttonRef = useRef<HTMLButtonElement | null>(null);

    const query = text ?? '';
    const results: FilteredOption<ComboboxOption>[] = filter
        ? filterOptions(options, query)
        : options.map((option, index) => ({
              option,
              index,
              label: highlightMatch(option.label, query),
              meta:
                  option.meta === undefined
                      ? null
                      : highlightMatch(option.meta, query),
          }));
    const noMatch =
        !loading &&
        text !== null &&
        query.trim() !== '' &&
        results.length === 0;

    const noMatchError = noMatch ? (
        <>
            {noMatchText
                ? noMatchText(query.trim())
                : `No ${noun} called “${query.trim()}”.`}
            {onCreate && (
                <>
                    {' '}
                    <button
                        type="button"
                        className="cf-link"
                        style={{ height: 'auto', color: 'inherit' }}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => {
                            onCreate(query.trim());
                            close();
                        }}
                    >
                        {createLabel}
                    </button>
                </>
            )}
        </>
    ) : undefined;

    const ids = useFieldIds(idProp, { help, error: error ?? noMatchError });
    const listboxId = `${ids.id}-listbox`;
    const optionId = (index: number) => `${ids.id}-option-${index}`;
    const expanded = open && results.length > 0;
    const activeResult = expanded ? results[active] : undefined;

    useEffect(() => {
        if (focusTarget?.to === 'input') {
            inputRef.current?.focus();
        } else if (focusTarget?.to === 'button') {
            buttonRef.current?.focus();
        }
    }, [focusTarget]);

    useEffect(() => {
        if (activeResult) {
            document
                .getElementById(optionId(activeResult.index))
                ?.scrollIntoView({ block: 'nearest' });
        }
    });

    const firstActive = (list: FilteredOption<ComboboxOption>[]) => {
        const current = list.findIndex(
            (result) => result.option.value === selectedValue,
        );

        return current !== -1
            ? current
            : nextEnabledIndex(
                  list.map((result) => Boolean(result.option.disabled)),
                  -1,
                  1,
              );
    };

    function close(refocus = false) {
        setOpen(false);
        setText(null);
        setActive(-1);

        if (refocus && variant === 'select') {
            setFocusTarget({ to: 'button' });
        }
    }

    const openList = () => {
        if (disabled) {
            return;
        }

        setOpen(true);
        setActive(firstActive(results));

        if (variant === 'select') {
            setFocusTarget({ to: 'input' });
        }
    };

    const pick = (option: ComboboxOption) => {
        if (option.disabled) {
            return;
        }

        setUncontrolled(option.value);
        onValueChange?.(option.value, option);
        close(true);
    };

    const move = (direction: 1 | -1) => {
        if (!open) {
            openList();

            return;
        }

        setActive(
            nextEnabledIndex(
                results.map((result) => Boolean(result.option.disabled)),
                active,
                direction,
            ),
        );
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                move(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                move(-1);
                break;
            case 'Enter':
                if (activeResult) {
                    event.preventDefault();
                    pick(activeResult.option);
                }

                break;
            case 'Escape':
                if (open || variant === 'select') {
                    event.preventDefault();
                    event.stopPropagation();
                    close(true);
                }

                break;
            case 'Tab':
                close();
                break;
        }
    };

    const showInput = variant === 'input' || open;

    const field = showInput ? (
        <span
            ref={anchorRef}
            className={cn(
                'cf-inwrap',
                ids.invalid && 'is-error',
                disabled && 'is-disabled',
                wrapClassName,
            )}
        >
            {searchIcon && <Icon icon={Search} size={16} />}
            <input
                ref={inputRef}
                id={ids.id}
                type="text"
                role="combobox"
                autoComplete="off"
                aria-label={ariaLabel}
                aria-autocomplete="list"
                aria-expanded={expanded}
                aria-controls={listboxId}
                aria-activedescendant={
                    activeResult ? optionId(activeResult.index) : undefined
                }
                aria-invalid={ids.invalid || undefined}
                aria-describedby={ids.describedBy}
                aria-busy={loading || undefined}
                placeholder={placeholder}
                disabled={disabled}
                value={
                    text ?? (open && variant === 'select' ? '' : selectedText)
                }
                onChange={(event) => {
                    const next = event.currentTarget.value;
                    setText(next);
                    setOpen(true);
                    onQueryChange?.(next);
                    const list = filter
                        ? filterOptions(options, next)
                        : results;
                    setActive(
                        nextEnabledIndex(
                            list.map((result) =>
                                Boolean(result.option.disabled),
                            ),
                            -1,
                            1,
                        ),
                    );
                }}
                onClick={() => !open && openList()}
                onKeyDown={handleKeyDown}
                onBlur={() => {
                    // An emptied input clears the value.
                    if (
                        text === '' &&
                        variant === 'input' &&
                        selectedValue !== null
                    ) {
                        setUncontrolled(null);
                        onValueChange?.(null, null);
                    }

                    close();
                }}
            />
            {loading && <InputSpinner />}
        </span>
    ) : (
        <span ref={anchorRef} className="flex">
            <button
                ref={buttonRef}
                id={ids.id}
                type="button"
                className={cn('cf-selectbox', ids.invalid && 'is-error')}
                aria-haspopup="listbox"
                aria-expanded={false}
                aria-label={ariaLabel}
                aria-invalid={ids.invalid || undefined}
                aria-describedby={ids.describedBy}
                disabled={disabled}
                onClick={openList}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        openList();
                    }
                }}
            >
                <span className={selected ? undefined : 't-faint'}>
                    {selected ? selectedText : placeholder}
                </span>
                <Icon icon={ChevronDown} size={16} />
            </button>
        </span>
    );

    return (
        <Field
            ids={ids}
            label={label}
            help={help}
            error={error ?? noMatchError}
            className={fieldClassName}
        >
            <Popover.Root
                open={expanded}
                onOpenChange={(next) => !next && close()}
            >
                <Popover.Anchor asChild>{field}</Popover.Anchor>
                <Popover.Content
                    role={undefined}
                    className="cf-pop z-40 animate-[cfFade_var(--duration-panel)_var(--ease-out)]"
                    collisionPadding={16}
                    side="bottom"
                    align="start"
                    sideOffset={6}
                    style={{
                        width: 'var(--radix-popover-trigger-width)',
                        maxHeight:
                            'min(320px, var(--radix-popover-content-available-height))',
                        overflowY: 'auto',
                    }}
                    // Focus stays in the input; clicks in the list mustn't blur it.
                    onMouseDown={(event) => event.preventDefault()}
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    onCloseAutoFocus={(event) => event.preventDefault()}
                    // Focus never enters the list; the input's blur closes it.
                    onFocusOutside={(event) => event.preventDefault()}
                    onInteractOutside={(event) => {
                        if (
                            event.target instanceof Node &&
                            anchorRef.current?.contains(event.target)
                        ) {
                            event.preventDefault();
                        }
                    }}
                >
                    <ul
                        className="cf-listbox"
                        id={listboxId}
                        role="listbox"
                        aria-label={listLabel}
                        aria-busy={loading || undefined}
                    >
                        {results.map((result, position) => {
                            const { option } = result;
                            const isSelected = option.value === selectedValue;

                            return (
                                <li
                                    key={option.value}
                                    role="option"
                                    id={optionId(result.index)}
                                    className={cn(
                                        'cf-option',
                                        position === active && 'is-active',
                                    )}
                                    aria-selected={
                                        option.disabled ? undefined : isSelected
                                    }
                                    aria-disabled={option.disabled || undefined}
                                    onMouseMove={() =>
                                        !option.disabled &&
                                        position !== active &&
                                        setActive(position)
                                    }
                                    onClick={() => pick(option)}
                                >
                                    {option.leading}
                                    <span>
                                        <Segments segments={result.label} />
                                        {result.meta && (
                                            <>
                                                {' '}
                                                <span className="t-muted">
                                                    ·{' '}
                                                    <Segments
                                                        segments={result.meta}
                                                    />
                                                </span>
                                            </>
                                        )}
                                    </span>
                                    {isSelected && (
                                        <Icon
                                            icon={Check}
                                            size={16}
                                            className="cf-check-ic"
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </Popover.Content>
            </Popover.Root>
            {name !== undefined && (
                <input type="hidden" name={name} value={selectedValue ?? ''} />
            )}
        </Field>
    );
}

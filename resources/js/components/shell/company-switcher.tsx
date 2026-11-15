import { Check, ChevronDown, Plus } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { useShell } from '@/components/shell/shell-context';
import { formatCompanyMeta } from '@/lib/format';

/**
 * Top-bar company switcher (design-system CompanySwitcher): a 54px pill that
 * opens a listbox of the person's companies with their role in each.
 */
export function CompanySwitcher() {
    const { workspace, companies, currentCompany, switchCompany } = useShell();
    const [open, setOpen] = useState(false);
    const rootRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const listRef = useRef<HTMLDivElement>(null);
    const listId = useId();

    const items = () =>
        Array.from(
            listRef.current?.querySelectorAll<HTMLButtonElement>(
                'button:not(:disabled)',
            ) ?? [],
        );

    useEffect(() => {
        if (!open) {
            return;
        }

        listRef.current
            ?.querySelector<HTMLButtonElement>('[aria-selected="true"]')
            ?.focus();

        const onPointerDown = (event: PointerEvent) => {
            if (!rootRef.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, [open]);

    const close = () => {
        setOpen(false);
        triggerRef.current?.focus();
    };

    const onListKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const all = items();
        const index = all.indexOf(document.activeElement as HTMLButtonElement);

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                all[(index + 1) % all.length]?.focus();
                break;
            case 'ArrowUp':
                event.preventDefault();
                all[(index - 1 + all.length) % all.length]?.focus();
                break;
            case 'Home':
                event.preventDefault();
                all[0]?.focus();
                break;
            case 'End':
                event.preventDefault();
                all[all.length - 1]?.focus();
                break;
            case 'Escape':
                event.preventDefault();
                close();
                break;
            case 'Tab':
                setOpen(false);
                break;
        }
    };

    return (
        <div ref={rootRef} className="relative">
            <button
                ref={triggerRef}
                type="button"
                className="cf-switcher"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                aria-label={`Switch company, current: ${currentCompany.name}`}
                onClick={() => setOpen((value) => !value)}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown' && !open) {
                        event.preventDefault();
                        setOpen(true);
                    }
                }}
            >
                <span className="cf-switcher-ini" aria-hidden="true">
                    {currentCompany.initials}
                </span>
                <span className="cf-stack">
                    <span className="t-label-strong">
                        {currentCompany.name}
                    </span>
                    <span className="t-caption t-muted">
                        {formatCompanyMeta(
                            currentCompany.type,
                            currentCompany.role,
                        )}
                    </span>
                </span>
                <ChevronDown size={16} strokeWidth={1.5} aria-hidden="true" />
            </button>

            {open && (
                <div
                    ref={listRef}
                    id={listId}
                    className="cf-pop cf-menu absolute top-[62px] left-0 z-30 w-[300px] max-w-[calc(100vw-32px)] animate-[cfFade_var(--duration-panel)_var(--ease-out)]"
                    role="listbox"
                    aria-label="Your companies"
                    onKeyDown={onListKeyDown}
                >
                    <div className="cf-menu-label">
                        {workspace.name} · your companies
                    </div>
                    {companies.map((company) => {
                        const selected = company.id === currentCompany.id;

                        return (
                            <button
                                key={company.id}
                                type="button"
                                className="cf-menu-item"
                                role="option"
                                aria-selected={selected}
                                style={
                                    selected
                                        ? { background: 'var(--surface-muted)' }
                                        : undefined
                                }
                                onClick={() => {
                                    switchCompany(company.id);
                                    close();
                                }}
                            >
                                <span
                                    className="cf-avatar cf-avatar--sm"
                                    aria-hidden="true"
                                >
                                    {company.initials}
                                </span>
                                <span className="cf-stack flex-1">
                                    <span className="t-label-strong">
                                        {company.name}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {formatCompanyMeta(
                                            company.type,
                                            company.role,
                                        )}
                                    </span>
                                </span>
                                {selected && (
                                    <Check
                                        size={16}
                                        strokeWidth={1.75}
                                        aria-hidden="true"
                                    />
                                )}
                            </button>
                        );
                    })}
                    <div className="cf-menu-sep" role="separator" />
                    <button
                        type="button"
                        className="cf-menu-item"
                        disabled
                        aria-label="New company (available soon)"
                    >
                        <span
                            className="cf-disc"
                            style={{ width: 28, height: 28 }}
                            aria-hidden="true"
                        >
                            <Plus size={14} strokeWidth={1.75} />
                        </span>
                        <span className="t-label-strong">New company</span>
                    </button>
                </div>
            )}
        </div>
    );
}

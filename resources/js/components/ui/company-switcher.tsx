import * as PopoverPrimitive from '@radix-ui/react-popover';
import { Check, ChevronDown, Plus } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { ButtonHTMLAttributes, KeyboardEvent } from 'react';
import { Icon } from '@/components/ui/icon';
import { formatCompanyMeta } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CompanySummary } from '@/types';

type SwitcherLookProps = {
    companies: CompanySummary[];
    currentId: CompanySummary['id'];
    /** "Vertex Group": the menu label reads "Vertex Group · your companies". */
    workspaceName: string;
    onNewCompany?: () => void;
    /** Disable New company and say why, e.g. "available soon" or "Growth plan". */
    newCompanyDisabledReason?: string;
};

export function CompanySwitcherTrigger({
    company,
    expanded,
    className,
    ...rest
}: {
    company: CompanySummary;
    expanded?: boolean;
    className?: string;
} & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'>) {
    return (
        <button
            type="button"
            {...rest}
            className={cn('cf-switcher', className)}
            aria-haspopup="listbox"
            aria-expanded={expanded ?? rest['aria-expanded']}
            aria-label={`Switch company, current: ${company.name}`}
        >
            <span className="cf-switcher-ini" aria-hidden="true">
                {company.initials}
            </span>
            <span className="cf-stack cf-switcher-text">
                <span className="t-label-strong" title={company.name}>
                    {company.name}
                </span>
                <span className="t-caption t-muted">
                    {formatCompanyMeta(company.type, company.role)}
                </span>
            </span>
            <Icon icon={ChevronDown} size={16} />
        </button>
    );
}

type ListProps = SwitcherLookProps & {
    onSwitch?: (id: CompanySummary['id']) => void;
    /** Highlighted row, documentation only (`.is-active`). */
    activeId?: CompanySummary['id'];
};

const LIST_STYLE = { width: 300 } as const;

function ListContent({
    companies,
    currentId,
    workspaceName,
    onSwitch,
    onNewCompany,
    newCompanyDisabledReason,
    activeId,
}: ListProps) {
    return (
        <>
            <div className="cf-menu-label">
                {workspaceName} · your companies
            </div>
            {companies.map((company) => {
                const selected = company.id === currentId;

                return (
                    <button
                        key={company.id}
                        type="button"
                        className={cn(
                            'cf-menu-item',
                            company.id === activeId && 'is-active',
                        )}
                        role="option"
                        aria-selected={selected}
                        style={
                            selected
                                ? { background: 'var(--surface-muted)' }
                                : undefined
                        }
                        onClick={() => onSwitch?.(company.id)}
                    >
                        <span
                            className="cf-avatar cf-avatar--sm"
                            aria-hidden="true"
                        >
                            {company.initials}
                        </span>
                        <span className="cf-stack" style={{ flex: 1 }}>
                            <span className="t-label-strong">
                                {company.name}
                            </span>
                            <span className="t-caption t-muted">
                                {formatCompanyMeta(company.type, company.role)}
                            </span>
                        </span>
                        {selected && (
                            <Icon icon={Check} size={16} strokeWidth={1.75} />
                        )}
                    </button>
                );
            })}
            <div className="cf-menu-sep" role="separator" />
            <button
                type="button"
                className="cf-menu-item"
                disabled={newCompanyDisabledReason !== undefined}
                aria-label={
                    newCompanyDisabledReason
                        ? `New company (${newCompanyDisabledReason})`
                        : undefined
                }
                title={newCompanyDisabledReason}
                onClick={onNewCompany}
            >
                <span className="cf-disc" style={{ width: 28, height: 28 }}>
                    <Icon icon={Plus} size={14} strokeWidth={1.75} />
                </span>
                <span className="t-label-strong">New company</span>
            </button>
        </>
    );
}

/** The open list (`cf-pop cf-menu`, `role="listbox"`), static, for the gallery and docs. */
export function CompanySwitcherList({
    className,
    ...rest
}: ListProps & { className?: string }) {
    return (
        <div
            className={cn('cf-pop cf-menu', className)}
            role="listbox"
            aria-label="Your companies"
            style={LIST_STYLE}
        >
            <ListContent {...rest} />
        </div>
    );
}

export type CompanySwitcherProps = SwitcherLookProps & {
    onSwitch: (id: CompanySummary['id']) => void;
};

/**
 * Top-bar company switcher: the 54px `cf-switcher` pill opens a listbox of the
 * person's companies with their type and role in each, ending with New company.
 * ↑ ↓ Home End move, Esc closes and returns focus to the pill.
 */
export function CompanySwitcher({
    companies,
    currentId,
    workspaceName,
    onSwitch,
    onNewCompany,
    newCompanyDisabledReason,
}: CompanySwitcherProps) {
    const [open, setOpen] = useState(false);
    const listRef = useRef<HTMLDivElement>(null);
    const listId = useId();
    const current = companies.find((c) => c.id === currentId) ?? companies[0];

    const items = () =>
        Array.from(
            listRef.current?.querySelectorAll<HTMLButtonElement>(
                'button:not(:disabled)',
            ) ?? [],
        );

    const onListKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const all = items();
        const index = all.indexOf(document.activeElement as HTMLButtonElement);
        const move = (to: number) => {
            event.preventDefault();
            all[(to + all.length) % all.length]?.focus();
        };

        switch (event.key) {
            case 'ArrowDown':
                move(index + 1);
                break;
            case 'ArrowUp':
                move(index - 1);
                break;
            case 'Home':
                move(0);
                break;
            case 'End':
                move(all.length - 1);
                break;
            case 'Tab':
                setOpen(false);
                break;
        }
    };

    if (!current) {
        return null;
    }

    return (
        <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
            <PopoverPrimitive.Trigger asChild>
                <CompanySwitcherTrigger
                    company={current}
                    aria-controls={open ? listId : undefined}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown' && !open) {
                            event.preventDefault();
                            setOpen(true);
                        }
                    }}
                />
            </PopoverPrimitive.Trigger>
            <PopoverPrimitive.Portal>
                <PopoverPrimitive.Content
                    ref={listRef}
                    id={listId}
                    role="listbox"
                    aria-label="Your companies"
                    className="cf-pop cf-menu z-50 max-w-[calc(100vw-32px)] animate-[cfFade_var(--duration-panel)_var(--ease-out)] focus-visible:shadow-pop"
                    style={LIST_STYLE}
                    onKeyDown={onListKeyDown}
                    align="start"
                    sideOffset={8}
                    collisionPadding={16}
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        listRef.current
                            ?.querySelector<HTMLButtonElement>(
                                '[aria-selected="true"]',
                            )
                            ?.focus();
                    }}
                >
                    <ListContent
                        companies={companies}
                        currentId={current.id}
                        workspaceName={workspaceName}
                        newCompanyDisabledReason={newCompanyDisabledReason}
                        onSwitch={(id) => {
                            setOpen(false);
                            onSwitch(id);
                        }}
                        onNewCompany={
                            onNewCompany &&
                            (() => {
                                setOpen(false);
                                onNewCompany();
                            })
                        }
                    />
                </PopoverPrimitive.Content>
            </PopoverPrimitive.Portal>
        </PopoverPrimitive.Root>
    );
}

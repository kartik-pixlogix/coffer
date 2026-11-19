import { KeyRound, LayoutGrid, List, Plus, Star } from 'lucide-react';
import { Avatar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { AvatarStack } from '@/components/ui/avatar-stack';
import { Button, ButtonLink } from '@/components/ui/button';
import { ButtonGroup, ButtonGroupButton } from '@/components/ui/button-group';
import { EmptyState } from '@/components/ui/empty-state';
import { SearchField } from '@/components/ui/search-field';
import { Select } from '@/components/ui/select';
import { EnvPill, StrengthMeter } from '@/components/vault/home/vault-bits';
import {
    rowWhen,
    strengthView,
} from '@/components/vault/home/vault-home.utils';
import type {
    VaultSort,
    VaultView,
} from '@/components/vault/home/vault-home.utils';
import { create } from '@/routes/vault/items';
import type { VaultItemRow, VaultPerson } from '@/types/vault';

const people = (list: VaultPerson[]) =>
    list.map((p) => ({
        name: p.name,
        partner:
            p.colour !== null && p.colour >= 1 && p.colour <= 6
                ? (p.colour as PartnerColour)
                : undefined,
    }));

function FavStar() {
    return (
        <Star
            width={13}
            height={13}
            fill="currentColor"
            strokeWidth={1.5}
            role="img"
            aria-label="Favourite"
            className="vh-star"
        />
    );
}

type ListProps = {
    items: VaultItemRow[];
    /** Every item the person can see (to tell "no items" from "no matches"). */
    total: number;
    title: string;
    query: string;
    onQuery: (query: string) => void;
    sort: VaultSort;
    onSort: (sort: VaultSort) => void;
    view: VaultView;
    onView: (view: VaultView) => void;
    openId: number | null;
    onOpen: (id: number) => void;
    onShowAll: () => void;
    canCreate: boolean;
    timeZone: string;
};

/** Search, sort and list / grid of the vault items (Vault.dc.html centre card). */
export function VaultList({
    items,
    total,
    title,
    query,
    onQuery,
    sort,
    onSort,
    view,
    onView,
    openId,
    onOpen,
    onShowAll,
    canCreate,
    timeZone,
}: ListProps) {
    if (total === 0) {
        return (
            <EmptyState
                icon={KeyRound}
                headingLevel={2}
                title="No items yet"
                description="Save the first login, API key or server here. Reveal and copy ask for a code from your authenticator app, and every use is in the audit log."
                className="vh-list vh-list--empty"
                actions={
                    canCreate ? (
                        <ButtonLink
                            href={create().url}
                            variant="primary"
                            disc={Plus}
                        >
                            Add item
                        </ButtonLink>
                    ) : undefined
                }
            />
        );
    }

    return (
        <section className="vh-list" aria-labelledby="vh-list-t">
            <div className="vh-toolbar">
                <SearchField
                    aria-label="Search the vault"
                    placeholder="Search name, username or host"
                    value={query}
                    onValueChange={onQuery}
                    wrapClassName="vh-search"
                />
                <Select
                    pill
                    aria-label="Sort items"
                    value={sort}
                    onChange={(event) =>
                        onSort(event.currentTarget.value as VaultSort)
                    }
                    options={[
                        { value: 'rotated', label: 'Recently rotated' },
                        { value: 'name', label: 'Name A–Z' },
                    ]}
                />
                <ButtonGroup aria-label="View">
                    <ButtonGroupButton
                        icon={List}
                        aria-label="List view"
                        pressed={view === 'list'}
                        onClick={() => onView('list')}
                    />
                    <ButtonGroupButton
                        icon={LayoutGrid}
                        aria-label="Grid view"
                        pressed={view === 'grid'}
                        onClick={() => onView('grid')}
                    />
                </ButtonGroup>
            </div>
            <div className="vh-list-head">
                <h2 className="t-title m-0" id="vh-list-t">
                    {title}
                </h2>
                <span className="t-caption t-muted" aria-live="polite">
                    {items.length} {items.length === 1 ? 'item' : 'items'}
                </span>
            </div>

            {items.length === 0 ? (
                <div className="vh-nomatch">
                    <span className="t-label-strong">Nothing here yet</span>
                    <span className="t-caption t-muted">
                        No items match this vault, filter or search.
                    </span>
                    <Button size="sm" onClick={onShowAll}>
                        Show all items
                    </Button>
                </div>
            ) : view === 'list' ? (
                <div className="vh-rows-scroll">
                    <ul className="vh-rows">
                        {items.map((item) => (
                            <li key={item.id}>
                                <ItemRow
                                    item={item}
                                    on={item.id === openId}
                                    onOpen={onOpen}
                                    timeZone={timeZone}
                                />
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <ul className="vh-grid">
                    {items.map((item) => (
                        <li key={item.id}>
                            <ItemCard
                                item={item}
                                on={item.id === openId}
                                onOpen={onOpen}
                                timeZone={timeZone}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

type RowProps = {
    item: VaultItemRow;
    on: boolean;
    onOpen: (id: number) => void;
    timeZone: string;
};

function ItemRow({ item, on, onOpen, timeZone }: RowProps) {
    const sub = [item.subtitle, rowWhen(item, timeZone)]
        .filter(Boolean)
        .join(' · ');

    return (
        <button
            type="button"
            className="vh-row"
            aria-pressed={on}
            data-theme={on ? 'light' : undefined}
            onClick={() => onOpen(item.id)}
        >
            <span className="vh-row-main">
                <Avatar
                    name={item.name}
                    initials={item.monogram}
                    tile
                    graphite={on}
                    label={false}
                    className="vh-tile"
                />
                <span className="vh-row-text">
                    <span className="t-label-strong vh-row-name">
                        <span className="vh-ellipsis" title={item.name}>
                            {item.name}
                        </span>
                        {item.favourite && <FavStar />}
                    </span>
                    <span className="t-money-sm t-muted vh-ellipsis">
                        {sub}
                    </span>
                </span>
            </span>
            <span>
                <EnvPill environment={item.environment} />
            </span>
            <StrengthMeter view={strengthView(item)} />
            <span className="vh-row-people">
                {item.people.length > 0 && (
                    <AvatarStack
                        people={people(item.people)}
                        prefix="Shared with"
                        max={3}
                    />
                )}
            </span>
        </button>
    );
}

function ItemCard({ item, on, onOpen, timeZone }: RowProps) {
    const strength = strengthView(item);
    const when = rowWhen(item, timeZone);

    return (
        <button
            type="button"
            className="vh-card"
            aria-pressed={on}
            data-theme={on ? 'light' : undefined}
            onClick={() => onOpen(item.id)}
        >
            <span className="vh-card-top">
                <Avatar
                    name={item.name}
                    initials={item.monogram}
                    tile
                    graphite={on}
                    label={false}
                    className="vh-tile"
                />
                <EnvPill environment={item.environment} />
            </span>
            <span className="vh-row-text">
                <span className="t-label-strong vh-row-name">
                    <span className="vh-ellipsis" title={item.name}>
                        {item.name}
                    </span>
                    {item.favourite && <FavStar />}
                </span>
                {item.subtitle && (
                    <span className="t-money-sm t-muted vh-ellipsis">
                        {item.subtitle}
                    </span>
                )}
            </span>
            <span className={`t-caption vh-str--${strength.tone}`}>
                {[strength.label, when].filter(Boolean).join(' · ')}
            </span>
        </button>
    );
}

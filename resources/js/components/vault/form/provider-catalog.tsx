import { Search } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterChip, FilterChipGroup } from '@/components/ui/filter-chips';
import { SearchField } from '@/components/ui/search-field';
import type { VaultItemType, VaultProvider } from '@/types/vault';

export type CatalogPick =
    | { kind: 'provider'; provider: VaultProvider }
    | { kind: 'type'; type: VaultItemType; label: string }
    | null;

/** "Next you'll fill in …" for a blank item of each type. */
export const BLANK_FILLS: Record<VaultItemType, string> = {
    login: 'URL, username and password',
    api_key: 'the key, its scope and expiry date',
    server: 'host, user and the private key',
    database: 'host, port, database name, user and password',
    hosting: 'panel URL, FTP host, user and password',
    domain: 'the domain, registrar, renewal date and nameservers',
    email: 'address, password and IMAP/SMTP settings',
    payment: 'mode, keys and webhook secret',
    licence: 'licence key, seats and renewal date',
    note: 'a title and the note itself',
};

const ALL = 'All';

type ProviderCatalogProps = {
    catalog: (VaultProvider & { saved: number })[];
    categories: string[];
    types: { type: VaultItemType; label: string }[];
    pick: CatalogPick;
    onPick: (pick: CatalogPick) => void;
};

/**
 * Add item, step 1 (VaultAddCatalog.dc.html): search the provider catalog or filter it
 * by category, or start blank from a type. Providers show monogram tiles, never logos.
 */
export function ProviderCatalog({
    catalog,
    categories,
    types,
    pick,
    onPick,
}: ProviderCatalogProps) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState(ALL);
    const q = query.trim().toLowerCase();
    const named = catalog.filter((p) => p.key !== 'custom').length;
    const custom = catalog.find((p) => p.key === 'custom');
    const tiles = catalog.filter(
        (p) =>
            (category === ALL ||
                p.category === category ||
                p.key === 'custom') &&
            (q === '' ||
                p.name.toLowerCase().includes(q) ||
                p.category.toLowerCase().includes(q)),
    );
    const pickedKey = pick?.kind === 'provider' ? pick.provider.key : null;
    const pickedType = pick?.kind === 'type' ? pick.type : null;

    return (
        <>
            <section className="cf-card vf-catalog" aria-labelledby="vf-pc-h">
                <div className="cf-kv vf-catalog-head">
                    <h2 id="vf-pc-h" className="cf-dot-title t-title">
                        What are you saving?
                    </h2>
                    <SearchField
                        variant="topbar"
                        showShortcut={false}
                        wrapClassName="vf-catalog-search"
                        aria-label="Search providers"
                        placeholder={`Search ${named} providers`}
                        value={query}
                        onValueChange={setQuery}
                    />
                </div>
                <FilterChipGroup aria-label="Category" scroll>
                    {[ALL, ...categories].map((c) => (
                        <FilterChip
                            key={c}
                            pressed={category === c}
                            onClick={() => setCategory(c)}
                        >
                            {c}
                        </FilterChip>
                    ))}
                </FilterChipGroup>
                {tiles.length > 0 ? (
                    <div
                        className="vf-tiles"
                        role="group"
                        aria-label="Providers"
                    >
                        {tiles.map((p) => (
                            <button
                                key={p.key}
                                type="button"
                                className="pt"
                                aria-pressed={pickedKey === p.key}
                                aria-label={`${p.name}, ${p.hint}${p.saved > 0 ? `, ${p.saved} already saved` : ''}`}
                                onClick={() =>
                                    onPick({ kind: 'provider', provider: p })
                                }
                            >
                                <span className="pt-mono" aria-hidden="true">
                                    {p.monogram}
                                </span>
                                <span className="cf-stack vf-tile-text">
                                    <span className="t-label-strong">
                                        {p.name}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {p.hint}
                                    </span>
                                </span>
                                {p.saved > 0 && (
                                    <span className="cf-pill cf-pill--outline cf-pill--sm">
                                        {`${p.saved} in vault`}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        icon={Search}
                        className="vf-catalog-empty"
                        title={`No provider called “${query.trim()}”`}
                        description="Use Custom to save it anyway. Every field works the same."
                        actions={
                            custom && (
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        setQuery('');
                                        setCategory(ALL);
                                        onPick({
                                            kind: 'provider',
                                            provider: custom,
                                        });
                                    }}
                                >
                                    Use Custom
                                </Button>
                            )
                        }
                    />
                )}
            </section>

            <section className="cf-card vf-blank" aria-labelledby="vf-bl-h">
                <div className="cf-stack vf-blank-text">
                    <h2 id="vf-bl-h" className="t-title vf-blank-title">
                        Or start blank
                    </h2>
                    <span className="t-caption t-muted">
                        Pick a type and fill in your own fields.
                    </span>
                </div>
                <FilterChipGroup aria-label="Item type" scroll>
                    {types.map((t) => (
                        <FilterChip
                            key={t.type}
                            pressed={pickedType === t.type}
                            onClick={() =>
                                onPick({
                                    kind: 'type',
                                    type: t.type,
                                    label: t.label,
                                })
                            }
                        >
                            {t.label}
                        </FilterChip>
                    ))}
                </FilterChipGroup>
            </section>
        </>
    );
}

import { Head, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { Button, ButtonLink } from '@/components/ui/button';
import { Stepper } from '@/components/ui/stepper';
import { ItemForm } from '@/components/vault/form/item-form';
import {
    BLANK_FILLS,
    ProviderCatalog,
} from '@/components/vault/form/provider-catalog';
import type { CatalogPick } from '@/components/vault/form/provider-catalog';
import { index as vaultIndex } from '@/routes/vault';
import { create } from '@/routes/vault/items';
import type { VaultCreateProps } from '@/types/vault';

/** The selection banner: "GitLab · Website login — Next you'll fill in …". */
function selectionOf(
    pick: NonNullable<CatalogPick>,
    typeLabel: (type: string) => string,
) {
    if (pick.kind === 'provider') {
        return {
            mono: pick.provider.monogram,
            title: `${pick.provider.name} · ${typeLabel(pick.provider.type)}`,
            fills: pick.provider.fills,
        };
    }

    return {
        mono: '+',
        title: `${pick.label} · Blank`,
        fills: BLANK_FILLS[pick.type],
    };
}

/** Add item, step 1 (VaultAddCatalog.dc.html). */
function CatalogStep({
    catalog,
    categories,
    types,
    defaultVaultId,
}: VaultCreateProps) {
    const [pick, setPick] = useState<CatalogPick>(null);
    const typeLabel = (type: string) =>
        types.find((t) => t.type === type)?.label ?? type;
    const vault = defaultVaultId === null ? {} : { vault: defaultVaultId };

    const proceed = () => {
        if (pick === null) {
            return;
        }

        router.get(
            create({
                query:
                    pick.kind === 'provider'
                        ? { provider: pick.provider.key, ...vault }
                        : { type: pick.type, ...vault },
            }).url,
        );
    };

    const selection = pick === null ? null : selectionOf(pick, typeLabel);

    return (
        <>
            <Head title="Add item" />
            <PageHeader
                kicker="Vault"
                title="Add item"
                crumbs={[
                    { title: 'Credentials Vault', href: vaultIndex().url },
                    { title: 'Add item' },
                ]}
                actions={
                    <>
                        <ButtonLink
                            href={vaultIndex().url}
                            variant="secondary"
                            size="lg"
                        >
                            Cancel
                        </ButtonLink>
                        {pick === null ? (
                            <Button variant="primary" size="lg" disabled>
                                Continue
                            </Button>
                        ) : (
                            <Button
                                variant="primary"
                                size="lg"
                                disc={ArrowRight}
                                onClick={proceed}
                            >
                                Continue
                            </Button>
                        )}
                    </>
                }
            />

            <Stepper
                aria-label="Add item steps"
                steps={['Choose a provider', 'Details', 'Share']}
                current={0}
            />

            <ProviderCatalog
                catalog={catalog}
                categories={categories}
                types={types}
                pick={pick}
                onPick={setPick}
            />

            {selection && (
                <section
                    className="cf-alert cf-alert--graphite"
                    aria-live="polite"
                    aria-label="Selection"
                >
                    <div className="cf-alert-main">
                        <span
                            className="cf-alert-ic vf-alert-mono"
                            aria-hidden="true"
                        >
                            {selection.mono}
                        </span>
                        <div>
                            <div className="cf-alert-title">
                                {selection.title}
                            </div>
                            <div className="cf-alert-sub">
                                Next you’ll fill in {selection.fills}.
                            </div>
                        </div>
                    </div>
                    <Button variant="lime" size="lg" onClick={proceed}>
                        Continue to details
                    </Button>
                </section>
            )}
        </>
    );
}

/**
 * Credentials Vault: Add item (catalog, then the template form) and Edit item, one
 * Inertia page (spec §14.4).
 */
export default function VaultCreate(props: VaultCreateProps) {
    if (props.step === 'catalog') {
        return <CatalogStep {...props} />;
    }

    return (
        <ItemForm
            key={`${props.item?.id ?? 'new'}-${props.provider?.key ?? ''}-${props.type ?? ''}`}
            {...props}
        />
    );
}

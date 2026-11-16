import { router } from '@inertiajs/react';
import { Check, RefreshCw, UserMinus } from 'lucide-react';
import { Avatar } from '@/components/ui/avatar';
import { Button, ButtonLink } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { StatusPill } from '@/components/ui/status-pill';
import { formatDateIn } from '@/components/vault/home/vault-home.utils';
import { edit } from '@/routes/vault/items';
import { dismiss } from '@/routes/vault/offboardings';
import type { VaultOffboardingRow } from '@/types/vault';

type Props = {
    offboardings: VaultOffboardingRow[];
    canDismiss: boolean;
    timeZone?: string;
    headingLevel?: 2 | 3;
};

/**
 * "N items they could use → rotate these" (spec §14.2, D73): one block per person who
 * left or lost access, with Rotate on each item still to change.
 */
export function OffboardingCard({
    offboardings,
    canDismiss,
    timeZone,
    headingLevel = 2,
}: Props) {
    if (offboardings.length === 0) {
        return null;
    }

    const Heading = headingLevel === 2 ? 'h2' : 'h3';

    return (
        <section className="cf-card cf-stack vo-card" aria-labelledby="vo-h">
            <Heading id="vo-h" className="cf-dot-title t-title">
                Rotate after someone leaves
            </Heading>
            <p className="t-caption t-muted m-0">
                They could still remember these passwords. Rotating swaps in a
                new one and keeps the old one in history.
            </p>
            {offboardings.map((o) => (
                <div key={o.id} className="cf-stack vo-person">
                    <div className="cf-kv vo-person-head">
                        <span className="cf-row vo-who">
                            <Avatar name={o.name} size="sm" label={false} />
                            <span className="cf-stack">
                                <span className="t-label-strong">
                                    {o.name} {o.reason}
                                </span>
                                <span className="t-caption t-muted">
                                    {formatDateIn(o.at, timeZone)} · {o.total}{' '}
                                    {o.total === 1 ? 'item' : 'items'} they
                                    could use →{' '}
                                    {o.open === 0
                                        ? 'all rotated'
                                        : `rotate ${o.open === 1 ? 'this' : 'these'}`}
                                </span>
                            </span>
                        </span>
                        <span className="cf-row vo-acts">
                            <StatusPill
                                tone={o.open === 0 ? 'pos' : 'warn'}
                                icon={o.open === 0 ? Check : RefreshCw}
                                size="sm"
                            >
                                {o.open === 0
                                    ? 'All rotated'
                                    : `${o.open} to rotate`}
                            </StatusPill>
                            {canDismiss && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={`Dismiss the list for ${o.name}`}
                                    onClick={() =>
                                        router.post(
                                            dismiss(o.id).url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Dismiss
                                </Button>
                            )}
                        </span>
                    </div>
                    {o.items.map((item) => (
                        <div key={item.id} className="vo-row">
                            <Avatar
                                name={item.name}
                                initials={item.monogram}
                                tile
                                label={false}
                            />
                            <span className="cf-stack vo-row-text">
                                <span className="t-label-strong">
                                    {item.name}
                                </span>
                                <span className="t-caption t-muted">
                                    {item.vault} vault
                                </span>
                            </span>
                            {item.rotated ? (
                                <StatusPill tone="pos" icon={Check} size="sm">
                                    Rotated
                                </StatusPill>
                            ) : item.canRotate ? (
                                <ButtonLink
                                    size="sm"
                                    variant="secondary"
                                    icon={RefreshCw}
                                    href={
                                        edit(item.id, { query: { rotate: 1 } })
                                            .url
                                    }
                                    aria-label={`Rotate ${item.name}`}
                                >
                                    Rotate
                                </ButtonLink>
                            ) : (
                                <span className="t-caption t-muted">
                                    Ask a Manager
                                </span>
                            )}
                        </div>
                    ))}
                    {o.hidden > 0 && (
                        <p className="t-caption t-muted vo-hidden m-0">
                            <Icon icon={UserMinus} size={14} />
                            {o.hidden} more {o.hidden === 1 ? 'item' : 'items'}{' '}
                            you can’t see. An Admin can rotate{' '}
                            {o.hidden === 1 ? 'it' : 'them'}.
                        </p>
                    )}
                </div>
            ))}
        </section>
    );
}

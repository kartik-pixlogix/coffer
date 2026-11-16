import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { PartnerModal } from '@/components/settings/partner-modal';
import type {
    PartnerData,
    SideData,
} from '@/components/settings/partner-modal';
import { SettingsShell } from '@/components/settings/settings-shell';
import { initialsFrom } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Money } from '@/components/ui/money';
import { formatEquityShort } from '@/lib/equity';
import { formatHours } from '@/lib/format';

type Partner = PartnerData & {
    stats: { revenuePaise: number; profitDuePaise: number; hours: number };
};

type Props = {
    company: { name: string; type: 'single' | 'jv' };
    sides: SideData[];
    partners: Partner[];
};

/**
 * Settings › Partners & equity (design/screens/SettingsPartners.dc.html, spec §16):
 * the equity bar, a card per partner with their overall share, and the edit modal.
 */
export default function Partners({ company, sides, partners }: Props) {
    const jv = company.type === 'jv';
    const [editing, setEditing] = useState<Partner | null>(null);
    const [adding, setAdding] = useState(false);
    const sideById = new Map(sides.map((s) => [s.id, s]));

    const summary = jv
        ? sides
              .map((s) => `${s.name} ${formatEquityShort(s.equityBps)}`)
              .join(' · ')
        : `${partners.length} ${partners.length === 1 ? 'partner' : 'partners'}`;

    return (
        <SettingsShell
            tab="partners"
            title="Partners"
            crumb="Partners & equity"
            actions={
                <Button
                    variant="primary"
                    size="lg"
                    disc={Plus}
                    onClick={() => setAdding(true)}
                >
                    Add partner
                </Button>
            }
        >
            <Head title="Partners & equity" />

            <section
                className="cf-card flex flex-col gap-3"
                aria-labelledby="eq-h"
            >
                <div className="cf-kv flex-wrap">
                    <h2 id="eq-h" className="cf-dot-title t-title">
                        Equity split
                    </h2>
                    <span className="t-caption t-muted">{summary}</span>
                </div>
                <div
                    className="seg-bar"
                    role="img"
                    aria-label={partners
                        .map(
                            (p) =>
                                `${p.name} ${formatEquityShort(p.overallBps)}`,
                        )
                        .join(', ')}
                >
                    {partners.map((p) => (
                        <span
                            key={p.id}
                            style={{
                                flex: p.overallBps,
                                background: `var(--partner-${p.colour})`,
                            }}
                        />
                    ))}
                </div>
                <div className="cf-legend flex-wrap">
                    {partners.map((p) => (
                        <span key={p.id} className="cf-row gap-1.5">
                            <span
                                className="cf-swatch cf-swatch--sq"
                                style={{
                                    background: `var(--partner-${p.colour})`,
                                }}
                            />
                            <span className="t-body">{p.name}</span>
                            <span className="t-money-sm">
                                {formatEquityShort(p.overallBps)}
                            </span>
                        </span>
                    ))}
                </div>
            </section>

            <div className="partner-grid">
                {partners.map((p) => {
                    const side =
                        p.sideId === null ? undefined : sideById.get(p.sideId);

                    return (
                        <article
                            key={p.id}
                            className="cf-card flex flex-col gap-3.5"
                            aria-label={p.name}
                        >
                            <div className="cf-row gap-3">
                                <span
                                    className="cf-avatar cf-avatar--lg"
                                    aria-hidden="true"
                                >
                                    {initialsFrom(p.name)}
                                    <span
                                        className="cf-avatar-dot"
                                        style={{
                                            background: `var(--partner-${p.colour})`,
                                        }}
                                    />
                                </span>
                                <span className="cf-stack min-w-0 flex-1">
                                    <span className="t-label-strong text-[16px]">
                                        {p.name}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {side ? `${side.name} side · ` : ''}
                                        {p.role === 'admin'
                                            ? 'Admin'
                                            : 'Partner'}
                                    </span>
                                </span>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setEditing(p)}
                                    aria-label={`Edit ${p.name}`}
                                >
                                    Edit
                                </Button>
                            </div>
                            <div className="cf-kv">
                                <span className="cf-stack">
                                    <span className="t-caption t-muted">
                                        Overall equity
                                    </span>
                                    <span className="t-money-lg partner-figure">
                                        {formatEquityShort(p.overallBps)}
                                    </span>
                                </span>
                                {side && (
                                    <span className="t-caption t-muted text-right">
                                        {formatEquityShort(p.equityBps)} of{' '}
                                        {side.name}’s{' '}
                                        {formatEquityShort(side.equityBps)}
                                    </span>
                                )}
                            </div>
                            <div
                                className="cf-progress"
                                role="progressbar"
                                aria-label={
                                    jv
                                        ? `${p.name}’s share of ${side?.name ?? 'the side'}`
                                        : `${p.name}’s share of ${company.name}`
                                }
                                aria-valuenow={p.equityBps / 100}
                                aria-valuemin={0}
                                aria-valuemax={100}
                            >
                                <span
                                    style={{
                                        width: `${p.equityBps / 100}%`,
                                        background: `var(--partner-${p.colour})`,
                                    }}
                                />
                            </div>
                            <div className="partner-stats">
                                <div className="cf-inset cf-stack invite-desc">
                                    <span className="t-caption t-muted">
                                        Revenue
                                    </span>
                                    <Money paise={p.stats.revenuePaise} />
                                </div>
                                <div className="cf-inset cf-stack invite-desc">
                                    <span className="t-caption t-muted">
                                        Profit due
                                    </span>
                                    <Money paise={p.stats.profitDuePaise} />
                                </div>
                                <div className="cf-inset cf-stack invite-desc">
                                    <span className="t-caption t-muted">
                                        Hours
                                    </span>
                                    <span className="t-money">
                                        {formatHours(p.stats.hours)}
                                    </span>
                                </div>
                            </div>
                        </article>
                    );
                })}
            </div>

            <PartnerModal
                open={editing !== null || adding}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                        setAdding(false);
                    }
                }}
                partner={editing}
                partners={partners}
                sides={sides}
                companyName={company.name}
                jv={jv}
            />
        </SettingsShell>
    );
}

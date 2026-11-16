import { Clock, Lock, Repeat2, ShieldAlert, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { StatusPill } from '@/components/ui/status-pill';
import type { StatusTone } from '@/components/ui/status-pill';
import { LEVEL_LABEL } from '@/components/vault/home/vault-home.utils';
import type {
    ItemFlag,
    ItemFlagKey,
    StrengthView,
} from '@/components/vault/home/vault-home.utils';
import { cn } from '@/lib/utils';
import type { VaultEnvironment, VaultLevel } from '@/types/vault';

const LEVEL_TONE: Record<VaultLevel, StatusTone> = {
    manage: 'graphite',
    edit: 'lime',
    use: 'neutral',
    view: 'outline',
};

/** Access level pill: Manage graphite, Edit lime, Use muted, View outline (Vault.dc.html). */
export function LevelPill({
    level,
    size = 'sm',
}: {
    level: VaultLevel;
    size?: 'sm' | 'md';
}) {
    return (
        <StatusPill tone={LEVEL_TONE[level]} size={size}>
            {LEVEL_LABEL[level]}
        </StatusPill>
    );
}

const ENV: Record<
    VaultEnvironment,
    { status: 'production' | 'staging' | 'dev' }
> = {
    production: { status: 'production' },
    staging: { status: 'staging' },
    development: { status: 'dev' },
};

export function EnvPill({
    environment,
    size = 'sm',
}: {
    environment: VaultEnvironment | null;
    size?: 'sm' | 'md';
}) {
    if (environment === null) {
        return null;
    }

    return <StatusPill status={ENV[environment].status} size={size} />;
}

const FLAG_ICON: Record<ItemFlagKey, LucideIcon> = {
    breached: ShieldAlert,
    expiry: TriangleAlert,
    reused: Repeat2,
    old: Clock,
    restricted: Lock,
};

/** A status pill for one item flag: always an icon and a word. */
export function FlagPill({
    flag,
    size = 'md',
}: {
    flag: ItemFlag;
    size?: 'sm' | 'md';
}) {
    return (
        <StatusPill tone={flag.tone} icon={FLAG_ICON[flag.key]} size={size}>
            {flag.label}
        </StatusPill>
    );
}

/** Four strength bars and the word under them (decorative bars; the word carries it). */
export function StrengthMeter({
    view,
    className,
}: {
    view: StrengthView;
    className?: string;
}) {
    return (
        <span className={cn('vh-strength', className)}>
            <span className="vh-bars" aria-hidden="true">
                {[0, 1, 2, 3].map((i) => (
                    <span
                        key={i}
                        className={cn(
                            'vh-bar',
                            i < view.filled && `vh-bar--${view.tone}`,
                        )}
                    />
                ))}
            </span>
            <span className={cn('t-caption', `vh-str--${view.tone}`)}>
                {view.label}
            </span>
        </span>
    );
}

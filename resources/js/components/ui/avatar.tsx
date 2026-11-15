import type { LucideIcon } from 'lucide-react';
import type { CSSProperties } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type AvatarSize = 'sm' | 'md' | 'lg' | 'xl';

/** Partner identity colour, in the order partners were added (`--partner-1`…`--partner-6`). */
export type PartnerColour = 1 | 2 | 3 | 4 | 5 | 6;

export const partnerColourVar = (n: PartnerColour): string =>
    `var(--partner-${n})`;

/**
 * Two-letter initials: the first letters of the first two words ("Aarav Mehta" → AM,
 * "Saarthi Portal JV" → SP), or the first two letters of a single word ("Razorpay" → RA).
 * Pass `initials` for provider monograms (GH, AW) that don't follow this rule.
 */
export function initialsFrom(name: string): string {
    const words = name
        .trim()
        .split(/\s+/u)
        .map((word) => word.replace(/^[^\p{L}\p{N}]+/u, ''))
        .filter(Boolean);

    if (words.length === 0) {
        return '';
    }

    const letters =
        words.length === 1
            ? Array.from(words[0]).slice(0, 2)
            : [Array.from(words[0])[0], Array.from(words[1])[0]];

    return letters.join('').toLocaleUpperCase('en-IN');
}

export type AvatarProps = {
    /** Person, company or provider name: gives the initials and the default label. */
    name?: string;
    /** Override the derived initials (provider monograms: GH, AW, RZ). */
    initials?: string;
    /** A line icon instead of initials (system actors, e.g. automatic payroll). */
    icon?: LucideIcon;
    size?: AvatarSize;
    /** Companies and vault providers. */
    graphite?: boolean;
    /** Rounded square, for vault providers. */
    tile?: boolean;
    /** Partner dot colour; the partner's name must also be in the label or nearby. */
    partner?: PartnerColour;
    /**
     * Accessible name, e.g. "Aarav Mehta, Vertex Labs". Defaults to `name`.
     * `false` makes the avatar decorative (inside an AvatarStack, or next to the name).
     */
    label?: string | false;
    className?: string;
};

const DOT_XL: CSSProperties = { width: 14, height: 14 };

/** Initials avatar (`cf-avatar`): 28 / 36 / 48 / 64 px, graphite, tile, partner dot. */
export function Avatar({
    name,
    initials,
    icon,
    size = 'md',
    graphite = false,
    tile = false,
    partner,
    label,
    className,
}: AvatarProps) {
    const accessibleName = label === false ? undefined : (label ?? name);

    return (
        <span
            className={cn(
                'cf-avatar',
                size !== 'md' && `cf-avatar--${size}`,
                tile && 'cf-avatar--tile',
                graphite && 'cf-avatar--graphite',
                className,
            )}
            role={accessibleName ? 'img' : undefined}
            aria-label={accessibleName || undefined}
        >
            {icon ? (
                <Icon icon={icon} size={16} />
            ) : (
                (initials ?? (name ? initialsFrom(name) : ''))
            )}
            {partner !== undefined && (
                <span
                    className="cf-avatar-dot"
                    style={{
                        background: partnerColourVar(partner),
                        ...(size === 'xl' ? DOT_XL : undefined),
                    }}
                />
            )}
        </span>
    );
}

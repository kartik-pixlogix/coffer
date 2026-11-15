import { Avatar } from '@/components/ui/avatar';
import type { AvatarSize, PartnerColour } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

export type StackPerson = {
    name: string;
    initials?: string;
    partner?: PartnerColour;
};

/** "Aarav Mehta", "Aarav Mehta and Kabir Shah", "A, B, C and D". */
export function joinNames(names: string[]): string {
    if (names.length <= 1) {
        return names[0] ?? '';
    }

    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

export type AvatarStackProps = {
    people: StackPerson[];
    /** Default `sm` (28 px), the size used in table rows and vault cards. */
    size?: AvatarSize;
    /** Avatars shown before the +N count (default 3). */
    max?: number;
    /** Lead-in for the label: "Shared with", "Paid by". The label then lists everyone. */
    prefix?: string;
    /** Full accessible name, replacing `prefix` + names (e.g. "Four partners"). */
    label?: string;
    className?: string;
};

/**
 * Overlapping avatars for who shares or paid for something: up to `max`, then +N.
 * One `role="img"` whose label lists everyone; the avatars inside are decorative.
 */
export function AvatarStack({
    people,
    size = 'sm',
    max = 3,
    prefix,
    label,
    className,
}: AvatarStackProps) {
    const shown = people.slice(0, Math.max(0, max));
    const hidden = people.length - shown.length;
    const names = joinNames(people.map((p) => p.name));
    const accessibleName = label ?? (prefix ? `${prefix} ${names}` : names);

    return (
        <span
            className={cn('cf-avatars', className)}
            role="img"
            aria-label={accessibleName}
        >
            {shown.map((person, i) => (
                <Avatar
                    key={`${person.name}-${i}`}
                    name={person.name}
                    initials={person.initials}
                    partner={person.partner}
                    size={size}
                    label={false}
                />
            ))}
            {hidden > 0 && <span className="cf-avatars-more">+{hidden}</span>}
        </span>
    );
}

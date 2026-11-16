import { Ellipsis, Inbox, ScrollText, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import type { MenuEntry } from '@/components/ui/dropdown-menu';
import { audit, health, requests } from '@/routes/vault';
import type { VaultIndexProps } from '@/types/vault';

/**
 * The vault's other pages from its header (part B, D75): health, sharing requests and,
 * for those allowed, the audit log. No board places these, so they share one menu.
 */
export function VaultToolsMenu({
    abilities,
}: {
    abilities: VaultIndexProps['abilities'];
}) {
    const entries: MenuEntry[] = [
        { label: 'Security health', icon: ShieldCheck, href: health().url },
        { label: 'Access requests', icon: Inbox, href: requests().url },
        ...(abilities.audit
            ? [{ label: 'Audit log', icon: ScrollText, href: audit().url }]
            : []),
    ];

    return (
        <DropdownMenu
            aria-label="More vault pages"
            align="end"
            trigger={
                <Button size="lg" icon={Ellipsis} aria-label="More vault pages">
                    More
                </Button>
            }
            entries={entries}
        />
    );
}

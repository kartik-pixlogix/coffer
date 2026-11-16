import { formatInr, toDate } from '@/lib/format';
import type { CostType } from '@/types/team';

export const firstName = (name: string): string => name.split(/\s+/)[0] ?? name;

/** "November 2026" */
export const monthLong = (iso: string): string =>
    toDate(iso).toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });

/** "₹600/h" · "₹80,000/mo" (Team.dc.html cards). */
export const rateLabel = (m: {
    costType: CostType;
    ratePaise: number;
}): string =>
    `${formatInr(m.ratePaise)}${m.costType === 'hourly' ? '/h' : '/mo'}`;

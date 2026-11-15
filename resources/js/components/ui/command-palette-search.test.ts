import { describe, expect, it } from 'vite-plus/test';
import {
    highlightParts,
    normalise,
    scoreItem,
    searchCommands,
    tokenise,
} from './command-palette-search';
import type { SearchableItem } from './command-palette-search';

const groups: { label: string; items: SearchableItem[] }[] = [
    {
        label: 'Pages',
        items: [
            { id: 'dashboard', label: 'Dashboard' },
            { id: 'settings', label: 'Settings · Company' },
            { id: 'settlements', label: 'Settlements' },
            { id: 'reports', label: 'Reports' },
        ],
    },
    {
        label: 'Actions',
        items: [
            {
                id: 'transfer',
                label: 'Record transfer',
                hint: 'Vertex Labs owes ₹3,24,600',
                keywords: ['settlement'],
            },
            { id: 'theme', label: 'Switch theme', keywords: ['dark', 'light'] },
        ],
    },
    {
        label: 'Vault',
        items: [
            {
                id: 'gw',
                label: 'Google Workspace — super admin',
                hint: 'settings',
            },
        ],
    },
];

const ids = (query: string) =>
    searchCommands(groups, query).map((g) => [
        g.label,
        g.items.map((r) => r.item.id),
    ]);

describe('normalise and tokenise', () => {
    it('lower-cases and strips accents', () => {
        expect(normalise('Café ÉTÉ')).toBe('cafe ete');
    });

    it('splits on whitespace and drops empties', () => {
        expect(tokenise('  Set   Comp ')).toEqual(['set', 'comp']);
        expect(tokenise('   ')).toEqual([]);
    });
});

describe('searchCommands', () => {
    it('returns everything in order for an empty query', () => {
        expect(ids('')).toEqual([
            ['Pages', ['dashboard', 'settings', 'settlements', 'reports']],
            ['Actions', ['transfer', 'theme']],
            ['Vault', ['gw']],
        ]);
    });

    it('matches the preview: "sett" finds pages, the transfer and the vault item', () => {
        expect(ids('sett')).toEqual([
            ['Pages', ['settings', 'settlements']],
            ['Actions', ['transfer']],
            ['Vault', ['gw']],
        ]);
    });

    it('ranks a label prefix above a word prefix above a substring', () => {
        const items: SearchableItem[] = [
            { id: 'substring', label: 'Unreported' },
            { id: 'word', label: 'Monthly reports' },
            { id: 'prefix', label: 'Reports' },
        ];

        expect(
            searchCommands([{ label: 'Pages', items }], 'report')[0].items.map(
                (r) => r.item.id,
            ),
        ).toEqual(['prefix', 'word', 'substring']);
    });

    it('ranks an exact label first', () => {
        const items: SearchableItem[] = [
            { id: 'long', label: 'Team timesheets' },
            { id: 'exact', label: 'Team' },
        ];

        expect(
            searchCommands([{ label: 'Pages', items }], 'team')[0].items[0].item
                .id,
        ).toBe('exact');
    });

    it('keeps the given order on ties', () => {
        expect(ids('s').find(([label]) => label === 'Pages')).toEqual([
            'Pages',
            ['settings', 'settlements', 'dashboard', 'reports'],
        ]);
    });

    it('requires every token to match', () => {
        expect(ids('sett comp')).toEqual([['Pages', ['settings']]]);
        expect(ids('sett zzz')).toEqual([]);
    });

    it('drops empty groups and caps items per group', () => {
        const many = Array.from({ length: 12 }, (_, i) => ({
            id: `p${i}`,
            label: `Page ${i}`,
        }));

        expect(
            searchCommands([{ label: 'Pages', items: many }], 'page', 5)[0]
                .items,
        ).toHaveLength(5);
        expect(ids('nothing matches this')).toEqual([]);
    });

    it('finds items by keyword without highlighting the label', () => {
        const [actions] = searchCommands([groups[1]], 'dark');

        expect(actions.items[0].item.id).toBe('theme');
        expect(actions.items[0].ranges).toEqual([]);
    });

    it('is case and accent insensitive', () => {
        expect(ids('DASH')).toEqual([['Pages', ['dashboard']]]);
    });
});

describe('scoreItem ranges', () => {
    it('returns merged label ranges for each token', () => {
        expect(
            scoreItem({ id: 'x', label: 'Settings · Company' }, [
                'sett',
                'comp',
            ])?.ranges,
        ).toEqual([
            [0, 4],
            [11, 15],
        ]);
        expect(
            scoreItem({ id: 'x', label: 'Settlements' }, ['set', 'sett'])
                ?.ranges,
        ).toEqual([[0, 4]]);
    });

    it('returns null when a token matches nothing', () => {
        expect(scoreItem({ id: 'x', label: 'Income' }, ['vault'])).toBeNull();
    });
});

describe('highlightParts', () => {
    it('splits a label around its matches', () => {
        expect(highlightParts('Settlements', [[0, 4]])).toEqual([
            { text: 'Sett', match: true },
            { text: 'lements', match: false },
        ]);
        expect(
            highlightParts('Settings · Company', [
                [0, 4],
                [11, 15],
            ]),
        ).toEqual([
            { text: 'Sett', match: true },
            { text: 'ings · ', match: false },
            { text: 'Comp', match: true },
            { text: 'any', match: false },
        ]);
    });

    it('returns the whole label when nothing matched', () => {
        expect(highlightParts('Reports', [])).toEqual([
            { text: 'Reports', match: false },
        ]);
    });
});

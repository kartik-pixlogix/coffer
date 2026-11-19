/*
 * What the QA sweep visits (docs/QA.md). Add new pages and dialogs here.
 */

/** Demo accounts (DemoWorkspaceSeeder; local seeded data, password "password"). */
export const ROLES = {
    admin: { email: 'aarav@vertexlabs.in', label: 'Admin' },
    partner: { email: 'meera@bluefininfotech.in', label: 'Partner' },
    member: { email: 'rohan@vertexlabs.in', label: 'Member' },
    viewer: { email: 'accounts@ledgerworks.in', label: 'Viewer' },
    guest: { email: null, label: 'Signed out' },
};

/** The owner's widths; the 666 px height pass runs at the laptop widths. */
export const WIDTHS = [320, 375, 768, 1024, 1150, 1440, 1920];
export const SHORT_HEIGHT = 666;
export const SHORT_WIDTHS = [1150, 1440];
export const THEMES = ['light', 'dark'];

export const APP_PAGES = [
    '/dashboard',
    '/partners',
    '/team',
    '/timesheets',
    '/timesheets/import',
    '/income',
    '/expenses',
    '/capital',
    '/loans',
    '/settlements',
    '/reports',
    '/trial-balance',
    '/balance-sheet',
    '/vault',
    '/vault/items/create',
    '/vault/health',
    '/vault/audit',
    '/vault/policies',
    '/vault/requests',
    '/vault/import',
    '/vault/export',
    '/notifications',
    '/profile',
    '/billing',
    '/settings/company',
    '/settings/members',
    '/settings/partners',
    '/settings/sides',
    '/settings/projects',
    '/settings/categories',
    '/settings/roles',
    '/settings/notifications',
    '/companies/create',
    '/qa-missing-page',
];

export const GUEST_PAGES = [
    '/',
    '/pricing',
    '/features/finance',
    '/features/timesheets',
    '/features/vault',
    '/features/reports',
    '/security',
    '/contact',
    '/privacy',
    '/terms',
    '/login',
    '/register',
    '/register?plan=growth',
    '/forgot-password',
];

export const QUICK_PAGES = [
    '/dashboard',
    '/expenses',
    '/settlements',
    '/vault',
    '/',
    '/register',
];
export const QUICK_WIDTHS = [375, 1150];

/*
 * Dialogs, menus and pickers. Each step is one of:
 *   { click: { text | selector, within?, nth?, exact? } }  real mouse click on a visible control
 *   { wait: 'css selector' }                               wait for it to appear
 *   { code: true }                                         type the demo 2FA code into the focused code boxes
 *   { pause: ms }
 *   { scroll: { selector, nth?, left? } }                   scroll a sideways scroller (default: to its end)
 * `root` is what gets measured (default: open dialogs, menus and popovers).
 * `minWidth` / `maxWidth` limit the sizes; `optional: true` skips the scenario quietly when its opener isn't on the page (role-dependent).
 */
const OPEN =
    '[role="dialog"], [role="alertdialog"], [role="menu"], [data-radix-popper-content-wrapper]';
const DIALOG = '[role="dialog"], [role="alertdialog"]';
const DATE = {
    click: {
        selector: `${'[role="dialog"]'} .cf-date-trigger, [role="dialog"] [data-date-trigger], [role="dialog"] button[aria-haspopup="dialog"]`,
    },
};

export const DIALOGS = [
    {
        name: 'Add expense',
        route: '/expenses',
        roles: ['admin', 'member'],
        steps: [{ click: { text: 'Add expense' } }, { wait: DIALOG }],
    },
    {
        name: 'Add expense › date picker',
        route: '/expenses',
        roles: ['admin'],
        steps: [
            { click: { text: 'Add expense' } },
            { wait: DIALOG },
            DATE,
            { pause: 400 },
        ],
    },
    {
        name: 'Add expense › Direct company',
        route: '/expenses',
        roles: ['admin'],
        // The phone sheet (MobileExpenses) has no payer split.
        minWidth: 641,
        steps: [
            { click: { text: 'Add expense' } },
            { wait: DIALOG },
            { click: { text: 'Direct', within: '[role="dialog"]' } },
            { pause: 300 },
        ],
    },
    {
        name: 'Expense receipt drawer',
        route: '/expenses',
        roles: ['admin'],
        steps: [
            {
                click: {
                    selector:
                        'button[aria-label^="Receipt for"], button.ex-mrow',
                },
            },
            { pause: 900 },
        ],
        root: OPEN + ', .cf-drawer',
    },
    {
        name: 'Add income',
        route: '/income',
        roles: ['admin'],
        steps: [{ click: { text: 'Add income' } }, { wait: DIALOG }],
    },
    {
        name: 'Add income › date picker',
        route: '/income',
        roles: ['admin'],
        steps: [
            { click: { text: 'Add income' } },
            { wait: DIALOG },
            DATE,
            { pause: 400 },
        ],
    },
    {
        name: 'Record contribution',
        route: '/capital',
        roles: ['admin'],
        steps: [
            {
                click: {
                    text: {
                        re: '^(record )?contribution|add contribution|^contribution',
                    },
                },
            },
            { wait: DIALOG },
        ],
        optional: true,
    },
    {
        name: 'Record drawing',
        route: '/capital',
        roles: ['admin'],
        steps: [{ click: { text: { re: 'drawing' } } }, { wait: DIALOG }],
        optional: true,
    },
    {
        name: 'New loan',
        route: '/loans',
        roles: ['admin'],
        steps: [
            { click: { text: { re: '^(new|add|record) loan' } } },
            { wait: DIALOG },
        ],
        optional: true,
    },
    {
        name: 'Edit loan',
        route: '/loans',
        roles: ['admin'],
        steps: [
            {
                click: {
                    selector: 'button[aria-label^="More actions for L-"]',
                },
            },
            { pause: 400 },
            { click: { text: 'Edit loan', exact: true } },
            { wait: DIALOG },
        ],
        optional: true,
    },
    {
        name: 'Archive company',
        route: '/settings/company',
        roles: ['admin'],
        steps: [
            { click: { text: 'Archive company', exact: true } },
            { wait: DIALOG },
        ],
        optional: true,
    },
    {
        name: 'Record transfer',
        route: '/settlements',
        roles: ['admin'],
        steps: [{ click: { text: 'Record transfer' } }, { pause: 600 }],
        root: OPEN + ', .cf-drawer',
    },
    {
        name: 'Reports › Month or range',
        route: '/reports',
        roles: ['admin'],
        steps: [
            { click: { selector: 'button[aria-label^="Period"]' } },
            { pause: 500 },
        ],
        optional: true,
    },
    {
        name: 'Invite member',
        route: '/settings/members',
        roles: ['admin'],
        steps: [{ click: { text: 'Invite' } }, { wait: DIALOG }],
    },
    {
        name: 'Add member',
        route: '/team',
        roles: ['admin'],
        steps: [{ click: { text: 'Add member' } }, { wait: DIALOG }],
    },
    {
        name: 'Run salaries',
        route: '/team',
        roles: ['admin'],
        steps: [{ click: { text: 'Run salaries' } }, { wait: DIALOG }],
        optional: true,
    },
    {
        name: 'Log hours',
        route: '/timesheets',
        roles: ['admin', 'member'],
        steps: [{ click: { text: 'Log hours' } }, { wait: DIALOG }],
    },
    {
        // The phone bottom sheet (MobileLogHours): month mode is the longer form, where
        // a shrinking body once squashed the One day / Whole month switch.
        name: 'Log hours › phone sheet, whole month',
        route: '/timesheets',
        roles: ['admin', 'member'],
        maxWidth: 640,
        steps: [
            { click: { text: 'Log hours' } },
            { wait: DIALOG },
            { click: { text: 'Whole month', within: '[role="dialog"]' } },
            { pause: 400 },
        ],
    },
    {
        name: 'Edit partner',
        route: '/settings/partners',
        roles: ['admin'],
        steps: [
            { click: { selector: 'button[aria-label^="Edit "]' } },
            { wait: DIALOG },
        ],
    },
    {
        // Scrolled sideways, the role matrix keeps its labels pinned on the left.
        name: 'Roles matrix › scrolled sideways',
        route: '/settings/roles',
        roles: ['admin'],
        maxWidth: 1100,
        steps: [
            { pause: 300 },
            { scroll: { selector: '.cf-table-wrap', nth: 0 } },
            { scroll: { selector: '.cf-table-wrap', nth: 1 } },
        ],
        root: 'main',
    },
    {
        name: 'Change plan',
        route: '/billing',
        roles: ['admin'],
        steps: [
            { click: { text: { re: 'change plan|choose a plan|upgrade' } } },
            { wait: DIALOG },
        ],
        optional: true,
    },
    {
        name: 'Notifications popover',
        route: '/dashboard',
        roles: ['admin'],
        // Phones open the Notifications page from the compact bar instead.
        minWidth: 641,
        steps: [
            { click: { selector: 'button[aria-label^="Notifications"]' } },
            { pause: 700 },
        ],
    },
    {
        name: 'Account menu',
        route: '/dashboard',
        roles: ['admin'],
        steps: [
            {
                click: {
                    selector: '.cf-topbar button[aria-haspopup="menu"]',
                    nth: 0,
                },
            },
            { pause: 400 },
        ],
        optional: true,
    },
    {
        name: 'Vault item › More menu',
        route: '/vault',
        roles: ['admin'],
        steps: [
            { click: { selector: 'button[aria-label="More vault pages"]' } },
            { pause: 400 },
        ],
        optional: true,
    },
    {
        name: 'Vault share panel',
        route: '/vault',
        roles: ['admin'],
        steps: [
            { click: { selector: '.vh-row, .vh-card' } },
            { pause: 900 },
            { click: { text: 'Share', exact: true } },
            { pause: 700 },
        ],
        root: OPEN + ', .cf-drawer',
        optional: true,
    },
    {
        name: 'Vault add › provider catalog',
        route: '/vault/items/create',
        roles: ['admin'],
        steps: [{ pause: 300 }],
        root: 'main',
    },
    {
        name: 'New company wizard',
        route: '/companies/create',
        roles: ['admin'],
        steps: [{ pause: 300 }],
        root: 'main',
    },
];

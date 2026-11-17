import type { MarketingFeature } from '@/types';

/** One figure row in a section's preview card (`label`, `value`, optional pill). */
export type PreviewRow = {
    label: string;
    value: string;
    sub?: string;
    pill?: { text: string; tone: 'pos' | 'warn' | 'neg' | 'lime' | 'graphite' };
};

export type FeatureSection = {
    title: string;
    body: string;
    points: string[];
    /** A small preview built from cf-cards, like the Landing module tabs. */
    preview: { caption: string; headline?: PreviewRow; rows: PreviewRow[] };
};

export type FeatureContent = {
    /** Title tag and nav label. */
    name: string;
    description: string;
    eyebrow: string;
    title: string;
    titleFaint: string;
    lead: string;
    sections: FeatureSection[];
    /** "Also included" tiles under the sections. */
    extras: { title: string; body: string }[];
};

/**
 * Copy for the feature-detail template (spec §21, D83), written from the product spec
 * (§4, §8–§15) so every claim is something Coffer does. Figures are the demo data's.
 */
export const FEATURES: Record<MarketingFeature, FeatureContent> = {
    finance: {
        name: 'Finance and joint ventures',
        description:
            'Double-entry books for single companies and joint ventures: income, expenses with approvals and split payers, capital accounts, partner loans and JV settlements that always add up.',
        eyebrow: 'Finance',
        title: 'Books that balance,',
        titleFaint: 'for every company and JV.',
        lead: 'Every rupee in Coffer posts a balanced journal entry, so the dashboard, partner shares and settlements all come from the same ledger and always agree.',
        sections: [
            {
                title: 'Income and expenses, with approvals',
                body: 'Record income as it lands and expenses as people spend. Expenses wait for approval before they touch the books, and a partner can’t approve their own.',
                points: [
                    'Split a bill between payers: full, half or custom lines',
                    'Receipts on every expense, and a photo from your phone',
                    'Personal-card payments become reimbursements automatically',
                ],
                preview: {
                    caption: 'Saarthi Portal JV · Apr – Oct',
                    headline: {
                        label: 'Net profit',
                        value: '₹18,42,350',
                        sub: '₹62,10,000 income − ₹43,67,650 expenses',
                    },
                    rows: [
                        {
                            label: 'Figma seats · Software',
                            value: '₹14,500',
                            pill: { text: 'Pending', tone: 'warn' },
                        },
                        {
                            label: 'Client offsite · Travel',
                            value: '₹38,200',
                            sub: 'Split: Aarav and Kabir',
                            pill: { text: 'Approved', tone: 'pos' },
                        },
                    ],
                },
            },
            {
                title: 'Partners, equity and capital',
                body: 'Profit follows equity. Each partner’s capital statement runs Opening + Contributions − Drawings + Share of profit = Closing, per fiscal year.',
                points: [
                    'Equity per partner, checked to add up to 100%',
                    'Contributions, drawings and partner loans with repayments',
                    'Close the fiscal year: capital carries forward and the period locks',
                ],
                preview: {
                    caption: 'Profit due · FY 2026-27',
                    rows: [
                        { label: 'Aarav Mehta · 30%', value: '₹5,52,705' },
                        { label: 'Ishita Rao · 20%', value: '₹3,68,470' },
                        { label: 'Kabir Shah · 25%', value: '₹4,60,588' },
                        { label: 'Meera Iyer · 25%', value: '₹4,60,587' },
                    ],
                },
            },
            {
                title: 'Joint ventures that settle themselves',
                body: 'A JV has sides, one per partner company. Coffer compares what each side holds with what it’s entitled to and tells you who owes whom.',
                points: [
                    'Entitled versus held, per side, for any period',
                    'Record transfers with their bank reference; part payments tracked',
                    'With more than two sides, the fewest transfers that settle everyone',
                ],
                preview: {
                    caption: 'To settle the sides',
                    headline: {
                        label: 'Vertex Labs owes Bluefin Infotech',
                        value: '₹3,24,600',
                    },
                    rows: [
                        {
                            label: 'Vertex Labs holds',
                            value: '₹12,45,775',
                            sub: 'entitled ₹9,21,175',
                            pill: { text: 'Owes', tone: 'warn' },
                        },
                        {
                            label: 'Bluefin Infotech holds',
                            value: '₹5,96,575',
                            sub: 'entitled ₹9,21,175',
                            pill: { text: 'Owed', tone: 'pos' },
                        },
                    ],
                },
            },
        ],
        extras: [
            {
                title: 'Indian formats',
                body: '₹12,34,567 grouping, a true minus for losses, April–March fiscal years.',
            },
            {
                title: 'Closed years stay closed',
                body: 'No one can add, edit or delete an entry dated in a closed fiscal year.',
            },
            {
                title: 'Corrections, not deletions',
                body: 'Editing a posted entry posts a reversal, so the history is always there.',
            },
            {
                title: 'Several money accounts',
                body: 'Company and side accounts, with personal payments tracked per partner.',
            },
        ],
    },
    timesheets: {
        name: 'Timesheets and salary',
        description:
            'Log hours by day or by month, import timesheets from spreadsheets, and turn hours and fixed salaries into salary cost per partner automatically.',
        eyebrow: 'Timesheets',
        title: 'Hours in,',
        titleFaint: 'salary cost out.',
        lead: 'People log hours by the day or as a month’s total. At month-end Coffer turns hours and fixed salaries into salary expenses, charged to the right partner.',
        sections: [
            {
                title: 'Day or month entry',
                body: 'Log a day’s hours, or enter a month’s total and let Coffer spread it over the working days, to the hundredth of an hour.',
                points: [
                    'Saturdays on or off, with a live preview of hours per day',
                    'Billable or not, per project, with notes',
                    'Members see and log only their own hours',
                ],
                preview: {
                    caption: 'Rohan Desai · November 2026',
                    headline: {
                        label: 'Total hours · Month mode',
                        value: '57.33 h',
                        sub: '21 working days → 2.73 hrs/day · 25 days with Saturdays',
                    },
                    rows: [
                        {
                            label: 'Saarthi portal',
                            value: '41.00 h',
                            pill: { text: 'Billable', tone: 'lime' },
                        },
                        { label: 'Internal', value: '16.33 h' },
                    ],
                },
            },
            {
                title: 'Bulk import from spreadsheets',
                body: 'Upload a CSV, map the columns, and fix any problem rows before anything is saved.',
                points: [
                    'Common column names detected for you',
                    'Dates like 2026-04-01, 01/04/2026 or 1-Apr-26; hours like 2.5 or 2:30',
                    'Unknown people, duplicates and closed-year dates are flagged',
                ],
                preview: {
                    caption: 'timesheet-october.csv',
                    rows: [
                        { label: 'Rows in file', value: '412' },
                        {
                            label: 'Ready to import',
                            value: '405',
                            pill: { text: 'OK', tone: 'pos' },
                        },
                        {
                            label: 'Need a fix',
                            value: '7',
                            pill: { text: 'Fix', tone: 'warn' },
                        },
                        { label: 'Hours to add', value: '1,318.5 h' },
                    ],
                },
            },
            {
                title: 'Salary, worked out every month',
                body: 'Hourly people cost their billable hours times their rate; fixed salaries cost the month. Each becomes an approved expense, paid from their partner’s account.',
                points: [
                    'Runs on its own for the previous month, or on demand',
                    'Re-runs after an hours change correct the expense',
                    'Hours feed each partner’s share of salary cost',
                ],
                preview: {
                    caption: 'Salary run · October 2026',
                    rows: [
                        {
                            label: 'Rohan Desai · ₹500/hr',
                            value: '₹80,000',
                            sub: '160.0 h',
                        },
                        {
                            label: 'Sana Qureshi · ₹450/hr',
                            value: '₹66,825',
                            sub: '148.5 h',
                        },
                        { label: 'Dev Patel · fixed', value: '₹40,000' },
                    ],
                },
            },
        ],
        extras: [
            {
                title: 'Team summary',
                body: 'Hours and earnings per person for the month.',
            },
            {
                title: 'Projects',
                body: 'A list per company, managed in Settings.',
            },
            {
                title: 'Warnings, not walls',
                body: 'More than 12 hours in a day asks before saving.',
            },
            {
                title: 'Roles decide',
                body: 'Who can edit or delete whose hours follows the role matrix.',
            },
        ],
    },
    vault: {
        name: 'Credentials vault',
        description:
            'An encrypted vault for the logins your team shares: per-company envelope encryption, 2FA step-up to reveal, one-time codes, sharing with end dates, health score and an audit log.',
        eyebrow: 'Credentials vault',
        title: 'Shared logins,',
        titleFaint: 'kept next to the company.',
        lead: 'Hosting panels, API keys, SSH keys and the team Gmail live in each company’s vault, encrypted, shared with the right people and logged every time they’re used.',
        sections: [
            {
                title: 'Encrypted, and asked for twice',
                body: 'Each company has its own data key, sealed with a master key; secrets are encrypted with AES-256-GCM. Lists never carry a secret: revealing one is a separate request that needs a recent 2FA code.',
                points: [
                    'Reveals hide again after 30 seconds',
                    'Copies clear from the clipboard after the policy time',
                    'The vault locks itself when you step away',
                ],
                preview: {
                    caption: 'Hostinger hPanel · Asha Retail',
                    rows: [
                        { label: 'Username', value: 'admin@asharetail.in' },
                        {
                            label: 'Password',
                            value: '••••••••••••',
                            pill: {
                                text: 'Reveal needs 2FA',
                                tone: 'graphite',
                            },
                        },
                        { label: 'Environment', value: 'Production' },
                    ],
                },
            },
            {
                title: 'One-time codes built in',
                body: 'Store the 2FA setup key with the login and Coffer shows the current code with a countdown, so nobody needs the one phone that has it.',
                points: [
                    'The server works out the code; the key never leaves it',
                    'Using a code is recorded in the audit log',
                    'Works with any standard authenticator setup key',
                ],
                preview: {
                    caption: 'GitHub — vertexlabs org',
                    headline: {
                        label: 'One-time code',
                        value: '492 039',
                        sub: 'Refreshes in 18 s',
                    },
                    rows: [],
                },
            },
            {
                title: 'Health score and an audit log',
                body: 'A score out of 100 flags weak, reused, old, breached and expiring passwords, with a fix-it list. Every view, reveal, copy, edit and share is logged.',
                points: [
                    'Reuse is spotted without comparing passwords in plain text',
                    'Optional breach checks that never send a password',
                    'Expiry alerts at 30, 7 and 1 days',
                ],
                preview: {
                    caption: 'Vault health',
                    rows: [
                        {
                            label: 'GitHub — vertexlabs org',
                            value: 'Strong · 2FA on',
                            pill: { text: 'Healthy', tone: 'pos' },
                        },
                        {
                            label: 'MySQL — production',
                            value: 'Changed 220 days ago',
                            pill: { text: 'Rotate', tone: 'warn' },
                        },
                        {
                            label: 'Gmail — support inbox',
                            value: 'Seen in a breach list',
                            pill: { text: 'Breached', tone: 'neg' },
                        },
                        {
                            label: 'Vercel — team token',
                            value: 'Expires in 9 days',
                            pill: { text: 'Renew', tone: 'warn' },
                        },
                    ],
                },
            },
        ],
        extras: [
            {
                title: 'Sharing with end dates',
                body: 'View, Use, Edit or Manage, for a person, until a date.',
            },
            {
                title: 'Access requests',
                body: 'Ask with a reason; managers approve from their inbox.',
            },
            {
                title: 'Import and export',
                body: 'From Bitwarden, 1Password, LastPass, Chrome or CSV.',
            },
            {
                title: 'Offboarding',
                body: 'When someone leaves, see what they could use and rotate it.',
            },
        ],
    },
    reports: {
        name: 'Reports and statements',
        description:
            'P&L by month and by partner, trial balance and balance sheet computed from the ledger, with PDF and Excel export and fiscal-year close.',
        eyebrow: 'Reports',
        title: 'Month-end,',
        titleFaint: 'already done.',
        lead: 'Reports come straight from the journal, so they’re ready the moment the last entry is in, and the trial balance and balance sheet always balance.',
        sections: [
            {
                title: 'Profit and loss, by month and by partner',
                body: 'Pick a fiscal year, a quarter, a month or your own dates. See revenue, expenses, salary and profit per month, and what each partner generated and is due.',
                points: [
                    'Profit margin, expense ratio and monthly averages',
                    'Partner P&L with each share of profit',
                    'Export to PDF or Excel; big periods are prepared in the background',
                ],
                preview: {
                    caption: 'FY 2026-27 · Apr – Oct',
                    headline: {
                        label: 'Net profit',
                        value: '₹18,42,350',
                        sub: 'Margin 29.7%',
                    },
                    rows: [
                        { label: 'Revenue', value: '₹62,10,000' },
                        { label: 'Expenses', value: '₹43,67,650' },
                    ],
                },
            },
            {
                title: 'Trial balance and balance sheet',
                body: 'Every account’s debits and credits for the period, and a balance sheet as of any date, with a banner that says they balance.',
                points: [
                    'Loans, reimbursements and capital per partner',
                    'Money accounts and partner balances',
                    'A nightly integrity check alerts Admins if anything drifts',
                ],
                preview: {
                    caption: 'Trial balance',
                    rows: [
                        {
                            label: 'Status',
                            value: 'Debits equal credits',
                            pill: { text: 'Balanced', tone: 'pos' },
                        },
                        { label: 'Accounts', value: '24' },
                    ],
                },
            },
            {
                title: 'Close the year in one step',
                body: 'Closing a fiscal year posts the closing entries, carries capital forward and locks the period, so last year’s numbers stay last year’s numbers.',
                points: [
                    'April to March by default; the start month is yours to set',
                    'Capital statements per partner for each year',
                    'Statements your accountant can open as a Viewer',
                ],
                preview: {
                    caption: 'FY 2025-26',
                    rows: [
                        {
                            label: 'Closing entries',
                            value: 'Posted',
                            pill: { text: 'Closed', tone: 'graphite' },
                        },
                        {
                            label: 'Capital carried forward',
                            value: '₹41,20,000',
                        },
                    ],
                },
            },
        ],
        extras: [
            {
                title: 'One company at a time',
                body: 'Reports never mix companies; switch from the top bar.',
            },
            {
                title: 'Viewer access',
                body: 'Accountants see reports and statements, nothing in the vault.',
            },
            {
                title: 'Any period',
                body: 'A fiscal year, a quarter, a month or your own dates.',
            },
            {
                title: 'PDF and Excel',
                body: 'Every report exports for your accountant in one click.',
            },
        ],
    },
};

export const FEATURE_ORDER: MarketingFeature[] = [
    'finance',
    'timesheets',
    'vault',
    'reports',
];

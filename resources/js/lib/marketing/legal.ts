import type { LegalPageKey } from '@/types';

export type LegalSection = {
    heading: string;
    paragraphs: string[];
    list?: string[];
};

export type LegalDoc = {
    title: string;
    description: string;
    intro: string;
    sections: LegalSection[];
};

/**
 * PLACEHOLDER legal copy (spec §21/§23, D84). Every page shows "Draft — to be reviewed by
 * counsel" and must be replaced by counsel-approved text before launch [VERIFY].
 */
export const LEGAL: Record<LegalPageKey, LegalDoc> = {
    terms: {
        title: 'Terms of Service',
        description:
            'Draft terms for using Coffer, the multi-company finance and operations service. To be reviewed by counsel.',
        intro: 'These terms cover your use of Coffer. By creating a workspace or using the service you agree to them on behalf of yourself and the organisation you represent.',
        sections: [
            {
                heading: 'The service',
                paragraphs: [
                    'Coffer provides bookkeeping, timesheets, partner equity, joint-venture settlements, reports and an encrypted credentials vault for the companies in your workspace.',
                    'Coffer is a record-keeping tool. It does not give accounting, tax or legal advice, and it does not move money on your behalf.',
                ],
            },
            {
                heading: 'Accounts and workspaces',
                paragraphs: [
                    'The person who creates a workspace is its owner. Owners and Admins decide who can access each company and with which role, and are responsible for the people they invite.',
                    'Keep your password and two-factor device safe. Tell us at once if you think your account has been misused.',
                ],
            },
            {
                heading: 'Plans, trials and payment',
                paragraphs: [
                    'New workspaces start with a free trial. After the trial a paid plan is needed to keep making changes; your data stays viewable.',
                    'Prices are shown on the Pricing page. Rupee prices are before GST. Plans renew automatically until cancelled from Billing.',
                ],
            },
            {
                heading: 'Your data',
                paragraphs: [
                    'You own the data you put into Coffer. We process it only to provide the service, as described in the Privacy Policy and, where it applies, the Data Processing Agreement.',
                    'We handle personal data in line with India’s Digital Personal Data Protection Act, 2023 (DPDPA).',
                ],
            },
            {
                heading: 'Acceptable use',
                paragraphs: ['You agree not to:'],
                list: [
                    'store content you have no right to store, or use Coffer for anything unlawful',
                    'try to get around security controls, rate limits or plan limits',
                    'probe, scan or load-test the service without our written permission',
                ],
            },
            {
                heading: 'Liability, changes and ending',
                paragraphs: [
                    '[Limitation of liability, warranty disclaimer, governing law (India) and dispute resolution to be drafted by counsel.]',
                    'We may update these terms; we will tell workspace owners about material changes in advance. You can stop using Coffer at any time.',
                ],
            },
        ],
    },
    privacy: {
        title: 'Privacy Policy',
        description:
            'Draft privacy policy: what personal data Coffer collects, why, how long it is kept and your rights under India’s DPDPA. To be reviewed by counsel.',
        intro: 'This policy explains what personal data Coffer handles, why, and the choices you have. It is written with India’s Digital Personal Data Protection Act, 2023 (DPDPA) in mind.',
        sections: [
            {
                heading: 'Who we are',
                paragraphs: [
                    '[Legal entity name, registered address and Grievance Officer contact to be added by counsel.]',
                    'For the data your workspace puts into Coffer, your organisation is the Data Fiduciary and Coffer acts as its Data Processor. For our own website, sign-up and billing, Coffer is the Data Fiduciary.',
                ],
            },
            {
                heading: 'What we collect',
                paragraphs: ['Depending on how you use Coffer:'],
                list: [
                    'account details: name, email, password hash, two-factor settings, theme and time zone',
                    'workspace data: companies, partners, team, hours, income, expenses and the records you add',
                    'vault items, stored encrypted; we cannot read secret fields in normal operation',
                    'billing details handled by our payment provider (we store the plan, invoices and the last four card digits)',
                    'technical data: IP address, device and browser, and security events such as sign-ins and vault reveals',
                    'what you send through the contact form',
                ],
            },
            {
                heading: 'Why we use it',
                paragraphs: [
                    'To provide and secure the service, to bill for it, to answer you, and to meet legal obligations. We do not sell personal data and we do not use workspace data for advertising.',
                ],
            },
            {
                heading: 'How long we keep it',
                paragraphs: [
                    'Workspace data is kept while the workspace exists. When a workspace owner deletes it, or you delete your account, it is kept for 30 days in case of mistakes, then erased. Accounting records may need to be kept longer by law: entries you posted stay in the books under “Deleted user”, without your name or email.',
                ],
            },
            {
                heading: 'Your rights',
                paragraphs: [
                    'Under the DPDPA you can ask to access, correct or erase your personal data, withdraw consent, nominate someone to act for you, and raise a grievance. You can download a copy of your data and delete your account yourself from Account › Profile (Your data); for anything else, contact your workspace Admin, or us through the contact page.',
                ],
            },
            {
                heading: 'Security and transfers',
                paragraphs: [
                    'See the Security page for how data is protected. [Hosting region, sub-processors and cross-border transfer terms to be listed by counsel.]',
                ],
            },
        ],
    },
    cookies: {
        title: 'Cookie Policy',
        description:
            'Draft cookie policy: the few cookies Coffer uses to sign you in, protect forms and remember your theme. To be reviewed by counsel.',
        intro: 'Coffer uses a small number of cookies, all needed for the service to work. We don’t use advertising or cross-site tracking cookies.',
        sections: [
            {
                heading: 'Cookies we set',
                paragraphs: ['These are first-party cookies:'],
                list: [
                    'session: keeps you signed in for the length of your visit',
                    'XSRF-TOKEN: protects forms against cross-site request forgery',
                    'remember-me and trusted device: only if you choose to stay signed in or skip 2FA on a device',
                    'theme: remembers light or dark mode',
                ],
            },
            {
                heading: 'Third parties',
                paragraphs: [
                    'When you pay, our payment provider’s checkout page sets its own cookies under its own policy. [Any analytics added later must be listed here and, where required under the DPDPA, consented to.]',
                ],
            },
            {
                heading: 'Your choices',
                paragraphs: [
                    'You can clear or block cookies in your browser. Blocking the session or XSRF cookies will stop sign-in from working.',
                ],
            },
        ],
    },
    dpa: {
        title: 'Data Processing Agreement',
        description:
            'Draft Data Processing Agreement between a Coffer workspace (Data Fiduciary) and Coffer (Data Processor) under India’s DPDPA. To be reviewed by counsel.',
        intro: 'This agreement applies when your organisation uses Coffer to process personal data, such as your team’s and partners’ details. Your organisation is the Data Fiduciary; Coffer is its Data Processor under India’s Digital Personal Data Protection Act, 2023 (DPDPA).',
        sections: [
            {
                heading: 'Scope and instructions',
                paragraphs: [
                    'Coffer processes workspace data only to provide the service and on your documented instructions, which are these terms, your settings and your use of the product.',
                ],
            },
            {
                heading: 'Security measures',
                paragraphs: [
                    'Coffer maintains technical and organisational measures including:',
                ],
                list: [
                    'per-company envelope encryption (AES-256-GCM) for vault secrets',
                    'role-based access per company, two-factor sign-in for Admins and vault users',
                    'append-only audit logs for vault access',
                    'restricted staff access [backup and hosting measures to be described by counsel]',
                ],
            },
            {
                heading: 'Personal data breaches',
                paragraphs: [
                    'Coffer will tell the workspace owner without undue delay after becoming aware of a personal data breach affecting workspace data, so you can meet your duties to the Data Protection Board of India and to the people affected. [Notice period to be set by counsel.]',
                ],
            },
            {
                heading: 'Sub-processors',
                paragraphs: [
                    '[List of sub-processors (hosting, email delivery, payments), their locations and the notice given before changes, to be completed by counsel.]',
                ],
            },
            {
                heading: 'Return and deletion',
                paragraphs: [
                    'When the workspace ends, you can export your data. Coffer then deletes it within 30 days, except where the law requires otherwise.',
                ],
            },
        ],
    },
};

export const LEGAL_ORDER: LegalPageKey[] = [
    'terms',
    'privacy',
    'cookies',
    'dpa',
];

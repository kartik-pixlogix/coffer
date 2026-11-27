# Coffer: build plan

Work through the phases in order. For each phase:

1. Use plan mode.
2. Read the files under "Read first".
3. Propose a short plan.
4. Build.
5. Check every acceptance box.
6. Commit.

Tick boxes here as you go (`- [x]`).

---

## Phase 0: Project setup and app shell

**Read first:**
- `CLAUDE.md`
- `design/design-system/README.md` and `tokens.json`
- the `components/NavRail` and `components/CompanySwitcher` READMEs and previews
- `design/screens/Main.dc.html` (the shell only)

- [x] Laravel app from the official React starter kit (Inertia + React + TS) with Pest. MySQL connection (D3; migrations portable to PostgreSQL). Redis optional locally.
- [x] Tooling: Pint, ESLint + Prettier (Vite+ `vp check`, D15), `tsc --noEmit` script, a GitHub Actions CI running tests, lint, types, `composer audit` and `npm audit`.
- [x] Design foundation:
  - copy `bundle.css` to `resources/css/coffer-ui.css`, imported in `app.css`
  - Tailwind v4 `@theme` mapped to the token CSS variables
  - `@fontsource` fonts (Bricolage Grotesque variable, Plus Jakarta Sans, IBM Plex Mono)
  - root `class="cf" data-theme`
- [x] Logos and favicons from `design/design-system/assets/logos/` in `public/`.
- [x] Theme toggle: light/dark per user (DB column + localStorage), no flash of the wrong theme on load.
- [x] Authenticated layout matching `Main.dc.html`:
  - floating shell on the canvas, NavRail (logo disc, grouped icons with tooltips, sign-out at the bottom)
  - top bar: wordmark, company switcher (placeholder data), ⌘K search (stub), date, theme toggle, notifications bell (stub), account menu
  - breadcrumb plus the two-tone page title component
- [x] `docs/DECISIONS.md` exists. Every remaining `[VERIFY]` decision is listed in it.

**Accept:**
- `composer run dev` serves an empty dashboard that looks like the shell of `Main.dc.html` in light and dark.
- CI is green. (Workflow written and every step passes locally; needs a GitHub remote to run.)

## Phase 1: UI kit (React components for the design system)

**Read first:** every `design/design-system/components/<Name>/README.md` + `preview.html`, and `bundle.css`.

- [x] One component per design-system component in `resources/js/components/ui/` (54 including Cover):
  - **Feedback and overlays:** AlertBanner, Breadcrumb, BulkActionBar, ConfirmDialog (type-to-confirm), Drawer, EmptyState, ErrorState, Modal, Skeleton, Toast (+ provider), Tooltip.
  - **Forms:** AmountInput (₹, Indian grouping as you type, paise in/out), Checkbox, CodeInput (6-digit), Combobox, DatePicker, FileDrop, MonthPicker, PasswordField (reveal/copy), Radio, SearchField (⌘K hint), SegmentedControl, Select, TextInput, Textarea, Toggle.
  - **Actions and navigation:** Button (primary with disc, secondary, ghost, danger, lime, glass; lg/md/sm; loading), ButtonGroup, CommandPalette, CompanySwitcher, DropdownMenu, FilterChips, IconButton, NavRail, NotificationPanel, Pagination, Popover, Stepper, Tabs.
  - **Data and charts:** AreaChart, Avatar (+ partner dot, tile), AvatarStack, BarChart (negatives), ChartTooltip, DataTable (sort, row actions, selection, sticky header, empty and loading rows), DonutChart, GroupedBars, HealthRing, KpiTile (count-up), ProgressBar, StatusPill, TotpRing, TrendChip.
- [x] `<Money paise>` and `<Hours>` display components. Shared formatters in `resources/js/lib/format.ts`, with unit tests (Vitest).
- [x] Use Radix primitives for focus management where needed (Dialog, Popover, DropdownMenu, Tooltip, Tabs), styled with `cf-*` classes only.
- [x] `/dev/ui` gallery (local only) showing every component and state in light and dark.

**Accept:**
- Side by side with each `preview.html`, the gallery matches: markup classes, states, light and dark.
- Keyboard and screen reader labels work.
- Reduced motion is respected.

## Phase 2: Auth, tenancy and permissions

**Read first:**
- spec §2, §3, §16, §17, §18
- screens: `SignIn`, `SignUp`, `AcceptInvite`, `CreateCompanySingle`, `CreateCompanyJV`, `CreateCompanySteps`, `SettingsMembers`, `SettingsPartners`, `StateDenied`, `StateSession`, `Profile`

- [x] Auth pages restyled to the designs:
  - sign in (+ 2FA challenge, error and lockout copy)
  - sign up, forgot and reset password, verify email
  - Google sign-in (Socialite)
  - 2FA setup with recovery codes, trusted devices
- [x] Models and tables:
  - Workspace, Company (type single/jv, FY start, currency, attribution mode)
  - Side, Partner (colour, role, equity), `company_user` with role
  - spatie/permission teams (team = company)
  - default page and action permissions seeded per company
- [x] Current-company middleware plus the switcher. `BelongsToCompany` scope. Policies on every route. A cross-company access test.
- [x] Create-company wizard:
  - Single and JV step 1 with live validation (100% totals, even split)
  - Team step, Review & launch (single transaction)
  - "Save and exit" draft
- [x] Invites by email or share link (role, link to partner/member, expiry), the accept page (valid and expired states), revoke and resend.
- [x] Settings › Members & invites, and Roles & permissions (editable matrices, reset).
- [x] Settings › Partners & equity (edit partner modal, colours, archive) and Partner sides (JV totals), from `SettingsPartners`.
- [x] Profile and security: profile fields, number format, password change (signs out others), sessions list with sign-out.
- [x] Session-expired re-auth dialog. "Permission denied" page.
- [x] Demo seeders: workspace, companies, sides, partners, users.

**Accept:**
- Each role sees exactly the pages and actions in spec §2 (feature tests per role).
- Wizard validation matches the designs.
- Invites work end to end.

## Phase 3: Ledger core

**Read first:** spec §4 in full.

- [x] Chart-of-accounts generator per company, and money accounts (company account, per-side accounts).
- [x] `journal_entries` and `journal_lines` with dimensions. `LedgerPoster` (post, reverse) with a balance guard. FY periods and lock dates.
- [x] Opening-balance posting (from the wizard and Settings).
- [x] Profit allocation service (equity and per-partner modes, largest-remainder rounding).
- [x] Settlement calculator (spec §13).
- [x] Report query services: P&L by month/category, trial balance, balance sheet, capital statement.
- [x] Integrity job (nightly) plus admin alert. Invariant tests 1–6 from spec §4.5, including property-style tests with random postings.

**Accept:**
- All invariant tests pass for both Single and JV fixtures.
- Seeded demo books produce figures close to the design numbers.

## Phase 4: Team and timesheets

**Read first:**
- spec §6, §7, §8
- screens: `Partners`, `Team`, `Timesheets`, `BulkImport`, `MobileLogHours`

- [x] Partners page (Single grid, JV grouped by side, charts, detailed table). (D39)
- [x] Team: list with partner tabs, add/edit modal (cost type switch, rate history), deactivate. Member profile page (derived). (D37)
- [x] Projects (Settings › Projects).
- [x] Timesheets:
  - KPIs, team summary, filters, pagination, edit and delete
  - Log hours modal: Day mode, and Month mode with Saturdays preview and exact distribution (D35, D36)
- [x] Bulk import:
  - upload, alias mapping, preview with row errors
  - queued commit, error CSV
  - manual monthly entries tab (D40)
- [x] Salary generation job plus "Run now" (idempotent, reverse and re-post on change). (D38)

**Accept:**
- Hours distribution sums exactly. (Property tests in PHP and Vitest, every month of FY 2026-27, with and without Saturdays.)
- Imports handle every format in spec §8.2.
- Salary expenses post correctly. (Seeded books still balance and hit the design figures; the integrity check passes.)
- Pages match the designs, including mobile Log hours. (Checked in Chrome, light and dark, desktop and 390 px; D41.)

## Phase 5: Finance

**Read first:**
- spec §9–§13
- screens: `Income`, `Expenses`, `MobileExpenses`, `CapitalAccounts`, `PartnerLoans`, `Settlements`, `Notifications`

- [x] Income: CRUD with reversals, attachment, KPIs, trend, sorting. (D42, D48)
- [x] Expenses (D43–D48), plus Settings › Categories (D49):
  - Add/edit modal: Full / Half / Custom payers with live total check, paid-from including personal card, receipt upload, inline new category, bill to client
  - Approvals with reject reason, bulk approve/reject, filters, search, export
  - Receipt drawer, Auto rows read-only
- [x] Reimbursement payouts. Convert a personal-card balance into a loan. (D50, D51)
- [x] Capital accounts: statement with FY selector, contribution and drawing modals. (D53, D55)
- [x] Partner loans: record a loan, record a repayment, progress, auto-close. (D51, D52)
- [x] Settlements: per-side table, banner (two sides, or minimal transfers for N sides), record/edit/delete transfer, non-JV empty state. (D54, D55)
- [x] Demo books through the app's Actions (D57); success toasts show once (D56).

**Accept:**
- Every action posts the right journal lines (tests per posting rule).
- Settlements sum to zero.
- Pages match the designs, including the mobile expense sheet.

## Phase 6: Dashboard, reports and statements

**Read first:**
- spec §5, §15, §4.6
- screens: `Main`, `Reports`, `Statements`, `SettingsCompany`, `StateEmpty`, `StateLoading`

- [x] Dashboard with real data (D58, D59, D64):
  - period control, KPIs with trends, balance hero with formula strip
  - charts, pending approvals, expiring credentials
  - empty and loading states
- [x] Reports: period presets, KPIs, ratios, monthly table, partner P&L, PDF and Excel export (queued when large). (D59, D61)
- [x] Trial balance and Balance sheet with status banners and "Find the drift". (D60)
- [x] Settings › Company (all fields), and FY close (preview, type-to-confirm, lock, reopen). (D63, D65)
- [x] Cache report aggregates. Invalidate on posting. (D62)

**Accept:**
- Figures reconcile across Dashboard, Reports, TB, BS and Capital for the same period.
- Exports open correctly.
- A closed FY blocks edits.

## Phase 7: Credentials Vault

**Read first:**
- spec §14 in full
- screens: `Vault`, `VaultAddCatalog`, `VaultAddLogin`, `VaultShare`, `VaultHealth`, `VaultAudit`, `VaultLockPolicies`, `MobileVault`

- [x] `VaultCrypto` service (D66):
  - per-company data keys wrapped by `VAULT_MASTER_KEY`, AES-256-GCM
  - key rotation command
  - HMAC fingerprints
  - unit tests (round-trip, tamper detection, wrong key)
- [x] Vaults, items (all types and custom fields), attachments (encrypted), versions with restore, favourites, tags, environments, expiry (D67, D68).
- [x] Provider catalog seed (monogram tiles). Add item step 1 (catalog) and step 2 (template form plus generator popover with zxcvbn) (D67, D70).
- [x] Vault home: rail with vaults and smart filters, list/grid toggle, search, sort, item drawer (D68, D70).
- [x] Reveal, copy and TOTP endpoints (D69):
  - step-up 2FA window, rate limits, audit events
  - auto-hide after 30 s and clipboard clear on the client
  - idle lock after 15 min with the unlock screen
- [x] Sharing (View/Use/Edit/Manage, access ends), access requests inbox, offboarding rotate list (D72, D73).
- [x] Security health score, fix-it lists with Rotate. Expiry notifications. Optional HIBP k-anonymity check (queued) (D74).
- [x] Audit log page with filters and export. Policies page (D75, D76).
- [x] Import (CSV / Bitwarden / 1Password / LastPass / Chrome) and encrypted export (Admin, step-up, audited) (D77).

**Accept:**
- A test proves no secret appears in any index response, log, activity diff or Inertia shared prop.
- Every reveal, copy and code use is audited.
- Permissions are enforced per item.

## Phase 8: SaaS: billing, notifications, marketing, super-admin

**Read first:**
- spec §19–§22
- screens: `Billing`, `Pricing`, `Landing`, `Notifications`, `StateToasts`

- [x] Notifications: events, database + mail channels, top-bar panel with filters and inline actions, `/notifications` page, preferences, brand email template. (D78, D79)
- [x] Plans table (placeholder prices and limits), 14-day trial, Cashier (Stripe) Checkout + Billing Portal. (D80; owner checklist in `docs/BILLING_TESTING.md`; Razorpay deferred [VERIFY])
- [x] Billing page: plan, usage meters, invoices, upgrade modal. Soft limit enforcement. (D80, D81, D82)
- [x] Marketing site with Inertia SSR (D83, D84; SSR proof in `docs/DEPLOY.md` §3):
  - landing (spotlight and parallax with reduced-motion fallback), pricing toggles
  - owner feedback: 3D hero tilt, depth-layered parallax cards, scroll reveals and hover lift on every marketing page, placeholder client strip, plan features on the landing, animated FAQ, Log hours card fixed (D103, D104 [VERIFY], D105)
  - feature template, security, contact/demo form, legal pages
  - SEO: meta, OG, sitemap
- [x] Filament super-admin at `/admin`: workspaces, usage, suspend, impersonate with reason and audit, plans editor, platform metrics. (D85, D86, D87; panel MFA [VERIFY])

**Accept:**
- A trial converts to paid in Stripe test mode.
- Limits trigger the upgrade modal.
- Marketing pages render server-side.
- Platform admin is isolated from tenants.

## Phase 9: Hardening and launch

- [x] Responsive pass at 1024 px, and against every mobile board: `MobileDashboard`, `MobileExpenses`, `MobileVault`, `MobileLogHours`, `MobileNav`. (D88)
- [x] All states: `StateEmpty`, `StateLoading`, `State404` (+ a 500 page derived from it), `StateDenied`, `StateSession`, `StateToasts`. (D89, D90)
- [x] Accessibility audit (axe plus keyboard pass). (D91)
- [x] Performance pass (indexes, N+1, caching). (D92)
- [x] Security review: headers/CSP, rate limits, authorisation tests, secret-leak test, dependency audit. (D93, D94)
- [x] Backups, queue workers (Horizon), scheduler, error tracking, health check. (D95, D96, D97)
- [x] Deployment runbook for a VPS (Forge/Ploi or Docker). `.env.example` complete. Data export and delete for DPDPA. (D98, D99)

**Accept:** a production checklist in `docs/DEPLOY.md`, all green. (The checklist is written; its boxes are ticked on the production server at launch, D99.)

---

# v2: sellable to Indian agencies and their CAs

Approved scope (owner, 20 Nov 2026): the "v2 core" list, items 1, 2, 3, 4, 5, 6, 7, 11 and 17.
v2.1 and later items are listed at the end and are not started without a new go-ahead.

**Ground rules for v2**
- Build and test locally; release to the Laravel Cloud staging environment only. Production on
  Hostinger stays on v1 until v2 is signed off (it already has the brand-colours picker, D147).
- These modules have **no `.dc.html` screens**. Each page is built from the design system's
  components and patterns (as earlier derived pages were), recorded as a decision, and shown to
  the owner as screenshots (light and dark, desktop and phone) before the phase closes. If the
  owner wants designed screens first, the phase pauses for them.
- Money stays integer paise through `LedgerPoster`; every new money event posts a balanced entry,
  and the ledger invariants (TB, BS, P&L shares, settlements) keep passing.
- Every new route gets authorisation per role, validation and happy-path tests; the route sweep
  (`RouteAuthorizationSweepTest`) stays green.
- Each phase starts in plan mode with its "Owner inputs" answered.

## Phase 10: Security hardening

**Read first:** spec §14, §17, §23; `docs/DEPLOY.md` §6–§8 and §12; D66, D93–D97, D146.

**Owner inputs:** key service for Laravel Cloud (AWS KMS or Google Cloud KMS); where off-site
backups go (S3-compatible bucket and region, India preferred); budget for an external pentest.

- [~] Master-key driver: `env` (today) or a KMS (`aws-kms` / `gcp-kms`). Company data keys stay
      wrapped; unwrapping calls the KMS and caches the data key for the request only. Rotation
      (`coffer:vault-rotate`) works with either driver. **Done:** the `MasterKey` driver seam and the
      `env` driver (D152). **Waiting on the owner:** which KMS (AWS or Google), then its SDK and keys.
- [x] Field-level encryption with the same envelope scheme for sensitive business data: GSTIN, PAN,
      bank details, addresses, partner and member contact details, free-text notes. Fields that
      must be searched or deduplicated get an HMAC blind index. (D149)
- [x] Receipts and attachments encrypted at rest with the company's data key, streamed through the app. (D150)
- [x] Posted ledger rows can't be changed in the database: triggers (MySQL and PostgreSQL variants)
      refuse `UPDATE`/`DELETE` on `journal_entries` and `journal_lines` except the reversal links,
      and the runtime DB user has no `DELETE` on them. (D148: triggers done; the `DELETE` revoke is
      not done, since erasure needs it and the trigger already refuses deletes.)
- [~] 2FA or a passkey required for every user with vault access, not only Admins; passkeys turned
      on (`laravel/passkeys` is installed). **Done already:** the vault refuses anyone without 2FA,
      whatever their role (EnsureVaultUnlocked). **Waiting on the owner:** passkeys need a browser
      package (`@simplewebauthn/browser` or `@laravel/passkeys`), so they wait for approval.
- [~] Encrypted off-site backups on a schedule, plus a restore-drill command. **Done:** PHP dumper
      (works on Hostinger), row counts in the manifest, `coffer:backup-drill` monthly (D150).
      **Waiting on the owner:** the off-site bucket (S3-compatible, India region).
- [x] Public security page (encryption, hosting region, access controls, disclosure contact) and
      `/.well-known/security.txt`. (D151; hosting region is left off until production's region is final.)

**Accept:**
- With the KMS driver, no plaintext master key is in `.env` or the database.
- A test proves an `UPDATE` on a posted journal line fails at the database.
- Database dumps show no plaintext GSTIN, PAN, bank details or notes.
- Off-site encrypted backups restore successfully.
- Pentest findings are triaged; every high and critical one is fixed.

## Phase 11: Audit trail and accountant-grade books

**Read first:** spec §4–§6 and §15; the owner's "Accounting Architecture & Audit Checklist" PDF
(Companies (Accounts) Rules 3(1) edit log; Schedule III); D30–D41.

**Owner inputs:** voucher number format (e.g. `INC/2026-27/0001`); whether users see a numeric
chart of accounts (Schedule III codes) or it stays internal. **Given 25 Nov 2026:** `INC/2026-27/0001`,
and a visible chart of accounts for Admins and Partners. The checklist PDF isn't in the repo; the
rules were followed from Rule 3(1) and Schedule III directly.

- [x] Audit trail (edit log) that can't be switched off (D153):
      - every create, edit, reversal, approval and settings change on money records is logged;
      - each entry records who, when, IP and before/after, with secrets redacted;
      - an append-only table with a hash chain (`prev_hash`, `hash`), checked by `coffer:audit-verify`;
      - an Audit trail page with filters and CSV/PDF export.
- [x] Voucher numbers, gapless per company, financial year and type. They are assigned inside the
      posting transaction under a row lock, and shown on every entry, statement and export. (D154,
      plus the Journal / day book.)
- [x] Trial balance in four columns (opening, debit, credit, closing) for any period, with an FY selector. (D155)
- [x] Balance sheet and P&L in Schedule III format (Division I), with grouped notes. (D156)
- [x] Cash flow statement (indirect method). (D157)
- [x] Chart of accounts page: groups and codes aligned to Schedule III; existing account roles kept. (D158)

**Accept:**
- Changing one audit row makes the verify command fail.
- Voucher numbers stay gapless under a concurrent-posting test.
- In the trial balance, opening plus movement equals closing for every account.
- Schedule III statements tie to the existing reports.
- The cash flow statement reconciles to the movement in cash and bank.

**Status (25 Nov 2026):** done. Each acceptance line has a test: a changed or removed audit row
fails `coffer:audit-verify` (AuditTrailTest); four processes posting at once get vouchers 1–41
with no gap (VoucherNumbersTest); opening + debits − credits = closing on every line, and the
Schedule III statements and cash flow tie to the other pages, on the demo books, a random
fixture and a closed year (ReconciliationTest, AccountantStatementsTest).

## Phase 12: GST and TDS

**Read first:** CGST/IGST Acts (rates, place of supply, input tax credit, reverse charge); Income
Tax Act §192, §194C, §194J, §194Q; spec §10–§11.

**Owner inputs:** each company's GST registration (GSTIN, state, regular or composition); whether
returns are filed in-house or by a CA; the TDS sections deducted; a CA to review the rules.
**Given 26 Nov 2026:** use dummy registration details until the real ones arrive (Settings › Tax).

- [x] Company tax profile: GSTIN (encrypted), state, registration type, TAN. (D159)
- [x] Tax rates and HSN/SAC codes; place-of-supply rules (CGST+SGST in-state, IGST inter-state). (D159)
- [x] Expenses with a GST split and input tax credit. Reverse charge on foreign services (AWS,
      Google and similar) posts RCM payable plus the matching credit. (D161, D162)
- [x] TDS on expenses and salaries by section, TDS payable and challan entry; TDS receivable on income. (D160–D163)
- [x] New ledger accounts (GST input/output by type, RCM payable, TDS payable/receivable) posted by
      `LedgerPoster`; reversals unwind them. (D161)
- [x] GSTR-1, GSTR-3B and TDS summaries with Excel/JSON export for the CA. (D164)

**Accept:**
- Unit tests cover every rate, place-of-supply case, reverse-charge entry and TDS section.
- Tax accounts balance to the summaries.
- A CA signs off a sample month.

**Status (27 Nov 2026):** built, with dummy registration details. Tests cover every rate, place of
supply, reverse charge, TDS section and set-off branch (tests/Unit/Tax, TaxPostingsTest), and tie
GSTR-1, GSTR-3B and TDS to the tax accounts (TaxReturnsTest). **Waiting on the owner:** real
registration details, and a CA to review the rates, the set-off and a sample month (every rule is
marked [VERIFY] in D159–D164).

## Phase 13: Invoicing and quotes

**Read first:** spec §9 (income); Phase 12 output; Rule 46 CGST (tax invoice contents).

**Owner inputs:** invoice layout and logo per company; numbering format; payment gateway (Razorpay
test and live keys); default payment terms; reminder schedule.

- [ ] Clients (customers) with GSTIN, billing address, place of supply and contacts.
- [ ] Quotes that convert to invoices; GST tax invoices with HSN/SAC lines and a compliant PDF.
- [ ] Recurring invoices for retainers (monthly or quarterly), generated by the scheduler.
- [ ] Payment links (Razorpay/UPI), with webhooks that record the receipt and post the income.
- [ ] Credit notes, part payments and write-offs.
- [ ] Receivables (who owes you) with aging (0–30, 31–60, 61–90, 90+) and automatic email reminders.
- [ ] Accrual posting (invoice → receivable, payment → bank); today's cash-based income keeps working.

**Accept:**
- An invoice, a payment and a credit note each post the right lines.
- The receivables aging ties to the receivable account.
- A Razorpay test-mode payment marks the invoice paid end to end.
- The PDF carries every Rule 46 field.

## Phase 14: Multi-currency and export invoicing

**Owner inputs:** currencies used (USD, EUR, GBP…); exchange-rate source; whether companies have a
GST export bond (LUT).

- [ ] Invoices and receipts in foreign currency at the rate on the date; the books stay in INR.
- [ ] Export of services under LUT (zero-rated) or with IGST; LUT details per company and year.
- [ ] Realised exchange gain or loss on receipt; optional revaluation at year end.
- [ ] Foreign-currency bank accounts.

**Accept:**
- A USD invoice paid at a different rate posts the exchange gain or loss.
- Export invoices carry the LUT wording.
- Reports stay in INR and balance.

## Phase 15: Bank import and reconciliation

**Owner inputs:** sample statements (CSV/XLSX/PDF) from each bank used (e.g. HDFC, ICICI, Axis, Kotak).

- [ ] Statement import per money account: parsers for the common Indian bank formats, column mapping
      for the rest, and duplicate detection.
- [ ] Matching that suggests income, expense, transfer or invoice by amount, date and narration.
      Rules such as "narration contains AWS → Software". Each line can be accepted or turned into
      a new entry.
- [ ] Reconciliation view: statement balance against book balance, unreconciled items, and a period lock.
- [ ] Later (v2.1): automatic bank feeds through an Account Aggregator partner.

**Accept:**
- Each sample statement imports without errors.
- Reconciled balances equal the statement balances.
- Rules apply to new lines.

## Phase 16: CA / accountant portal and exports

**Owner inputs:** the Tally version the CA uses; a pilot CA.

- [ ] New "Accountant" role:
      - read-only access to books, reports, the audit trail and exports, and no vault;
      - can be invited to several companies and workspaces, with one switcher across clients.
- [ ] Tally export (XML vouchers and masters) and Zoho Books/Excel export.
- [ ] Year-end pack as one ZIP: trial balance, Schedule III statements, ledgers, GST/TDS summaries
      and the audit trail.
- [ ] CA referral: a referral link and a discount for the CA's clients (in billing).

**Accept:**
- The pilot CA imports a month into Tally without errors.
- The Accountant role can't change anything or open the vault (tests per route).

## Phase 17: Compliance calendar and partner statements

- [ ] Due dates per company for GST (GSTR-1, 3B), TDS (payments and returns), advance tax and ROC
      filings. Reminders go by email and in notifications, and each can be marked as done.
- [ ] Monthly partner statement PDF (capital, drawings, loans, share of profit, settlement position),
      emailed to each partner on the 1st.

**Accept:**
- Reminders arrive on schedule in tests.
- Statements match the capital and settlement pages.

---

## v2.1 (not started without approval)
- Client profitability and budgets (profit per client and project from hours and costs, budget vs
  actual, 90-day cash forecast).
- Client portal (clients see and pay invoices, download statements, receive shared credentials).
- Smart expenses (receipt photo read by AI for amount, vendor and category; WhatsApp submit and approve).
- Installable mobile app (PWA) with offline capture.

## Later
Vendor bills and payables; India payroll (PF, ESI, PT, TDS on salary, payslips); vault browser
extension and client share links; "Ask Coffer" AI assistant; integrations (Slack/Teams, Google
Sheets, webhooks); fixed assets and depreciation; Account Aggregator bank feeds.

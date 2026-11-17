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

- [ ] Notifications: events, database + mail channels, top-bar panel with filters and inline actions, `/notifications` page, preferences, brand email template.
- [ ] Plans table (placeholder prices and limits), 14-day trial, Cashier (Stripe) Checkout + Billing Portal.
- [ ] Billing page: plan, usage meters, invoices, upgrade modal. Soft limit enforcement.
- [ ] Marketing site with Inertia SSR:
  - landing (spotlight and parallax with reduced-motion fallback), pricing toggles
  - feature template, security, contact/demo form, legal pages
  - SEO: meta, OG, sitemap
- [ ] Filament super-admin at `/admin`: workspaces, usage, suspend, impersonate with reason and audit, plans editor, platform metrics.

**Accept:**
- A trial converts to paid in Stripe test mode.
- Limits trigger the upgrade modal.
- Marketing pages render server-side.
- Platform admin is isolated from tenants.

## Phase 9: Hardening and launch

- [ ] Responsive pass at 1024 px, and against every mobile board: `MobileDashboard`, `MobileExpenses`, `MobileVault`, `MobileLogHours`, `MobileNav`.
- [ ] All states: `StateEmpty`, `StateLoading`, `State404` (+ a 500 page derived from it), `StateDenied`, `StateSession`, `StateToasts`.
- [ ] Accessibility audit (axe plus keyboard pass). Performance pass (indexes, N+1, caching).
- [ ] Security review: headers/CSP, rate limits, authorisation tests, secret-leak test, dependency audit.
- [ ] Backups, queue workers (Horizon), scheduler, error tracking, health check.
- [ ] Deployment runbook for a VPS (Forge/Ploi or Docker). `.env.example` complete. Data export and delete for DPDPA.

**Accept:** a production checklist in `docs/DEPLOY.md`, all green.

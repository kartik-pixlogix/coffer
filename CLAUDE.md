# Coffer: project memory for Claude Code

Coffer is a multi-company finance and operations SaaS for agencies, studios and joint ventures (JVs). It covers:

- partner equity, team and timesheets
- income and expenses (with approvals and split payments)
- capital accounts, partner loans and JV settlements
- P&L, trial balance and balance sheet
- an encrypted **Credentials Vault** for team logins: Hostinger, Google, GitHub, Vercel, Netlify, AWS and others

It is used internally first and sold as a subscription product, so it must be correct, secure and polished.

## Sources of truth (read before working on a feature)

| What | Where |
|---|---|
| Behaviour, fields, formulas, rules | `docs/PRODUCT_SPEC.md` |
| Build order and acceptance criteria | `docs/BUILD_PLAN.md` |
| Which design file is which page/route | `docs/DESIGN_MAP.md` |
| Look and feel: tokens, rules, components | `design/design-system/README.md`, `tokens.json`, `components/bundle.css`, `components/<Name>/README.md` + `preview.html` |
| Exact screens (44 artboards, light + dark) | `design/screens/*.dc.html` |

Never edit anything under `design/`. It is a read-only reference.

## Stack (decided, don't change without asking)

- **Backend:** latest stable Laravel, PHP 8.3+.
- **Frontend:** Inertia.js + React + TypeScript (strict). Start from the official Laravel React starter kit.
- **Styling:**
  - Tailwind CSS v4 for layout utilities.
  - Component styling comes from the design system's `bundle.css`, with its `cf-*` and `t-*` classes.
  - Radix primitives (via shadcn/ui) are used only for behaviour and accessibility (Dialog, Popover, DropdownMenu, Tooltip, Tabs). Restyle them with `cf-*` classes and never ship shadcn's default look.
- **UI libraries:** charts are plain React per the design markup (D19, no Recharts), `lucide-react` icons at `strokeWidth={1.5}`, and `motion` (Framer Motion) for animation.
- **Database:**
  - MySQL 8 is the default (D3); MariaDB 10.4+ (XAMPP) is fine locally. Keep migrations portable to PostgreSQL 16: no engine-specific column types or SQL. Tests run against the `coffer_testing` database, not SQLite.
  - Redis for queues and cache, with Laravel Horizon. Locally, the `database` queue driver is fine.
- **Tests and tooling:** Pest for tests, Laravel Pint and Larastan, Vite+ `vp check` (oxlint + oxfmt, the starter kit's ESLint/Prettier replacement, D15), and `tsc --noEmit` in CI.
- **Packages:**
  - **Access and audit:** `spatie/laravel-permission` with the teams feature (team = company), and `spatie/laravel-activitylog`.
  - **Files and exports:** `maatwebsite/excel` or `spatie/simple-excel` for CSV/XLSX, and `spatie/laravel-pdf` or `barryvdh/laravel-dompdf` for PDF exports.
  - **2FA:** `pragmarx/google2fa` for vault TOTP codes; login 2FA comes from the starter kit / Laravel Fortify.
  - **Billing:** `laravel/cashier` (Stripe), plus Razorpay in Phase 8.
  - **Super-admin panel only:** `filament/filament` at `/admin`. Customers never see it.

## Architecture rules

- **Tenancy:** one database.
  - A `workspaces` row is the paying tenant. It has many `companies`.
  - Every business table has `company_id`. A `BelongsToCompany` global scope plus middleware resolve the current company from the session (company switcher).
  - Users belong to companies through `company_user`, with one role per company.
- **Roles:** `admin`, `partner`, `member`, `viewer`. Page and action access come from an editable per-company matrix (defaults in the spec). Enforce with Policies and Gates on every route and action, never only in the UI.
- **Money:**
  - Store amounts as integer **paise** in `bigint` columns. Never use floats. Currency is a company setting (INR by default).
  - Format Indian style (₹12,34,567). Negatives use a true minus (−₹2,66,500). Use `Intl.NumberFormat('en-IN')` on the frontend and `NumberFormatter('en_IN')` in PHP.
- **Ledger:** double-entry is the core. Every money event posts a balanced journal entry through a single `LedgerPoster` service, inside a DB transaction. Events include income, expense approval, salary, contribution, drawing, loan, repayment and settlement transfer.
  - Reports (P&L, TB, BS, capital statement, settlements) are computed from journal lines, so books always balance.
  - Invariants are tested (see spec §4): TB balances, BS balances, profit shares sum to net profit, and JV settlement remainders sum to zero.
- **Fiscal year:** April–March by default (start month is configurable). Closing a FY posts closing entries, carries capital forward and locks the period. No create, edit or delete on dates in a closed FY.
- **Domain code:** Actions/Services in `app/Actions` and `app/Services`. Form Requests validate input. Policies authorise. Thin controllers return Inertia responses. Queue anything slow (imports, salary runs, exports, breach checks).
- **Vault security:** spec §14.
  - Envelope encryption: a per-company data key wrapped by a master key from env, using AES-256-GCM.
  - Secrets never appear in list/index payloads, logs, exceptions or activity-log diffs.
  - Reveal and copy require a recent 2FA step-up, are rate-limited and are audit-logged.

## Design implementation rules

- **Stylesheet:** `resources/css/coffer-ui.css` is generated from `design/design-system/components/bundle.css` with `npm run ui:css` (D22: same rules, component selectors boosted above the bundle's element resets) and imported from `app.css` in the components layer. Never hand-edit it.
  - The root element gets `class="cf" data-theme="light|dark"`.
  - Store the theme per user, with a `localStorage` fallback, and toggle it from the top bar.
  - Map the tokens into a Tailwind v4 `@theme` block so utilities use the same CSS variables. Never hard-code colours, fonts, radii or spacing that aren't tokens.
- **Fonts:** self-host with `@fontsource`:
  - Bricolage Grotesque (variable, with the opsz axis) for display.
  - Plus Jakarta Sans 300–600 for UI.
  - IBM Plex Mono 400–500 for money.
- **UI kit:** one React component per design-system component in `resources/js/components/ui/`. It must output the same markup, classes and ARIA as that component's `preview.html` for every state.
  - Build a `/dev/ui` gallery (local env only) that shows every component in light and dark, to compare against `preview.html`.
- **Pages:** each Inertia page reproduces its `.dc.html` screen. Use the same structure, classes and copy; replace mock data with props; re-implement its interactions.
  - Read the screen's `<script type="text/x-dc">` block for behaviour and validation.
  - The `data-props` "tweaks" (State, Tab, Theme, Balance, Screen…) list alternate states that must exist in the real app.
- **Money and figures:**
  - Use a `<Money paise={…} />` component (classes `t-money*`, tabular numbers, Indian grouping, −₹ for negatives).
  - Figures count up on load. Charts follow `design-system/components/*Chart*/README.md`.
- **Motion and accessibility:**
  - Motion follows the README: hover lift, count-up, panel entry, drift. Turn everything off under `prefers-reduced-motion`.
  - Cursor spotlight and parallax appear only on marketing and auth pages.
  - Meet WCAG 2.1 AA. Status always shows an icon plus a word. Icon-only buttons need `aria-label`. Use visible focus rings (`focus-ring`).
- **Responsive:** follow the `Mobile*.dc.html` boards. At phone width the nav rail becomes a row or menu, tables scroll inside their card and modals become full-height sheets.

## Conventions

- PHP: strict types, typed properties, enums for statuses and roles, `final` Actions. TS: no `any`; share Inertia prop types in `resources/js/types/`.
- Migrations: `bigint` paise, `foreignId()->constrained()`, indexes on `(company_id, date)`, and soft deletes where the spec says so.
- **Tests:**
  - Write a feature test for every route: authorisation per role, validation, happy path.
  - Write unit tests for ledger posting, profit allocation, settlements, salary generation, month-hours distribution, CSV parsing and vault crypto.
- **Demo data:** seeders recreate the design's sample data (Vertex Group, Saarthi Portal JV, Vertex Labs / Bluefin Infotech sides, Aarav, Ishita, Kabir, Meera, Rohan…) so screens can be compared with the designs.
- **Git:** small commits per step. Conventional messages, e.g. `feat(expenses): split payers`.

## Commands

PHP 8.3+ must come first on PATH. On this machine XAMPP's PHP 8.2 is first; use the winget PHP 8.4 (`%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.NTS.8.4_*`).

- `composer run dev` starts the app, queue and Vite.
- `php artisan test` runs the tests (Pest).
- `vendor/bin/pint` formats PHP.
- `npm run lint` and `npm run types` check the frontend.
- `php artisan migrate:fresh --seed` resets the DB with demo data.

## How to work

1. Work phase by phase from `docs/BUILD_PLAN.md`. Start each phase in plan mode: read the listed spec sections and design files, then propose a short plan before coding.
2. Finish a phase only when its acceptance criteria pass:
   - tests are green and the ledger invariants hold
   - pages match the designs in light and dark
3. Ask before adding a major dependency, changing the stack, or deviating from a design or a spec rule. Record decisions in `docs/DECISIONS.md`.
4. Never commit secrets. `.env.example` lists every required key (including `VAULT_MASTER_KEY`).

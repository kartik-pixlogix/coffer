# Decisions log

Add a line whenever something is decided or changed. `[VERIFY]` means the product owner (Kartik) should confirm. Where final content (prices, copy, limits) is still to come, use clearly dummy content and mark it Placeholder.

| # | Decision | Status |
|---|---|---|
| D1 | Product name **Coffer**. Look locked: Lime gradient, Bricolage Grotesque + Plus Jakarta Sans + IBM Plex Mono, light and dark. | Decided |
| D2 | Stack: Laravel + Inertia.js + React + TypeScript, Tailwind v4, design-system `bundle.css` for components. | Decided |
| D3 | Database: **MySQL 8** (MariaDB 10.4+ locally via XAMPP). Migrations stay portable to PostgreSQL 16. Tests run on MySQL (`coffer_testing`), not SQLite. | Decided (Nov 2026) |
| D4 | Money stored as integer paise. Indian number formatting by default; per-user number format setting. | Decided |
| D5 | Double-entry ledger. Every report comes from journal lines. Edits post reversals. | Decided |
| D6 | "Other expenses" = all non-salary expenses (the reference product used only the "Other" category). | Decided |
| D7 | JV settlements include each side's share of company-account flows, so the sides always net to zero. | Decided (fixes reference bug) |
| D8 | Profit sharing: JV pooled = by effective equity; Per partner = attributed amounts + equity share of unattributed. | Decided |
| D9 | Default permissions differ slightly from the reference: Members can submit their own expenses; Partners can view Settlements. Both are editable per company. | Decided for now (placeholder until the owner supplies final content) |
| D10 | Vault: envelope encryption, per-company data keys wrapped by `VAULT_MASTER_KEY` (env); KMS later. 2FA required to use the vault. | Decided |
| D11 | Payments: Stripe via Cashier first; Razorpay later in Phase 8. | Decided |
| D12 | Plan prices and limits are placeholders (`[PRICE]`, `[N]`) in a DB plans table. | Placeholder: dummy prices and limits until the owner supplies them |
| D13 | Super-admin panel uses Filament at `/admin`, platform admins only. | Decided |
| D14 | Vault providers shown as monogram tiles, never third-party logos. | Decided (design system rule) |
| D15 | Base: Laravel 13 React starter kit (main branch): Inertia 3, Fortify (login 2FA, passkeys), Wayfinder route helpers, React Compiler. Its frontend tooling is Vite+ (`vp check` = oxlint + oxfmt) instead of ESLint + Prettier; we keep it and expose `npm run lint` / `npm run types`. | Decided (owner delegated; Vite+ kept: it ships with the starter kit, is faster, and replaces both tools) |
| D16 | Fonts are self-hosted with `@fontsource` (not Google Fonts as the design README says), so the CSP can stay `font-src 'self'`. | Decided |
| D17 | Theme: light or dark, stored per user (`users.theme`) with a `localStorage` + cookie fallback. Until a user picks one, the OS preference is used. | Decided |
| D18 | Nav rail items for modules not built yet render disabled, with the reason in the label, until their phase ships. | Decided |
| D19 | Charts are plain React (SVG, divs and CSS conic-gradient) reproducing the design-system chart markup exactly (`cf-bar` buttons, conic donut, hand-drawn area SVG with crosshair). Recharts is not used: it can't produce the design's markup or keyboard-reachable bars. | Decided (owner delegated) |
| D20 | The starter kit's shadcn components live in `components/shadcn/` only until Phase 2 rebuilds the auth and settings pages; `components/ui/` holds the Coffer kit. Icons render through `<Icon>` (lucide drawn as the design's `svg.ic`). | Decided |
| D21 | Front-end unit tests use Vitest through Vite+ (`npm test` = `vp test`), pure logic only; no DOM test library added. | Decided |
| D22 | `resources/css/coffer-ui.css` is generated from `bundle.css` by `npm run ui:css` (scripts/coffer-ui-css.mjs): an exact copy except every component rule gets `:not(#\#)`. Reason: the bundle's own resets (`.cf button{font:inherit;color:inherit}`, `.cf a{…}`) out-rank single-class component rules, so primary/danger/lime buttons, danger menu items and the rail logo lost their colour and weight. A unit test fails if the file drifts from the design bundle. | Decided (fixes design-bundle bug) |
| D23 | Create-company wizard: partner and team rows get an optional **Email** field (the design's Review promises launch invites but its rows had no email). Launch invites everyone with an email, linked to their partner or member record. | Decided (owner, Nov 2026) |
| D24 | Roles & permissions matrix uses the **spec §2.1/§2.2 rows and defaults**, laid out and grouped like `SettingsMembers.dc.html` (Pages; Actions in Money / People / Vault). Keeps the spec's "Reset to defaults". "Own" scoping for Members (own entries, submissions, shared vault items) is enforced by each module's policies. | Decided (owner, Nov 2026) |
| D25 | Passkeys (starter kit) are switched off and their UI removed: no design or spec covers them. Fortify still depends on `laravel/passkeys`, but the feature, table and routes are gone. | Decided (owner, Nov 2026) |
| D26 | Invite expiry choices follow the spec: 1, 3, 7 or 14 days, default 7 (the design mock showed 24 hours / 7 days / 30 days). | Decided |
| D27 | Phase 2 Launch creates the company, memberships, roles, sides, partners, members and invites, and stores `opening_balance_paise`. Money accounts, the chart of accounts and the opening journal entry are Phase 3 (with a backfill for existing companies). Until then partner-card stats show ₹0 / 0 h and side bank accounts "Not set". JV step 1 gets the same Currency select as Single. | Decided |
| D28 | Settings sub-nav items whose phase hasn't shipped (Company, Categories, Notifications, Billing) render disabled with "coming soon", like D18. | Decided |
| D29 | Settings › Members shows "Team members by partner" (spec §16) as a second card under the Users table, in the same table style. The Billing row of the permission matrix is locked: billing is for the workspace owner and Admins only. | Decided |
| D30 | Session expiry (StateSession): an expired session makes the next request return 401/419; the app opens the re-auth dialog over the page without navigating, signs in through JSON endpoints (password, then the 2FA code when 2FA is on) and refreshes the CSRF token. Unsaved form state stays; the user resubmits. | Decided |
| D31 | Edit-partner modal (SettingsPartners): a share can only change if its split still totals 100%, so the modal also shows the other partners in the affected split (side, or company) with editable shares, saved together in one transaction. Adding a partner works the same way and can send an invite (D23). | Decided |
| D32 | Phase 3 ships the ledger's posting layer (one method per spec §4.2 event, `App\Ledger\Postings`) and seeds ledger-only demo books that hit the design figures. Phase 5's Income/Expense records post through the same methods and replace the seeded entries. | Decided |
| D33 | "Current-year profit" (3900) is derived on the balance sheet from the open years' income and expenses and is never posted to; FY close moves profit straight into capital per partner (spec §4.2). A reversal keeps the type of the entry it reverses (linked by `reverses_id`), so corrections net out of the same figures. | Decided |
| D34 | Settlements are computed from "holdings" (money accounts, partners' current accounts, reimbursements owed to members) grouped by the side holding them; the company account is pooled by side share. Every profit-and-loss entry moves holdings, so remainders sum to zero by construction (invariant 5). Collected/paid are breakdowns of the same lines. | Decided |

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

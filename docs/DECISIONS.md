# Decisions log

Add a line whenever something is decided or changed. `[VERIFY]` means the product owner (Kartik) should confirm.

| # | Decision | Status |
|---|---|---|
| D1 | Product name **Coffer**. Look locked: Lime gradient, Bricolage Grotesque + Plus Jakarta Sans + IBM Plex Mono, light and dark. | Decided |
| D2 | Stack: Laravel + Inertia.js + React + TypeScript, Tailwind v4, design-system `bundle.css` for components. | Decided |
| D3 | Database: MYSQL
| D4 | Money stored as integer paise. Indian number formatting by default; per-user number format setting. | Decided |
| D5 | Double-entry ledger. Every report comes from journal lines. Edits post reversals. | Decided |
| D6 | "Other expenses" = all non-salary expenses (the reference product used only the "Other" category).
| D7 | JV settlements include each side's share of company-account flows, so the sides always net to zero. | Decided (fixes reference bug) |
| D8 | Profit sharing: JV pooled = by effective equity; Per partner = attributed amounts + equity share of unattributed. | Decided |
| D9 | Default permissions differ slightly from the reference: Members can submit their own expenses; Partners can view Settlements. Both are editable per company.
| D10 | Vault: envelope encryption, per-company data keys wrapped by `VAULT_MASTER_KEY` (env); KMS later. 2FA required to use the vault. | Decided |
| D11 | Payments: Stripe
| D12 | Plan prices and limits are placeholders (`[PRICE]`, `[N]`) in a DB plans table. | [VERIFY] when pricing is set |
| D13 | Super-admin panel uses Filament at `/admin`, platform admins only. | Decided |
| D14 | Vault providers shown as monogram tiles, never third-party logos. | Decided (design system rule) |

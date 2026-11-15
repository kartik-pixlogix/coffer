# Nav rail

Use the pill-shaped nav rail on every app screen: the lime C home disc, then one round icon link per module in groups (Dashboard · Partners, Team, Timesheets · Income, Expenses, Capital Accounts, Partner Loans, Settlements · Reports, Trial Balance, Balance Sheet · Credentials Vault · Settings, Billing), with Sign out in graphite at the end.

- Markup: `<nav class="cf-rail" aria-label="Main">` › `cf-rail-logo`, `cf-rail-item` links with `aria-label` and a tooltip, `cf-rail-sep` between groups, `cf-rail-out`.
- Active: `aria-current="page"` fills the item with the lime gradient. Badge: `cf-badge` with the count also in the label ("Settlements, 1 due"). Items a role can't open are hidden, or shown disabled with the reason in the label.
- At phone width the rail wraps into a row above the content.

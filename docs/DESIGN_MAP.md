# Design map: screens → routes → pages

All screens are in `design/screens/` and were exported from the Claude Design canvas "Coffer — Dashboard, Expenses & Vault". Each one shows light and dark through its Theme tweak. The design system is in `design/design-system/`.

## How to read a `.dc.html` file

These are "Design Component" prototypes. Use them as a visual and behavioural reference only. Never ship them.

- **Markup:** everything inside `<x-dc>…</x-dc>` is the screen. `<helmet>` holds font links and page CSS. Classes come from `design-system/components/bundle.css` (`cf-*` components, `t-*` type styles). Inline styles are layout.
- **Data holes:** `{{name}}` is a value from `renderVals()` in the `<script type="text/x-dc" data-dc-script>` block at the bottom.
- **Loops and conditions:** `<sc-for list="{{rows}}" as="r">` is a loop (render `rows.map`). `<sc-if value="{{cond}}">` is a conditional.
- **Behaviour:** the script's class holds mock state, validation and interactions. Examples:
  - equity totals and even split
  - hrs/day preview in month mode
  - payer totals in a split expense
  - reveal auto-hide after 30 s
  - generator rescoring

  Re-implement this logic for real, backed by the server.
- **Tweaks:** the `data-props` JSON on the script tag lists alternate states (State, Tab, Screen, Balance, Company, 2FA, Theme). Every option is a state the real page must support.
- **Links:** `href="Other.dc.html"` links between screens. Map them with the table below.
- Mock data is fictional. Seed similar data (spec §24) so pages can be compared side by side.

## Screen table

| Screen file | Route(s) | Inertia page | States / tweaks to implement |
|---|---|---|---|
| `Landing.dc.html` | `/` | `marketing/landing` | Pointer and scroll parallax on the preview; still under reduced motion |
| `Pricing.dc.html` | `/pricing` | `marketing/pricing` | Monthly/yearly, INR/USD; `[PRICE]`, `[N]`, `[X]%` placeholders come from the plans table |
| `SignIn.dc.html` | `/login`, `/two-factor-challenge` | `auth/login`, `auth/two-factor-challenge` | State: Sign in, 2FA code, Error |
| `SignUp.dc.html` | `/register` | `auth/register` | Password strength meter, email check |
| `AcceptInvite.dc.html` | `/invites/{token}` | `auth/accept-invite` | State: Valid invite, Expired link |
| `CreateCompanySingle.dc.html` | `/companies/create` (step 1, Single) | `companies/create` | State: Total is 100% / not 100%; colour cycle; even split |
| `CreateCompanyJV.dc.html` | `/companies/create` (step 1, JV) | `companies/create` | Side totals plus within-side totals |
| `CreateCompanySteps.dc.html` | `/companies/create` (steps 2–3) | `companies/create` | Team / Review |
| `Main.dc.html` | `/dashboard` | `dashboard` | Theme; settlement alert; approvals inline |
| `Partners.dc.html` | `/partners` | `partners/index` | JV grouped view (build the Single grid from the same cards) |
| `Team.dc.html` | `/team` | `team/index` (+ `team/show` derived) | Add member modal; partner tabs |
| `Timesheets.dc.html` | `/timesheets` | `timesheets/index` | Log hours modal, Day/Month, Saturdays recalculation |
| `BulkImport.dc.html` | `/timesheets/import` | `timesheets/import` | State: Upload → Map columns → Preview; manual entry tab |
| `Income.dc.html` | `/income` | `income/index` | Add income modal; sort by amount/date |
| `Expenses.dc.html` | `/expenses` | `expenses/index` | Add expense modal (Full/Half/Custom), filters, bulk bar |
| `CapitalAccounts.dc.html` | `/capital` | `capital/index` | Record contribution modal; FY selector |
| `PartnerLoans.dc.html` | `/loans` | `loans/index` | Record repayment modal; auto-close at ₹0 |
| `Settlements.dc.html` | `/settlements` | `settlements/index` | Record transfer panel; Company tweak gives the non-JV empty state |
| `Reports.dc.html` | `/reports` | `reports/index` | Period presets (Q1, Q2, October…), Export menu |
| `Statements.dc.html` | `/trial-balance`, `/balance-sheet` | `reports/trial-balance`, `reports/balance-sheet` | Balance tweak gives the drift state |
| `SettingsCompany.dc.html` | `/settings/company` | `settings/company` | Close FY dialog |
| `SettingsMembers.dc.html` | `/settings/members`, `/settings/roles` | `settings/members`, `settings/roles` | Tab tweak; invite modal; permission ticks |
| `SettingsPartners.dc.html` | `/settings/partners`, `/settings/sides` | `settings/partners`, `settings/sides` | Tab tweak; edit partner modal; side totals |
| `Vault.dc.html` | `/vault` (`?item={id}` opens the drawer) | `vault/index` | Drawer, reveal with 2FA, TOTP ring, list/grid, lock |
| `VaultAddCatalog.dc.html` | `/vault/items/create` | `vault/create` (step 1) | Catalog search and filter |
| `VaultAddLogin.dc.html` | `/vault/items/create?provider=…` | `vault/create` (step 2) | Generator popover with live scoring |
| `VaultShare.dc.html` | share drawer + `/vault/requests` | `vault/requests` | Add people, permission levels, approve/deny |
| `VaultHealth.dc.html` | `/vault/health` | `vault/health` | Rotate raises the score |
| `VaultAudit.dc.html` | `/vault/audit` | `vault/audit` | Filters by date, person, action |
| `VaultLockPolicies.dc.html` | `/vault/locked` (state), `/vault/policies` | `vault/locked`, `vault/policies` | Screen tweak |
| `Billing.dc.html` | `/billing` | `billing/index` | Upgrade modal; usage bars |
| `Profile.dc.html` | `/profile` | `settings/profile` | 2FA tweak (Off/On); sessions |
| `Notifications.dc.html` | panel in the top bar + `/notifications` | `notifications/index` | Filters; approve inline |
| `MobileDashboard.dc.html` | `/dashboard` at 390 px | (responsive) | |
| `MobileExpenses.dc.html` | `/expenses` at 390 px | (responsive) | Add expense as a full-height sheet; paid from / personal card; bill to client |
| `MobileVault.dc.html` | `/vault` at 390 px | (responsive) | Reveal hides after 30 s; TOTP |
| `MobileLogHours.dc.html` | `/timesheets` at 390 px | (responsive) | Day/month switch |
| `MobileNav.dc.html` | menu at 390 px | (responsive) | Every module |
| `StateEmpty.dc.html` | any list with no data | `EmptyState` usage | "No income yet" pattern |
| `StateLoading.dc.html` | dashboard loading | `Skeleton` usage | |
| `State404.dc.html` | 404 | `errors/404` (+ 500 derived) | |
| `StateDenied.dc.html` | 403 | `errors/403` | Member opening Reports |
| `StateSession.dc.html` | session expired | `SessionExpiredDialog` | 2FA re-auth |
| `StateToasts.dc.html` | toasts | `Toast` usage | "Show it" action |

## Pages without their own design (build from the patterns noted)

- Forgot / reset password and verify email use the `SignIn` layout.
- Feature-detail, Security, Contact and legal pages use `Landing` sections.
- The Single-company Partners grid uses the `Partners` cards.
- Member profile uses the `Team` cards plus a `Timesheets` table plus a `BarChart`.
- Approvals queue and receipt preview use `Expenses` with the `Drawer`.
- Settings › Categories, Projects, Money accounts and Notifications use the `SettingsCompany` layout with `DataTable` and `Toggle`.
- Vault import/export uses the `BulkImport` steps.
- Notifications page, 500 page, offline banner (`AlertBanner --info`) and emails use the brand tokens.
- The super-admin panel is Filament with brand colours. It needs no design.

# Coffer: product specification

How the product behaves. Look and layout come from `design/` (see `DESIGN_MAP.md`). When the two disagree on behaviour, this file wins. When they disagree on look, the design wins. Record any open question in `docs/DECISIONS.md` instead of guessing.

---

## 1. Glossary

| Term | Meaning |
|---|---|
| Workspace | The paying tenant (e.g. "Vertex Group"). Owns subscription, users and companies. |
| Company | A set of books inside a workspace. Type is **Single** or **Joint Venture (JV)**. Users switch companies from the top bar. |
| Partner | An owner with an equity %. In a Single company equity is a % of the company. In a JV it is a % *within their side*. |
| Side | JV only: a parent organisation (e.g. "Vertex Labs", "Bluefin Infotech") with a share of the JV. Side shares total 100%. |
| Effective equity | Single: the partner's %. JV: side % × within-side %. |
| Member | A team member who logs hours and costs money (hourly rate or fixed monthly). Billed to one partner. May be linked to a user. |
| User | A login. Has one role per company: Admin, Partner, Member or Viewer. |
| Money account | Where cash sits: "Company account" (Single, or JV-level), one account per JV side ("Vertex Labs account"), plus optional extra bank/cash accounts. |
| FY | Fiscal year. April–March by default; start month configurable (Jan/Apr/Jul/Oct). Shown as "FY 2026-27". |

---

## 2. Tenancy, users and permissions

- **Tenancy model:** a workspace has many companies, users and one subscription. A user can belong to companies in more than one workspace. The company switcher lists each company with the user's role there.
- **Company scoping:** every business record carries `company_id`. A request can never read or write another company's data. Add a test that tries.
- **Roles per company:** `admin`, `partner`, `member`, `viewer`. The workspace owner is always Admin of every company they create.
- **Permissions:** an editable per-company matrix (Settings › Roles & permissions), stored per company and seeded with the defaults below. The Admin column is locked to full access.

### 2.1 Default page access

| Page | Admin | Partner | Member | Viewer |
|---|---|---|---|---|
| Dashboard | ✓ | ✓ | – | ✓ |
| Partners | ✓ | ✓ | – | – |
| Team | ✓ | ✓ | own profile | – |
| Timesheets | ✓ | ✓ | own entries | – |
| Income | ✓ | ✓ | – | – |
| Expenses | ✓ | ✓ | own submissions | – |
| Capital accounts | ✓ | ✓ | – | – |
| Partner loans | ✓ | ✓ | – | – |
| Settlements (JV) | ✓ | ✓ | – | – |
| Reports | ✓ | ✓ | – | ✓ |
| Trial balance | ✓ | ✓ | – | – |
| Balance sheet | ✓ | ✓ | – | ✓ |
| Credentials vault | ✓ | ✓ | shared items | – |
| Settings | ✓ | – | – | – |
| Billing | workspace owner/admin | – | – | – |

A Member's landing page is Timesheets. Everyone else lands on the Dashboard.

### 2.2 Default actions

| Action | Admin | Partner | Member | Viewer |
|---|---|---|---|---|
| Add / edit income | ✓ | ✓ | – | – |
| Add expense | ✓ | ✓ | ✓ (own) | – |
| Approve / reject expenses | ✓ | ✓ (not own) | – | – |
| Log time | ✓ | ✓ | ✓ (own) | – |
| Edit rates | ✓ | – | – | – |
| Manage team | ✓ | – | – | – |
| Record settlement transfer | ✓ | – | – | – |
| Manage capital and loans | ✓ | – | – | – |
| Close fiscal year | ✓ | – | – | – |
| Vault: create items | ✓ | ✓ | – | – |
| Vault: reveal and copy | ✓ | Use+ on the item | Use+ on the item | – |
| Vault: share and manage | ✓ | item Managers | item Managers | – |
| Vault: export / view audit log | ✓ | – | – | – |

- **Enforcement:** Policies and Gates on the server. The UI hides what the role can't do. A direct URL shows the "Permission denied" state (`StateDenied.dc.html`).

### 2.3 Invites

- **Ways to invite:** by email, or by a shareable link.
  - Each invite has a role, an optional link to a partner or member, and an expiry (1, 3, 7 or 14 days; default 7). Pending invites can be copied, resent or revoked.
  - The invite page shows the inviter, the company, the role, the side and equity (if linked to a partner), and a summary of what the role can and can't do.
- **Accepting:** the invitee joins by creating a password or with Google.
- **Edge cases:** expired, revoked or already-used links show the expired state with an "Ask for a new link" action, which emails the inviter.

---

## 3. Company setup

### 3.1 Create-company wizard (3 steps)

**Step 1, Company & partners.**
- **Company fields:**
  - Name, required.
  - Short code: 2–6 letters, uppercase, unique within the workspace.
  - Opening balance: ₹, default 0. This is cash on the FY start date.
  - Currency: INR by default.
  - Type: Single / JV.
- **Single:**
  - Partner rows have a colour (partner-1…6, click to cycle), name, role (Admin/Partner) and equity % (2 dp).
  - Show a live "Total: 100.00%" pill. The step can't continue until the total is 100% (±0.005).
  - The error text says the gap: "Add 5.00% so it totals 100%."
  - "Even split" fills equal shares, with any rounding remainder on the first row.
- **JV:**
  - Sides have a name and an equity %. Sides must total 100%.
  - Partners within a side have a role, a side and a % within that side. Each side must total 100%.
  - "Even split" works per side. Each side gets a money account named "<Side> account".
- The first partner row is the creating user, as Admin. That row can't be removed.

**Step 2, Team (optional):** add members inline: name, designation, cost type (Hourly / Fixed monthly), rate or monthly cost, partner.

**Step 3, Review & launch:** a summary of everything. Launch creates in one transaction:
- the company and its partners (and sides)
- the money accounts and chart of accounts
- the opening-balance journal entry (credited to partners' capital by effective equity)
- the members

The user then lands on the new company's dashboard. "Save and exit" keeps a draft.

### 3.2 Company settings

These live in Settings › Company:
- **Identity:** name, legal name, GSTIN (optional; validate the format), registered address, short code.
- **Money:**
  - Opening balance (editable until the first FY is closed; editing re-posts the opening entry).
  - Currency (locked after the first transaction).
- **Structure and attribution:**
  - Type (Single/JV). Switching to JV requires setting up sides.
  - Income attribution: Per partner / JV pooled (§4.4).
- **Calendar:** FY start month; working days (Mon–Fri, plus a Saturdays toggle) and standard hours per day (default 8), used by month-mode timesheets.
- **Close FY** (§4.6), behind a type-to-confirm dialog.

---

## 4. Accounting model (the core; implement and test first)

### 4.1 Accounts (auto-created per company)

| Code | Account | Type | Notes |
|---|---|---|---|
| 1000-x | Money accounts | Asset | Company account; one per JV side; extra bank/cash accounts |
| 1200-x | Partner current, per partner | Asset/Liability | Debit balance = the partner holds company money. Credit = the company owes the partner (e.g. paid by personal card). |
| 2000-x | Partner loans payable, per partner | Liability | |
| 2100-x | Reimbursements payable, per member | Liability | A member paid personally |
| 3000-x | Capital, per partner | Equity | Opening, contributions, drawings, closed-year profit |
| 3900 | Current-year profit | Equity (system) | Closed into capital at FY close |
| 4xxx | Income, per income category | Income | Project, Product, Consulting, Other + custom |
| 5xxx | Expenses, per expense category | Expense | Salary, Software, Hardware, Travel, Marketing, Office & misc + custom |

- **Journal storage:** `journal_entries` (company, date, type, source morph, memo, posted_by, reversed_by) and `journal_lines` (account, debit_paise, credit_paise, partner_id, side_id, member_id, project_id dimensions).
- **Balancing:** an entry must balance. Reject it otherwise.
- **Corrections:** an edit or delete never mutates posted lines. It posts a **reversal** plus a new entry.

### 4.2 Posting rules

| Event | Debit | Credit |
|---|---|---|
| Opening balance | Money account | Capital, per partner by effective equity |
| Income received into money account M | M | Income:category (dimension: partner "brought in by") |
| Income received personally by partner P | Partner current P | Income:category |
| Expense approved, per payer line, paid from M | Expense:category (dim: payer partner/side) | M |
| …paid by partner P's personal card | Expense:category | Partner current P |
| …paid by member m's personal card | Expense:category (dim: m's partner) | Reimbursements payable m |
| Reimbursement paid out from M | Partner current P / Reimb. payable m | M |
| Salary (auto, member m, month) | Expense:Salary (dim: m, m's partner) | Paid-from account of m's partner (side account in a JV, company account in Single) |
| Capital contribution by P into M | M | Capital P |
| Drawing by P from M | Capital P | M |
| Loan from partner P into M | M | Loans payable P |
| Loan repayment to P from M | Loans payable P | M |
| Convert a personal-card amount into a loan | Partner current P | Loans payable P |
| Settlement transfer, side A → side B | Money account of B | Money account of A |
| FY close | All income accounts; all expense accounts (net) | Capital per partner by profit share (§4.4) |

### 4.3 Figures used across the app

All figures are computed from journal lines for the selected period.
- **Income:** credits to income accounts.
- **Expenses:** debits to expense accounts. Only approved expenses post.
- **Net profit:** income − expenses.
- **Salary expenses:** the Salary category. **Other expenses:** all non-salary expenses. (The reference product used only the "Other" category here; this definition is ours, see DECISIONS.md.)
- **Current balance:** the sum of all money-account balances. The formula strip shows opening + income − expenses, plus a "± capital & loans" term only when it isn't zero.

### 4.4 Profit allocation (share of profit per partner)

- **JV pooled / equity mode:** share_P = net profit × effective equity_P.
- **Per-partner mode:** share_P = (income attributed to P − expenses attributed to P) + effective equity_P × (unattributed income − unattributed expenses).
  - Income attributed to P: "brought in by P".
  - Expenses attributed to P: paid by P (including personal card), paid from P's side account split by within-side equity, and salary of members billed to P.
- **Rounding:** work in paise with largest-remainder rounding, so the shares add up to net profit exactly.

### 4.5 Invariants (Pest tests plus a nightly integrity job that alerts admins)

1. Every journal entry balances.
2. Trial balance: total debits = total credits, for any period.
3. Balance sheet: assets = liabilities + equity, for any date.
4. The sum of partner profit shares = net profit.
5. JV: the sum of settlement remainders across sides = 0 (within ±1 paisa).
6. Capital statement closing totals = the equity section of the balance sheet.

### 4.6 Fiscal year close

- **Preview first:** show net profit, each partner's share and the closing capital.
- **Confirm:** typing the FY name (e.g. "FY 2025-26") posts the closing entries and locks the period. No create, edit or delete is allowed with a date inside a closed FY. Opening capital for the next FY = closing capital.
- **Undo:** reopening a closed FY is an Admin-only, audit-logged action that reverses the closing entries.

---

## 5. Dashboard (`Main.dc.html`)

- **Header:** breadcrumb (Workspace / Company / Dashboard), greeting ("Hi Aarav,"), period control (default FY to date; also quarter, month or custom), and a "New company" button.
- **Hero card, Current balance:** the formula strip, plus a gentle lime glow drift.
- **KPI tiles:** total income, total expenses, other expenses, net profit, total hours. Each has a trend chip versus the previous equal period.
- **Settlement alert (JV only):** "A owes B ₹X → Resolve" opens Settlements. It is hidden when every side is settled.
- **Charts:**
  - Revenue vs expenses: monthly area chart. Revenue is solid with a lime wash; expenses are dashed.
  - Profit by month: bars, with negatives below the axis and the latest bar in lime.
  - Revenue by partner: donut in partner colours.
  - Expenses by category: donut in the brand ramp.
- **Lists:**
  - Pending approvals: top 5, with approve/reject inline (permission-gated) and "Review in Expenses".
  - Credentials expiring soon: vault items expiring within 30 days, plus the vault health score link. Shown only if the user has vault access.
- **States:** empty (new company), loading skeleton (`StateLoading.dc.html`), error.

## 6. Partners (`Partners.dc.html`)

- **Single:** a grid of partner cards. **JV:** cards grouped under side headers. Each side header shows the side share %, an OWES/OWED pill, the partner count, and revenue, expenses and net.
- **Partner card:** avatar with colour dot, role badge, equity % ("within side" in a JV), and revenue, expenses, net contribution and hours.
- **Metric definitions:**
  - Revenue: income brought in by P.
  - Expenses: expenses attributed to P (§4.4).
  - Salary cost: salary of members billed to P.
  - Net contribution: revenue − expenses.
  - Hours: hours of members billed to P.
  - Share %: P's revenue ÷ total revenue.
- **Charts:** partner comparison (grouped bars, negatives allowed), revenue distribution (donut), equity split (progress bars).
- **Detailed table:** partner, equity, revenue, expenses, other, salary cost, net contribution, hours, share %.

## 7. Team (`Team.dc.html`)

- **List:**
  - Filter tabs by partner, with counts.
  - Member cards: avatar (partner dot), name, designation, partner badge, hourly rate or monthly cost, billable hours this month, earnings this month, Active/Inactive.
- **Add / edit member:**
  - Name (required), designation.
  - Cost type: Hourly / Fixed monthly. The field label changes between "Hourly rate ₹/hr" and "Monthly cost ₹/month".
  - Assigned partner (required) and linked user account (optional).
  - "Edit rates" permission is required to change a rate.
  - A rate change takes effect from a chosen date and keeps history. Past salaries don't change.
- **Deactivate, not delete:** members with hours or salary history can't be deleted.
- **Earnings:** hourly = billable hours × rate; fixed = monthly cost.
- **Member profile** (`/team/{member}`, derived from the existing patterns): details, rate history, monthly hours (chart plus table), salary history, and vault items the member can access.

## 8. Timesheets (`Timesheets.dc.html`, `BulkImport.dc.html`)

### 8.1 Entries

- **Entry fields:**
  - Member, date, hours (0.25–24, 2 dp; warn above 12 h per member per day).
  - Billable (default on).
  - Project: a per-company list such as "Saarthi portal" or "Internal", managed in Settings › Projects.
  - Partner (defaults to the member's partner), notes.
- **Log hours modal:**
  - **Day mode:** date + hours.
  - **Month mode:** month + total hours, plus an "Include Saturdays" toggle and a live preview: "21 working days → 2.73 hrs/day · 25 days with Saturdays".
  - Month mode creates one entry per working day in a batch. Hours are distributed with largest-remainder rounding at 0.01 h so the sum equals the total exactly. Deleting the batch removes all its entries.
- **Page:**
  - KPIs: hours this month, billable, non-billable, logged today.
  - Team summary: hours and earnings per member this month.
  - Entries table: filters for month, member, partner, project and billable; 25 rows per page; edit and delete per permission.
- **Members** see and log only their own entries.

### 8.2 Bulk import (3 steps: Upload → Map columns → Preview; plus a second tab for manual monthly entries)

- **File:** `.csv` up to 5 MB and 5,000 rows, with a downloadable template.
- **Columns:**
  - Required: member (name or email), date, hours, project. Billable is optional (Yes/No, default Yes).
  - Aliases are auto-detected: "Log owner" → member, "Log Date" → date, "Log Hours" → hours, "Item Name" → notes/project. The mapping step lets the user override any of them.
- **Accepted formats:**
  - Dates: `2026-04-01`, `01/04/2026`, `1-Apr-26`, and `2026-04` (a monthly total, distributed as in month mode).
  - Hours: `2.5` or `2:30`.
- **Preview:**
  - Counts: rows in file, OK, need a fix, total hours to add.
  - Per-row errors: unknown member, bad date, hours out of range, duplicate of an existing entry, date in a closed FY.
  - Import commits valid rows in a queued job, then shows a toast with counts and a downloadable error CSV.
- **Manual monthly entries tab:** rows of member, month, hours, project/notes, billable.

### 8.3 Salary generation (scheduled job + "Run now")

- **When:** monthly, for the previous month (idempotent per member-month).
  - Hourly members: billable hours × the rate in effect.
  - Fixed members: monthly cost.
- **The expense:**
  - Description "Salary — {Name} — 2026-10", category Salary, dated the last day of the month, Approved, tagged **Auto**.
  - Paid from the member's partner's account (side account in a JV, company account in Single).
- **Re-running after hours change:** reverse and re-post. Auto rows can't be edited by hand.

## 9. Income (`Income.dc.html`)

- **Fields:**
  - Source: payer or client; free text with suggestions. Amount. Date received.
  - Brought in by: a partner. Optional in JV-pooled mode; used for per-partner sharing.
  - Received into: a money account, or a partner personally. Drives settlements.
  - Category: Project / Product / Consulting / Other + custom. Project (optional), notes.
  - Attachment: invoice PDF/JPG/PNG up to 10 MB.
- **Page:**
  - KPIs: total income, entries, active months. Monthly trend chart.
  - Table sortable by date, amount, source, partner and category, with edit and delete.
- **Posting:** income posts immediately; edits post reversals. Empty state: `StateEmpty.dc.html`.

## 10. Expenses (`Expenses.dc.html`, `MobileExpenses.dc.html`)

- **Fields:**
  - Description, amount, date.
  - Category: Salary, Software, Hardware, Travel, Marketing, Office & misc, plus "+ Add category" inline.
  - Submitted by: the current user, read-only.
  - Receipt: PDF/JPG/PNG up to 10 MB; on mobile, "Take photo".
  - Optional "Bill to a client" (project/client): it appears on that client's monthly summary.
- **Paid by:**
  - Mode is a segmented control: **Full** (one payer), **Half** (50/50 between two payers, odd paisa to the first), **Custom** (any number of payer lines).
  - Each payer line has a payer (partner, member or "Company account"), paid from (a money account or "Personal card (reimburse)") and an amount.
  - Validation: lines must equal the amount. Show the gap: "Payers add up to ₹5,100 more than the amount."
- **Status:** Pending → Approved / Rejected.
  - A rejection needs a reason. Partners can't approve their own expenses; Admins can.
  - Only approved expenses post to the ledger.
- **Editing:** editing an approved expense (Admin) posts a reversal and returns it to Pending, unless it falls in a closed FY.
- **List:**
  - KPIs: total, approved, pending approval.
  - Toolbar: search (description, member, payer) and filters (month, category, paid by, status).
  - Columns: description (Auto tag), category, amount, date, submitted by, paid by (avatar stack for splits), status, row actions (approve, reject, receipt, delete).
  - Pagination. Bulk select opens the bulk-action bar (Approve, Reject, Export). Export to CSV/XLSX.
- **Pages:**
  - Approvals queue: a filtered "Pending" view for approvers.
  - Receipt preview: a drawer showing the image or PDF.

## 11. Capital accounts (`CapitalAccounts.dc.html`)

- **KPIs:** total capital (closing), contributions this FY, drawings this FY, net profit YTD.
- **Statement:** per partner (grouped by side in a JV), Opening + Contributions − Drawings + Share of profit = Closing, with an FY selector. Closed FYs are read-only.
- **Contributions and drawings:** two ledgers, each with Record contribution / Record drawing modals (partner, amount, date, money account, note).

## 12. Partner loans (`PartnerLoans.dc.html`)

- **KPIs:** outstanding, total borrowed, repaid, open loans.
- **Loan ledger:** one row per loan with lender, date, amount, repaid, a progress bar and Open/Closed status.
- **Recording:**
  - Record loan: lending partner, amount, date, received into (money account), note.
  - Record repayment: loan, amount (≤ outstanding), paid on, paid from, note.
  - A loan closes automatically when outstanding reaches ₹0.
- **Converting:** a partner's personal-card balance can be converted into a loan (§4.2).

## 13. Settlements (JV only, `Settlements.dc.html`)

For each side s, over the selected period, excluding capital, loan and opening flows:
- **Cash collected_s:** income received into s's account(s), or personally by s's partners.
- **Cash paid_s:** expenses paid from s's account(s), plus personal-card payments by s's partners and by members billed to s's partners.
- **Net held_s** = collected_s − paid_s.
- **Pooled_s** = share_s × (company-account income − company-account expenses). The JV-level account belongs to all sides by share.
- **Entitled_s** = share_s × net profit.
- **Paid out_s / Paid in_s:** settlement transfers sent and received.
- **Remaining_s** = Net held_s + Pooled_s − Paid out_s + Paid in_s − Entitled_s. Status is **OWES** if > ₹1, **OWED** if < −₹1, **SETTLED** otherwise. The remainders always sum to 0 (invariant 5).

**Page:**
- JV company balance card.
- Banner, "A owes B ₹X": with two sides, the single amount. With more than two, the minimum set of transfers that settles everyone (greedy match).
- Per-side table: side, share, collected, paid, net held, entitled, paid out, paid in, remaining, status.

**Recording transfers:**
- The Record transfer panel has from side → to side, amount, date and reference (e.g. NEFT ref). From and to must differ. Warn if the amount exceeds what is owed.
- Recorded transfers list, newest first. Admins can edit or delete a transfer (with a reversal).
- Non-JV companies see the empty state: "Create partner sides in Settings to enable."

## 14. Credentials Vault (`Vault*.dc.html`)

### 14.1 Structure

- **Vaults:** per company, e.g. "Internal", "Saarthi Portal JV", "Asha Retail". Each item has a vault, a type, a name, a provider and tags.
  - It also has an environment (Production / Staging / Dev), favourite (per user), created by, rotated at, expires at, strength score and breached flag.
  - Smart filters: Favourites, Shared with me, Expiring, Weak.
- **Item types and their fields** (🔒 = encrypted):

| Type | Fields |
|---|---|
| Website login | URL, username/email, password 🔒, TOTP setup key 🔒, recovery codes 🔒 |
| API key / token | key 🔒, secret 🔒, scopes, environment, expiry |
| Server / SSH | host, port, user, password 🔒 or private-key file 🔒, passphrase 🔒 |
| Database | engine, host, port, database, user, password 🔒, connection string 🔒 |
| Hosting / cPanel / FTP | panel URL, FTP host, user, password 🔒, nameservers |
| Domain & DNS | registrar, domain, expiry, auto-renew, nameservers |
| Email account | address, password / app password 🔒, IMAP/SMTP settings |
| Payment gateway | mode test/live, publishable key, secret key 🔒, webhook secret 🔒 |
| Software licence | licence key 🔒, seats, renewal date |
| Secure note | note 🔒 |

- **Every item** can have custom fields (each marked secret or not), notes 🔒, and attachments 🔒 (keys, certificates, recovery files; up to 5 MB each).
- **Provider catalog:**
  - A seeded list with templates: Hostinger, Google Workspace, Gmail, GitHub, GitLab, Bitbucket, Vercel, Netlify, AWS, Google Cloud, Azure, DigitalOcean, Contabo, Hetzner, Cloudflare and GoDaddy.
  - Also Namecheap, Razorpay, Stripe, PayPal, Shopify, WordPress, Apple Developer, Google Play Console, Figma, Notion, Slack, Jira, OpenAI, Meta Business, LinkedIn, and Custom.
  - Providers show **monogram tiles** (GH, AW…), never other companies' logos.

### 14.2 Access

- **Share targets:** share a vault or a single item with users (and later, teams).
- **Permission levels:** **View** (metadata only: name, URL, username) · **Use** (reveal, copy, TOTP) · **Edit** · **Manage** (share, delete).
  - Access can end on a date ("Access ends"). Vault permissions are inherited by its items; item permissions can be stricter but never looser than the role allows.
- **Access requests:** a user asks for access with a reason. Managers approve or deny from the inbox and the requester is notified.
- **Offboarding:** when a user is removed from a company, show "N items they could use → rotate these" with Rotate actions.

### 14.3 Security (non-negotiable)

- **Encryption:**
  - Envelope encryption: each company gets a random 256-bit data key, wrapped with `VAULT_MASTER_KEY` (env). Secrets are encrypted with AES-256-GCM using that key.
  - Store only ciphertext plus nonce. Support key rotation (re-wrap data keys).
- **What leaves the server:**
  - Index and list endpoints never include secret values.
  - A separate `POST /vault/items/{id}/reveal` returns one field. It needs Use permission plus a step-up 2FA confirmation newer than the policy window ("Ask for a 2FA code again after", default 15 min). It is rate-limited (e.g. 30/min per user) and writes an audit event.
- **TOTP:** the server computes the current code and seconds remaining from the stored secret, and audits it as "used one-time code". The UI shows a countdown ring and refreshes every 30 s.
- **In the UI:**
  - Reveal auto-hides after 30 s. Copy clears the clipboard after the policy time (default 30 s, best effort) and audits "copied".
  - The vault locks after 15 minutes idle. Unlock with a 2FA code or the password (`VaultLockPolicies.dc.html`).
  - Users without 2FA enabled can't use the vault; prompt them to set it up.
- **Never leak secrets:** exclude secret attributes from activity-log diffs, logs, exceptions, queue payloads, Inertia shared props and search indexes. Redact them in error reports.
- **Reuse and breach checks:**
  - Reuse detection uses an HMAC fingerprint of each password (keyed by a separate secret), never plaintext comparison.
  - The breach check (opt-in per workspace) uses the Have I Been Pwned range API with k-anonymity (SHA-1 prefix only), queued. Store only `breached` + `checked_at`.

### 14.4 Features

- **Add item, step 1:** searchable provider catalog. **Step 2:** the template form.
  - The password generator popover has a length slider (12–64), symbols, and a words/passphrase mode, and shows strength using zxcvbn.
- **Item drawer (Vault home):**
  - Username, and password with Reveal/Copy. TOTP code with ring and copy. Open login URL.
  - Notes, environment, tags, shared-with avatars, activity timeline.
  - Actions: Share, Rotate, History, Edit.
- **Version history:** encrypted snapshots, with restore (audited).
- **Security health:**
  - Score 0–100: start at 100 and subtract, capped per category: weak −4 each, reused −5 each, older than 180 days −2 each, found in a breach −10 each, expiring within 30 days −2 each, login without 2FA −1 each. Floor 0.
  - Shows counts, fix-it lists with Rotate (opens edit with the generator and sets `rotated_at`), and items without 2FA.
- **Expiry alerts:** at 30, 7 and 1 days for items with `expires_at` (API keys, domains, SSL, licences, hosting renewals).
- **Audit log:** an append-only table of time, user, action, item, IP and device.
  - Actions: viewed, revealed, copied, used code, edited, shared, exported, unlocked, failed unlock.
  - Filters for date, person and action, plus export. Admin only.
- **Import and export:**
  - Import from CSV, Bitwarden (JSON/CSV), 1Password CSV, LastPass CSV or Chrome CSV, through the same upload → map → preview flow as §8.2.
  - Export is an encrypted, password-protected JSON file. Admin only, needs step-up, audited.
- **Policies:** re-auth interval, clipboard clear time, idle lock time, who can export, breach checks on or off.

## 15. Reports and statements

- **Reports** (`Reports.dc.html`):
  - Period picker (FY, Q1–Q4, month, custom) and an Export menu (PDF, Excel; queued if large).
  - KPIs: revenue, expenses, other expenses, net profit. Ratios: profit margin (net ÷ revenue), expense ratio (expenses ÷ revenue), average monthly revenue and expense (over months in the period up to today).
  - Monthly table: month, revenue, expenses, other, salary, profit, margin %. Partner P&L: equity %, revenue generated, expenses, other, net contribution, profit due (share of profit, §4.4), share %.
- **Trial balance** (`Statements.dc.html`):
  - Status banner "Balanced ✓", or "Out of balance — drift ₹X" with "Find the drift" listing unbalanced entries. This should never happen; it means a bug.
  - Accounts with debit and credit totals for the period, then totals.
- **Balance sheet** (`Statements.dc.html`):
  - As-of date. Liabilities and equity (loans, reimbursements, partner current credits, capital per partner, current-year profit) next to assets (money accounts, partner current debits).
  - Status banner.

## 16. Settings tabs (`Settings*.dc.html`)

- **Company:** see §3.2.
- **Partners & equity:**
  - Partner cards with equity bars.
  - Edit partner modal: name, role, share (of company, or of side in a JV), side, colour (partner-1…6), delete.
  - Delete is only possible with no records; otherwise archive. Totals are validated: 100% per side, and sides total 100%.
- **Partner sides (JV):** name, equity %, money account, partner count. Add, edit, and delete only if empty.
- **Members & invites:**
  - Users table: user, email, role (changeable), linked partner/member, joined, last active.
  - Team members grouped by partner: name, designation, rate, partner, status.
  - Remove a user (triggers the vault offboarding list).
  - Invite modal (§2.3) and pending invites.
- **Roles & permissions:** editable matrices (§2.1 and §2.2) with "Reset to defaults". The Admin column is locked.
- **Categories:** income and expense categories (add, rename, archive). Salary is a system category.
- **Projects:** name, client, status (Active/Archived). Used by timesheets, income and expenses.
- **Money accounts:** rename accounts, add extra bank/cash accounts, archive.
- **Notifications:** per-user, per-event email and in-app toggles.

## 17. Account (`Profile.dc.html`)

- **Profile:** full name, email (changes need verification), time zone, number format (Indian 12,34,567 / International 1,234,567), theme.
- **Password:** at least 12 characters, with a strength meter. Changing it signs out other sessions.
- **2FA:** TOTP QR setup, 10 recovery codes (regenerate), trusted devices (30 days, revocable).
- **Active sessions:** device, IP/location, last active, "Sign out" per session or "Sign out others". Use the database session driver.

## 18. Authentication (`SignIn`, `SignUp`, `AcceptInvite`, `StateSession`)

- **Sign in:**
  - Email + password, "Continue with Google" (Socialite), "Remember me on this device".
  - Lockout: after 5 failed attempts, a 15-minute lock. After 3 failures show "2 tries left before a 15-minute lock".
- **2FA challenge:** 6-digit code ("Codes change every 30 seconds"), "Trust this device for 30 days", "Use a recovery code", "Lost your device?" (contact the admin).
- **Sign up:** name, company name, work email, password (≥12, with strength meter), terms. Then email verification, then the create-company wizard. A 14-day trial with no card.
- **Forgot / reset password, verify email:** build from the Sign-in layout.
- **Session expired:** a re-auth dialog (2FA code) that keeps the user's current page and unsaved form state.

## 19. Notifications (`Notifications.dc.html`)

- **Where:** a top-bar bell with an unread count and a panel with filters (All, Approvals, Vault, Finance), plus a `/notifications` page. Kept for 30 days.
- **Events:**
  - Approvals: expense submitted (to approvers); expense approved or rejected (to the submitter).
  - Finance: settlement due (JV remainder above a threshold), FY close reminder.
  - Vault: credential expiring (30/7/1 days), vault access requested or decided, breach found.
  - Team and jobs: invite accepted, import finished, salary run done.
- Actionable items can be handled inline, e.g. approving an expense from the panel.
- **Channels:** database and mail, respecting each user's preferences. Email uses the brand template.

## 20. Billing and plans (`Billing.dc.html`, `Pricing.dc.html`)

- **Plans:** Starter, Growth, Business, Enterprise. Prices and limits are placeholders (`[PRICE]`, `[N]`) in a DB-backed `plans` table editable in the super-admin panel.
  - Limits: companies, users and vault items, plus features (JV and settlements, exports, SSO, audit-log export).
- **Billing page:** monthly/yearly; INR and USD; current plan, usage meters, invoices, payment method, Upgrade modal.
- **Payments:** Stripe via Cashier (Checkout + Billing Portal) first; Razorpay as a later option.
- **Limits:** soft. Creating past a limit opens the Upgrade modal. Existing data is never blocked from viewing.

## 21. Marketing site (`Landing.dc.html`, `Pricing.dc.html`)

- **Pages:** Landing, Pricing, a feature-detail template, Security, Contact / Book a demo (stores a lead and emails the team), and legal pages (Terms, Privacy, DPA).
- **SEO:** Inertia SSR, meta and OG tags, sitemap, robots.
- **Motion:** spotlight and parallax only here and on auth pages, with reduced-motion fallbacks.

## 22. Super-admin panel (Filament, `/admin`)

- **Access:** platform admins only (an `is_platform_admin` flag plus a separate gate). It is not the product UI and needs no Coffer design, but use brand colours in Filament's theme.
- **Workspaces:** list with plan, MRR, users, companies, status, created. Detail view with usage and subscription.
- **Admin actions:** suspend; impersonate (a reason is required; it is logged and shows a banner).
- **Also:** plans and limits editor; platform metrics (MRR, trials, churn, signups).

## 23. Non-functional requirements

- **Security:**
  - Transport and sessions: HTTPS and HSTS; a CSP with self-hosted fonts; CSRF; rate limits (login, reveal, exports, invites).
  - Accounts: argon2id/bcrypt passwords; 2FA required for Admins and vault users.
  - Code and checks: `composer audit` and `npm audit` in CI; aim for OWASP ASVS L2.
  - The activity log covers business records; vault events are immutable.
- **Privacy (DPDPA-ready):**
  - Per-company data export (ZIP of CSVs) and company/account deletion (soft delete for 30 days, then purge).
  - A DPA page, and an option to host in an India region.
- **Performance:** p95 server time under 300 ms on lists; pagination; indexes on `(company_id, date)`. Cache report aggregates per company and period, and invalidate on posting.
- **Reliability:** daily encrypted DB backups, queue retries, failed-job alerts, and a health-check endpoint.
- **Formats:** English UI. en-IN formats by default (Profile can switch the number format). Currency per company.
- **Accessibility:** WCAG 2.1 AA, as in the design system README.
- **Browsers:** the last 2 versions of Chrome, Edge, Safari and Firefox, plus mobile Safari and Chrome.

## 24. Demo data (seeders)

- **Workspace:** "Vertex Group", owned by Aarav Mehta (`aarav@vertexlabs.in`, Admin).
- **Companies:**
  - Saarthi Portal JV, a JV:
    - Vertex Labs side, 50%: Aarav Mehta (Admin) and Ishita Rao, each 50% within the side.
    - Bluefin Infotech side, 50%: Kabir Shah and Meera Iyer, each 50%.
  - Vertex Labs Pvt Ltd, Single: Aarav 60%, Ishita 40%.
  - Northstar Studio and Kriya Commerce, both Single.
- **Members** (billed to Vertex Labs or Bluefin partners):
  - Hourly: Rohan Desai (Senior Developer, ₹500/hr), Neha Joshi (QA Engineer, ₹200/hr), Sana Qureshi (iOS Developer, ₹450/hr).
  - Fixed monthly: Dev Patel (Project Manager, ₹40,000/mo), Vivaan Kapoor (UI Designer, ₹35,000/mo).
  - Also Arjun Nair, Priya Menon and Tanvi Shah.
- **Projects:** Saarthi portal, Asha Retail store, Kiran Exports site, Internal.
- **Activity:** income and expenses from Apr–Oct 2026 that roughly match the design figures (income ≈ ₹62,10,000, expenses ≈ ₹43,67,650), with "Vertex Labs owes Bluefin Infotech ≈ ₹3,24,600". Some expenses are left Pending.
- **Vault items:**
  - Hostinger hPanel (Asha Retail, Production), GitHub (vertexlabs org, with TOTP), Vercel team token (expires soon), Netlify.
  - Google Workspace admin, Gmail support (app password), AWS root (Production, Admins only).
  - Cloudflare DNS, Razorpay live keys, Contabo VPS SSH (203.0.113.24:22, key file), MySQL production DB, Domain saarthi-jv.in.
  - Use obviously fake secrets.

## 25. Improvements over the reference product (audited Nov 2026)

1. A real double-entry ledger. The reference showed "Books out of balance — drift ₹2,66,500".
2. Settlements always net to zero, including company-account flows. The reference showed "owes ₹12,67,405" against "owed ₹9,75,012".
3. Records can be edited, with reversals and audit. The reference was delete-only, and team members couldn't be edited.
4. An editable permission matrix. The reference's was display-only.
5. Report filters and PDF/Excel exports. The reference had none.
6. Approvals with reasons, bulk actions and receipts; money accounts; reimbursements; projects.
7. A Credentials Vault, billing and plans, and a super-admin panel (new).

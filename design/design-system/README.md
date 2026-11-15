Coffer's interface language: a white app shell on a soft gray canvas, flat cards, near-black figures and one electric lime. Numbers lead; lime marks what matters. Every colour token has a Light and a Dark value under the same name.

## Content

- Make the figure the biggest thing in every card and keep its label small, above or below it: "₹18,42,350" under "Current balance".
- Write money the Indian way: ₹2,66,500 and ₹34,61,915; compact on charts as ₹2.7L and ₹1.2Cr. Negative amounts read −₹2,66,500 (a true minus sign) in `negative`.
- Write dates as "19 Oct 2026" and the fiscal year as "FY 2026-27" (April to March).
- Show change as a trend chip: an up-right arrow icon and the value, "↗ 6%". Write comparisons as "up 6% on September".
- Speak to the reader as "you" and greet them by first name: "Hi Aarav,". Settlement and approval copy names both sides: "Vertex Labs owes Bluefin Infotech ₹3,24,600".
- Buttons are verbs: "Add expense", "Resolve", "Record transfer". Use Title Case for page and card titles, uppercase only for micro labels ("VAULTS", "OWES"), sentence case for body copy.
- No emoji. Status always carries an icon and a word, never colour alone.

## Colour

- Float the app shell (`surface`, `radius-xl`) on `canvas`. Fill cards with `surface-card`; fill secondary cards, the nav rail and control pills with `surface-muted`. Keep cards flat: no shadows, no borders.
- Set figures, headings and labels in `ink`; body copy, captions and axis labels in `ink-secondary`. Use `ink-faint` only for the light first line of the page title.
- Spend `lime` on one thing per region: the active nav item, the selected chip or row, the highlighted bar, the hero tile. Put `on-lime` on it. Never lime text or lime lines on a light ground.
- Use `graphite` for the primary action, count badges, the round sidebar action and strong panels (settlement banner, vault drawer header, toasts), with `on-graphite` labels and `on-graphite-muted` secondary text.
- Status pairs a strong colour (text, icons) with a soft fill (pills, banners):
  - positive: income, Approved, OWED, Settled. `positive` text; pills in `positive-soft` with `on-positive-soft`.
  - negative: −₹ amounts, Reject, Rejected, OWES, Breached. `negative`; `negative-soft` with `on-negative-soft`.
  - warning: Pending, expiring soon, Staging, Reused. `warning`; `warning-soft` with `on-warning-soft`.
  - info: Billable, Dev, tips. `info`; `info-soft` with `on-info-soft`.
- Give each partner one identity colour, `partner-1` to `partner-6`, in the order partners were added: the dot on their avatar, their slice and their bars. Members wear their partner's colour.
- Outline chips, icon buttons, inputs and menus with a 1px `line`; use `line-strong` for rail separators, chart baselines and drop zones.
- Show keyboard focus with `focus-ring` on every control.

### The gradient

- `gradient-lime` may appear on: the hero balance card, the arrow disc inside a primary button, KPI icon chips, the active nav item, the selected chart bar, the small square before a card title, toast icons and the logo disc.
- It may not appear: behind body text, tables, forms or inputs; as text; on more than one large surface per view; on status pills (they use the soft fills).
- Text and icons on it are always `on-lime`. Small text on the hero card sits in a frosted pill of `surface` at 60% opacity.

### Dark mode

- Switch themes by setting `data-theme="light"` or `data-theme="dark"` on the app root; components read the same token names in both. Offer the toggle in the top bar, next to notifications, and remember the choice per user.
- Lime, `on-lime` and `gradient-lime` stay the same in both themes; surfaces, ink, status and chart steps change. Strong panels stay dark in both themes (`graphite` lightens a step in Dark so it still separates from `surface`).
- Every text pair above meets 4.5:1 in both themes (3:1 for large text and marks); check new pairs in both themes before shipping.

## Type

- Three families, each with one job:
  - `display`, Bricolage Grotesque: the greeting and page titles (`display-light` over `display`), card titles (`title`), the balance (`figure-hero`), donut centres and the health score (`figure-centre`), modal titles (`heading`) and the wordmark.
  - `sans`, Plus Jakarta Sans: nav, buttons, labels, body text and menus (`label`, `label-strong`, `body`, `caption`, `micro`). 12px is the smallest size.
  - `mono`, IBM Plex Mono: every amount (`money-lg` in KPI tiles with a `money-unit` ₹, `money` in tables, forms, banners and the formula strip, `money-sm` in legends, tooltips and value chips), plus one-time codes (`code`), usernames and hosts.
- Set all money with `font-variant-numeric: tabular-nums` so digits line up in columns and while counting up.
- Load the faces from Google Fonts: Bricolage Grotesque with its optical-size axis (opsz 12–96, wght 200–800), Plus Jakarta Sans 300–600, IBM Plex Mono 400–500.

## Shape and space

- Make everything clickable round: `radius-full` pills for buttons, control pills, status pills and trend chips; circles for icon buttons and nav items (`icon-button`). Filter chips are the one exception: `radius-sm`.
- Round cards, modals and drawers with `radius-lg`; inputs, rows and tiles with `radius-md`; the app shell with `radius-xl`.
- Pad cards with `space-4`. Separate sibling tiles by `space-3`, card rows by `space-4`, main columns by `space-5`; keep `space-10` between the shell edge and its content.
- Size top-bar controls and the primary button at `control-lg`, toolbars and selects at `control-md`, filter chips and row actions at `control-sm`.
- The primary button is a `graphite` pill with its label on the left and a `gradient-lime` disc holding an arrow or plus icon on the right.

## Layout

- On desktop, run a vertical rail of circular nav items down the left edge (logo disc at the top, the round sign-out action at the bottom), then a top bar: wordmark, company switcher and ⌘K search on the left; date, theme toggle, notifications and the account menu on the right.
- Under the top bar, put the breadcrumb and two-tone title on the left and the period and primary controls on the right; then the card grid.
- Open each card with a small `gradient-lime` square and its `title`; then the lead figure with its trend chip; then captions.
- At phone width the rail becomes a row, toolbars wrap, tables scroll sideways inside their card and modals become full-height sheets.

## Data

- Draw single-series bars as `chart-bar`-wide pills `space-2` apart in `chart-ink`; mark the selected or latest bar with `gradient-lime` and its value in a small chip above it. Bars below zero hang under the baseline in `chart-negative`, with the value chip under them in `negative-soft`.
- Draw the main series of an area chart as a 2.5px `chart-ink` line over a `lime` fill fading to transparent; draw the comparison series dashed in `chart-muted`.
- For categories use `chart-1` to `chart-8` in order, never cycled; a ninth series folds into Other. Beside a partner chart, show a category split in the brand ramp instead (`chart-ink`, `lime`, `ink-secondary`, `ink-faint`, `line-strong`) so categories never borrow a partner's colour.
- Keep a legend for two or more series, label slices and bars directly where there is room, and offer a table view. Legends, tooltips and axis values are `money-sm` in `ink-secondary`; the chart's own colours mark identity only. No gridlines.

## Motion

- Hover lifts cards 3px and buttons 2px over `duration-ui` with `ease-out`. Fallback: no movement.
- Figures count up over `duration-count`; charts draw in over `duration-hero`. Fallback: final values shown at once.
- Drawers, dropdowns and modals enter over `duration-panel`; toasts slide in from the top right and leave after 2.6s. Fallback: they appear without sliding.
- The blurred lime glow behind the balance card drifts on a 16s loop with `ease-drift`. Fallback: it stays still.
- Inside the app the cursor stays native; spotlight borders, magnetic buttons, custom cursors and parallax belong to the marketing and sign-in pages only. Fallback: all of them off on touch devices and under `prefers-reduced-motion: reduce`.

## Iconography

- Use thin line icons (1.5px stroke, round caps) in `ink`, centred in a circle: `surface` with a `line` outline at rest, `gradient-lime` or `graphite` when active.
- No icon files are included yet. Lucide at a 1.5px stroke is the closest match: treat it as a stand-in until the real set is added.
- Providers in the vault show monogram tiles (GH, AW…) on `surface-muted`, never other companies' logos.

## Logo

- The logo is the Coffer wordmark in Bricolage Grotesque SemiBold and a `lime` disc holding a dark C. Files are under assets/Logos: wordmark and lockup in versions for light and dark grounds, the monogram, and the favicon set.
- Use the lockup where there is room and the monogram in the nav rail, favicon and app icons. Keep clear space of half the disc's diameter around either.
- Never recolour the disc, set the wordmark in another face, or place the on-light files on dark grounds. The smallest wordmark is 16px tall; the smallest monogram is 16px.

## Components

- The component library is plain HTML and CSS in `components/bundle.css`; every card under Components shows its states in light and dark side by side and starts with a one-line rule for when to use it. Class names start with `cf-` (components), `t-` (type styles) and `ic` (icons).
- States are the same everywhere: `:hover`, `:focus-visible` (the focus ring), `aria-pressed`/`aria-selected`/`aria-current` for selected, `disabled`, `aria-invalid` plus `cf-errtext` for errors, and `aria-busy` with `cf-spinner` or `cf-skel` for loading. The `.is-hover`, `.is-focus`, `.is-selected`, `.is-error` and `.is-loading` classes force a state for documentation only.
- Selection has two idioms: lime for choosing a value (segmented controls, filter chips, the selected bar), and an ink fill for where you are (tabs, the current page, the selected day).
- Money is always `t-money*` (IBM Plex Mono, tabular figures) with Indian grouping and −₹ for negatives. Status always pairs a colour with an icon or word.
- On a Design canvas or in the app, load the three Google fonts and `components/bundle.css`, then put `class="cf" data-theme="light"` (or `dark`) on the page root. The stylesheet carries a zero-specificity copy of the colour tokens for surfaces without tokens.css; update it when colour tokens change.

# QA sweep

`scripts/qa/sweep.mjs` is the responsive and dialog check for the whole app. **Run it before shipping
any UI work** (pages, CSS, kit components, dialogs) and fix what it reports, or add the finding to
`scripts/qa/allow.json` with the reason it is intended.

It drives headless Chrome over the DevTools protocol with Node's built-in `WebSocket` and `fetch`
(Node 22+, no packages). For every role it signs in through the real sign-in and 2FA forms, then
visits each page at the owner's widths (320, 375, 768, 1024, 1150, 1440, 1920 at 800 px tall, plus a
666 px short-laptop pass at 1150 and 1440) in both themes, and opens the scripted dialogs, menus and
date pickers.

## What it reports

| Kind | Meaning |
|---|---|
| `page-hscroll` | The document scrolls sideways. |
| `off-viewport` / `off-card` | An element runs past the viewport or its card, outside an intended scroll area. |
| `clipped-text` | Text cut by its own box or a clipping ancestor with no ellipsis; a `<select>` too narrow for its chosen option. |
| `overlap` | Two text boxes intersect (popovers and fixed bars floating over content are ignored). |
| `occluded` | Text covered by another box (checked in the viewport). |
| `popover-offscreen` / `popover-clipped` | A menu, popover or picker leaves the viewport or is cut by its scroll parent. |
| `dialog-capped` | A dialog scrolls inside although the viewport has room for it to grow. |
| `dialog-hscroll` / `dialog-offscreen` | A dialog scrolls sideways or leaves the viewport. |
| `shell-scrolls-away` | The nav rail or top bar scrolls off screen on app pages. |
| `under-sticky` | Text sits under a pinned (sticky-left) column while its scroller is at rest (scrollLeft 0). Nothing should be under it before you scroll. |
| `lead-scrolled-away` | A table scrolled sideways (a scenario's `scroll` step) loses a row's lead cell off the left edge: labels must stay pinned. |
| `visible-scrollbar` | A one-row strip (96 px tall or less) scrolls sideways with a scrollbar track: `offsetHeight − clientHeight` (less borders) above 0, or no `scrollbar-width: none` (headless phones draw overlay scrollbars, desktop browsers draw a track). |
| `empty-control` | A segmented-control option or tab whose label has no width or height, or shows less than 60% of its height (squashed or clipped by a parent). |
| `small-kpi` | An app KPI tile (`cf-kpi`) whose figure is smaller than its title or under 20 px (a count rendered as body text). |
| `scenario-failed` | A scripted dialog didn't open (its opener moved or was renamed). |

## How to run it

Use built assets on a second port, so the sweep never depends on (or disturbs) `composer run dev`
on port 8000. `VITE_HOT_FILE` points at a missing file so that server ignores `public/hot`.

```sh
npm run build
VITE_HOT_FILE=storage/qa-no-hot php artisan serve --port=8010 --no-reload   # in another terminal
npm run qa:sweep                      # everything: ~1 hour
npm run qa:sweep -- --quick           # Admin + signed out, a few pages, 375 and 1150
```

PHP must be 8.3+ on `PATH` (the sweep runs `php artisan coffer:demo-2fa` for the 2FA codes), or set
`COFFER_PHP`. Stop the 8010 server when you are done.

Options: `--roles=admin,partner,member,viewer,guest`, `--widths=375,1150`, `--themes=light`,
`--pages=/expenses,/vault` (also limits the dialogs to those routes), `--dialog=<name part>`,
`--no-pages`, `--no-dialogs`, `--no-shots`, `--headed`, `--base=http://127.0.0.1:8010`,
`--out=storage/qa/<name>`.

The demo accounts are local seeded data (password `password`): aarav@vertexlabs.in (Admin),
meera@bluefininfotech.in (Partner), rohan@vertexlabs.in (Member), accounts@ledgerworks.in (Viewer).

## Output

`storage/qa/<run>/` (gitignored): `report.md` (findings grouped across widths, themes and roles),
`report.json` (every finding with role, page, width, height, theme) and a PNG per
page / viewport / theme and per dialog. The command exits 1 when anything outside `allow.json` is found.

## Adding pages and dialogs

Pages and dialog scenarios live in `scripts/qa/routes.mjs`. A scenario is a route, the roles that
can open it and steps (`click` by visible text or selector, `wait` for a selector, `code` for a 2FA
step, `pause`, `scroll` a sideways scroller to its end). New pages and dialogs should be added there when they ship.

## Notes

- 2FA codes are single-use (D108): the sweep waits for the next 30-second window when the code it
  typed last is still current, so sign-in and the vault unlock can take up to half a minute.
- Content passing under a sticky or fixed bar (the top bar, a dialog's pinned header and footer,
  the phone tab bar) and popovers floating over content are not reported as overlap; content under a
  pinned table column at rest is (`under-sticky`). One-row strips (`cf-tabs`, `cf-scroll-row`) may
  scroll sideways, but never with a scrollbar track (`visible-scrollbar`). Visually hidden content (`cf-sr`) is skipped.
- Headless Chrome uses overlay scrollbars, so a page measured at 1150 px has the full 1150 px;
  a desktop browser with classic scrollbars has about 15 px less. Check layouts that switch near a
  width with that margin in mind.
- The baseline before the owner's QA round (19 Nov 2026) had 5,917 findings (356 distinct).
- After the round (19 Nov 2026, full run `storage/qa/b2`): 10 findings (5 distinct), all fixed
  and re-checked on the pages they touched (0 open), plus a pass over the archive / Edit loan /
  month-mode pages that landed during the run (0 open). The 40 allowed hits are the landing hero's
  designed overlap (`allow.json`).
- Owner QA round 3 (19 Nov 2026, D143): five new checks (`under-sticky`, `lead-scrolled-away`,
  `visible-scrollbar`, `empty-control`, `small-kpi`) and three scenarios (Log hours › phone sheet,
  whole month; Edit partner; Roles matrix › scrolled sideways). Run against the build before the
  fixes they caught four of the owner's thirteen items (the settings tabs scrollbar, the squashed
  Log hours switch, the small Entries / Active months / Open loans figures, the roles labels
  scrolling away); the rest are visual judgements the screenshots still need. After the fixes, the
  affected pages (marketing heroes and features, dashboard, partners, team, timesheets, income,
  expenses, capital, loans, settlements, reports, statements, vault audit and health, settings
  company / members / partners / roles) at every width, both themes, as Admin, Member and signed
  out: 0 open (runs `storage/qa/r3a`–`r3c`).
- The sweep unlocks the vault before visiting it, so it never measures the lock card; check it by
  hand at 320 and 375 px after changing it. It also reports a `<select>` whose chosen option ends
  in an ellipsis: prefer a label that fits.
- The sweep measures whatever the server runs. When someone else has uncommitted work (or an
  unrun migration) in the tree, serve a clean checkout instead: `git worktree add --detach
  ../coffer-qa HEAD`, junction `vendor` and `node_modules` into it, copy `.env` and
  `bootstrap/cache/*.php`, `npm run build` there and start the 8010 server from it.

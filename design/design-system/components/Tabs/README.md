# Tabs

Use pill tabs to switch between sections of one page (Settings: Company, Members & invites, Roles, Partners & equity, Partner sides) or between filtered views with counts (team by partner).

- Markup: `<div class="cf-tabs" role="tablist" aria-label>` of `cf-tab` buttons with `role="tab"` and `aria-selected`; links styled as tabs use `aria-current="page"`. Counts go in `cf-tab-count`.
- Arrow keys move between tabs. The selected tab is the ink fill so it reads differently from lime filter chips.

# Side drawer

Use a side drawer to show or edit one record's details without leaving the list (a vault item, a settlement, a transfer): a graphite header with the identity and key figure, then a `surface-card` body.

- Markup: `cf-drawer` (400 px, or `cf-drawer--float` to overlay the page) › `cf-drawer-head` › `cf-drawer-body`. The list stays usable beside an inline drawer; a floating drawer adds a scrim on small screens.
- Header money uses IBM Plex Mono; pills on the graphite header keep their soft backgrounds. Slides in with `cfSlide` over `duration-panel`.

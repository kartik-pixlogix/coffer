# Data table

Use the data table for every list of records (expenses, income, timesheets, transfers, audit log): white rounded rows on a `surface-card` panel, money right-aligned in mono, status as pills and actions at the far right.

- Markup: `cf-table` (`role="table"`) › `cf-tr cf-thead` header row with `role="columnheader"` and `aria-sort` › `cf-tr` rows with `role="row"` / `role="cell"`; set the grid columns on the rows. Wrap wide tables in `cf-table-wrap` so they scroll on small screens.
- Sort: `cf-sort` buttons in the header; the sorted column reads `aria-sort="ascending|descending"`.
- Rows: hover `surface-muted`; selected `.is-selected` / `aria-selected` on the `selected` tint with its checkbox ticked; `.is-error` for an import or validation problem; `.is-total` for a totals row.
- Loading: skeleton rows with `aria-busy`. Empty: `cf-empty-row` with a way out ("Clear filters").

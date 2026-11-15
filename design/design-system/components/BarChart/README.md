# Bar chart

Use the bar chart for one value per period, such as profit by month: 22 px rounded `chart-ink` bars, negative values hanging below the axis in `chart-negative`, and the selected bar in the lime gradient.

- Markup: `cf-bars` (positive row, bars aligned to the axis) above `cf-bars-neg` (negative row); each bar is a `<button class="cf-bar">` with an `aria-label` holding month and value; `aria-pressed="true"` selects it; `.is-neg` for negatives.
- Label only the selected bar (a `cf-pill`) and show the rest on hover with `cf-tip`. Axis months sit under the chart in `cf-axis-x`.
- Loading: skeleton bars. Empty: say when bars will appear. Offer the numbers as a table for screen readers.

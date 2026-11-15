# Area and line chart

Use the area chart for a trend over time with one main series (revenue) and at most one comparison (expenses): solid `chart-ink` line over a fading lime wash, comparison as a dashed `chart-muted` line.

- SVG classes: `cf-line-ink`, `cf-line-muted`, `cf-area-stop` (gradient stops), `cf-baseline`, `cf-crosshair`, `cf-marker`; keep strokes `vector-effect="non-scaling-stroke"`.
- Hover shows a crosshair and a `cf-tip` listing each series with its swatch and a mono value. Y-axis labels are compact (₹12L); tooltips are exact (₹10,85,000).
- One y-axis only. If two measures need different scales, use two charts.

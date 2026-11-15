# Chart tooltip and legend

Use the graphite tooltip for every chart hover (period on top in `on-graphite-muted`, exact ₹ values in mono) and a legend above the plot whenever a chart has two or more series.

- Tooltip: `cf-tip` (positioned) or `cf-tip--static`; rows `cf-tip-row`; add `cf-tip--arrow` when it points at a single bar.
- Legend: `cf-legend` with `cf-swatch` (dot), `cf-swatch--line` (dashed comparison) or `cf-swatch--sq` (grouped bars). Legend text stays `ink-secondary`; only the swatch carries colour.

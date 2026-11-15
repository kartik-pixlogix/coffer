# KPI tile

Use a KPI tile for one headline number per tile (income, expenses, net profit, hours, pending), in rows of three to six, with the trend or status chip in the top-right and one line of context underneath.

- Markup: `cf-kpi` › `cf-kpi-head` (`cf-kpi-title` with a `cf-kpi-ic` lime chip, then a `cf-trend` or `cf-pill`) › `cf-kpi-value` (`t-money-unit` ₹ + `t-money-lg`) › a caption.
- Figures count up over `duration-count` on first load (not under reduced motion). Hover lifts 3 px; `.is-selected` rings the tile when it filters the page.
- Loading: skeleton bars with `aria-busy`. Error: say it couldn't load and offer Try again; never show ₹0 for a failed load.

# Select

Use a native select for short, fixed lists (up to about ten options) such as category, currency, role or month; switch to Combobox when people need to search.

- Markup: `<span class="cf-select">` › `<select>` + the chevron SVG. `cf-select--pill` is the 40 px rounded version for toolbar filters.
- The native menu follows the theme through `color-scheme`, so dark mode needs no extra work.
- Error: `cf-select is-error` on the wrapper and `aria-invalid` on the select.

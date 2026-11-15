# Combobox

Use a searchable combobox when the list is long or grows over time (members, partners, projects, linked user accounts): type to filter, arrow keys to move, Enter to pick.

- Markup: `cf-inwrap` with `<input role="combobox" aria-expanded aria-controls aria-activedescendant>` and a `cf-pop` › `cf-listbox` of `cf-option` items; the matched text is wrapped in `<mark>`.
- Active option: `.is-active`; picked option: `aria-selected="true"` (shows the check); unavailable: `aria-disabled="true"`.
- No match: say so and offer to create the thing when that's allowed.

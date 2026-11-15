# Text input

Use a text input for single-line free text such as names, descriptions and short codes, always with a visible label above it.

- Classes: `cf-field` (label column) › `cf-label` + `cf-input` (48 px) or `cf-input cf-input--sm` (40 px in dense forms). Help text `cf-help`, errors `cf-errtext` with the alert icon, linked by `aria-describedby`.
- States: hover darkens the border to `line-strong`; focus uses the `focus` border plus a soft ring; `disabled` and `readonly` sit on `surface-muted`; errors set `aria-invalid="true"` and a `negative` border.
- Loading (an async check): wrap it in `cf-inwrap` and put `cf-spinner` at the end.
- Error copy says how to fix it: "Add a company name.", not "Invalid input".

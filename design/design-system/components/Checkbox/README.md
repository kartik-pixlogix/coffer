# Checkbox

Use a checkbox for independent yes/no choices saved with a form and for selecting table rows; the header checkbox shows a dash when only some rows are selected.

- Markup: `<label class="cf-check">` › `<input class="cf-sr" type="checkbox">` + `<span class="cf-check-box">` holding the check SVG + label text.
- Checked fills with `ink` (near-black in light, near-white in dark) so it stays clear in both themes. Error: `.is-error` on the label, message in `cf-errtext`.

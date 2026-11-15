# Radio

Use radio buttons when there are two to five mutually exclusive options that need a sentence each, like Per partner versus JV pooled income attribution; for short labels prefer Segmented control.

- Markup: a `<fieldset>` with a `<legend class="cf-label">`, then `<label class="cf-radio">` › `<input class="cf-sr" type="radio" name>` + `<span class="cf-radio-dot">` + text.
- Checked is a thick `ink` ring. Error: `.is-error` on each option and one `cf-errtext` under the group.

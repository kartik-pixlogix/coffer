# Toggle

Use a toggle for a setting that takes effect immediately, like Include Saturdays, Billable or Require 2FA; use a checkbox when the choice is only saved with the form.

- Markup: `<label class="cf-switch">` › `<input class="cf-sr" type="checkbox" role="switch">` + `<span class="cf-switch-track">` + the label text. The real input stays focusable.
- On: graphite track with a lime thumb. Off: `line-strong` track with a white thumb. Disabled at 45%.
- If saving can fail, show `cf-spinner` while it saves and put it back with an error message if it does.

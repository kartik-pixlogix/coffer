# Segmented control

Use a segmented control to choose one of two to four options that change the form below it, like Full / Half / Custom / Direct company for who paid, Day / Month for hours or Hourly / Fixed monthly for cost.

- Markup: `<div class="cf-seg" role="group" aria-labelledby>` of `cf-seg-btn` buttons with `aria-pressed`; `cf-seg--sm` is 32 px.
- The chosen option is solid lime with `on-lime` text in both themes; hover lifts an option onto `surface`.
- Changing the option must keep what the person already typed where it still applies.

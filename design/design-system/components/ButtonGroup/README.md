# Button group

Use a button group to switch between two to five related views of the same data, like Month / Quarter / FY or list and grid, with exactly one pressed button.

- Markup: `<div class="cf-btn-group" role="group" aria-label="…">` holding `cf-btn cf-btn--sm` buttons with `aria-pressed`.
- The pressed button sits on `surface` inside the `surface-muted` track; the others are text-only until hovered.
- For choosing a form value (Full / Half / Custom) use Segmented control instead: its selection is lime.

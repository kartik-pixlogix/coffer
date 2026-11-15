# Tooltip

Use a tooltip to name an icon-only control (every nav rail item) or explain a figure in one line; it shows on hover and keyboard focus after a short delay and never holds actions.

- Markup: `<span class="cf-tooltip" role="tooltip" id>` linked from the trigger with `aria-describedby`; graphite with an arrow, 12 px text, mono for money.
- Icon-only buttons still need their own `aria-label`; the tooltip is for sighted mouse and keyboard users.

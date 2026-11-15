# Popover

Use a popover for a small set of controls tied to one field or button (password generator, column picker, quick filters) that the person can dismiss without losing the page.

- Markup: `cf-pop cf-popover` with `role="dialog"` and a title; it floats on `shadow-pop` with a `line` border and closes on Esc or an outside click.
- Keep it to one task and one primary action; anything longer belongs in a modal.

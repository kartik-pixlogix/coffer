# Dropdown menu

Use a dropdown menu for a list of commands behind one button (Export, a row's ⋯ actions, the account menu); destructive items go last, below a separator, in `negative`.

- Markup: trigger with `aria-haspopup="menu"` and `aria-expanded`; `cf-pop cf-menu` (`role="menu"`) of `cf-menu-item` buttons (`role="menuitem"`), optional `cf-menu-label` group title, `cf-menu-sep`, and a `cf-kbd` shortcut.
- Arrow keys move, Enter runs, Esc closes and returns focus to the trigger. Explain a disabled item in its label ("(Growth plan)").

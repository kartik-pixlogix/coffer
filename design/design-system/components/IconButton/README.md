# Icon button

Use a round icon button for compact, repeated actions such as copy, approve, reject, theme and notifications, and always give it an `aria-label`.

- Classes: `cf-iconbtn` (40 px), `cf-iconbtn--lg` (54 px, top bar), `cf-iconbtn--sm` (32 px, table rows). Variants: `--pos` (approve), `--neg` (reject, red icon), `--graphite`, `--glass` (on graphite panels), `--bare` (no border).
- States: hover fills `surface-muted`; `aria-pressed="true"` turns it lime for view toggles; `disabled` at 45%; loading shows `cf-spinner` with `aria-busy`; `.is-error` outlines it in `negative`.
- Touch targets: keep 32 px buttons at least 8 px apart; use 40 px or larger on touch screens.

# Health score ring

Use the health ring for the vault's security score out of 100, with the score in Bricolage Grotesque in the middle and the counts that make it up listed next to it.

- Same ring as TOTP: r 26 in a 60 viewBox, dasharray 163.36, offset = 163.36 × (1 − score / 100). Lime bar on graphite, ink bar on surface, `.is-warn` below 60.
- The `aria-label` carries the score; the number in the middle is `aria-hidden`.

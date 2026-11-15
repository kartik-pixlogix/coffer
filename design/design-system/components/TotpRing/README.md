# TOTP countdown ring

Use the countdown ring beside a live one-time code so people can see how long the code stays valid; the code itself is `t-code` in IBM Plex Mono, split 3 + 3.

- Markup: `<span class="cf-ring" role="timer" aria-label="18 seconds left">` › SVG with `cf-ring-track` and `cf-ring-bar` circles (r 20, dasharray 125.66, offset = 125.66 × (1 − left / 30)) › `cf-ring-label`.
- On graphite add `cf-ring--dark` (lime bar); on surface use `cf-ring--onlight` (ink bar). Add `.is-warn` in the last 5 seconds. Under reduced motion the ring steps once per second without easing.

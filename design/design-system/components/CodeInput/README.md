# 6-digit code input

Use the code input for 2FA sign-in, vault unlock and Reveal checks: six boxes in IBM Plex Mono, auto-advancing, with paste filling every box at once.

- Markup: `<div class="cf-code">` of six `<input class="cf-code-box" inputmode="numeric" maxlength="1" aria-label="Digit n">` with a `cf-code-sep` after the third; wrap in a fieldset with a legend.
- Submit automatically on the sixth digit. Error: `.is-error` on the group, clear the boxes and refocus the first. Verifying: `.is-disabled` plus a spinner line.

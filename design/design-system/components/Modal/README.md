# Modal

Use a modal for a short, focused task that must finish or be cancelled before returning to the page (add expense, record contribution, invite user): a lime icon disc and Bricolage title, the form, then Cancel and the primary pill in the footer.

- Markup: `cf-scrim` + `<div class="cf-modal" role="dialog" aria-modal="true" aria-labelledby>` › `cf-modal-head` › body › `cf-modal-foot`. Widths: 600 default, `cf-modal--sm` 440, `cf-modal--lg` 760.
- Focus moves to the first field on open and is trapped; Esc and the close button cancel; the scrim does not close a form with unsaved input.
- Opens with `cfPop` over `duration-panel`; under reduced motion it simply appears.

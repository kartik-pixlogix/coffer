# Confirmation dialog

Use a confirmation dialog before anything destructive: name the thing and its amount, say what happens, and label the buttons with the outcome ("Delete expense", "Keep it"); for irreversible, company-wide actions like Close FY, make the person type the exact phrase first.

- Markup: `cf-modal cf-modal--sm` with `role="alertdialog"`; the destructive button is `cf-btn--danger`, the safe one is secondary and gets initial focus.
- Type-to-confirm: show the phrase in `cf-confirm-code`; keep the danger button disabled until the input matches exactly.

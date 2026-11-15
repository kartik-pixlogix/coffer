# Stepper

Use a stepper at the top of a multi-step flow (create company, bulk import, add vault item) to show where the person is and what's left; finished steps can be revisited, future ones can't be skipped.

- Markup: `<ol class="cf-stepper">` of `cf-step` items with a `cf-step-n` number disc and `cf-step-line` connectors. Done: `.is-done` (lime disc with a check). Current: `aria-current="step"` (ink disc).

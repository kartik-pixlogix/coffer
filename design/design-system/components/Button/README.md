# Button

Use the graphite primary pill with the lime arrow disc for the one main action on a screen, secondary for the next action, ghost for low-emphasis actions, and destructive only for delete, reject and other actions that can't be undone.

- Classes: `cf-btn` plus `cf-btn--primary`, `cf-btn--secondary`, `cf-btn--ghost`, `cf-btn--danger` (also `cf-btn--muted`, `cf-btn--lime`, `cf-btn--glass` on graphite panels). Add `cf-btn--disc` and a `<span class="cf-disc">` icon for the arrow-disc pill.
- Sizes: `cf-btn--lg` 54 px (page headers, modal footers), default 40 px (toolbars, cards), `cf-btn--sm` 32 px (rows, inline).
- States: `:hover` lifts the primary pill 2 px and brightens it; `:focus-visible` draws the focus ring; `disabled` drops to 45% opacity; `.is-loading` swaps the disc icon for `cf-spinner` and sets `aria-busy`; `.is-error` adds a negative outline next to an error message.
- Copy: a verb, sentence case ("Save expense", "Record transfer"). One primary per view.

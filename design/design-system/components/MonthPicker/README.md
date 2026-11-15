# Month picker

Use the month picker when the record belongs to a whole month (monthly hours, salary runs, the Expenses month filter); it pages by financial year, April to March.

- Trigger: a secondary button with a calendar icon showing "Oct 2026". Popover: `cf-pop` › `cf-monthgrid` of `cf-month` buttons.
- States: `aria-pressed="true"` selected, `.is-current` this month, `disabled` for months that can't hold entries yet.

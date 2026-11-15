# Bulk-action bar

Use the graphite bulk-action bar in place of the table toolbar while rows are selected: it states the count and total, then offers the actions that apply to every selected row.

- Markup: `cf-bulkbar` (`role="region"` with an `aria-label` like "3 expenses selected") › `cf-bulkbar-count` › `cf-btn--lime` for the main action, `cf-btn--glass` for the rest, and a clear-selection icon button.
- While it runs, disable the buttons and show the spinner; afterwards, a toast reports what happened.

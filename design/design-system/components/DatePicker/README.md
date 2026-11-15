# Date picker

Use the date picker for single dates (expense, income, transfer, contribution) and date ranges (reports); dates read "22 Oct 2026" and weeks start on Monday.

- Field: `cf-inwrap` with a typed input plus a calendar icon button; the popover is `cf-pop cf-cal` with a 7-column `cf-cal-grid` of `cf-day` buttons.
- Day states: `aria-pressed="true"` selected (ink fill), `.is-today` (ink ring), `.is-range` (selected tint), `.is-muted` (other month), `disabled` (closed FY, future-only rules).
- Typed dates are parsed leniently (1-Apr-26, 01/04/2026) and echoed back in the house format.

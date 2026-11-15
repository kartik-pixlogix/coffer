# Amount input

Use the amount input for every rupee value: a ₹ prefix, IBM Plex Mono with tabular figures, and Indian digit grouping applied as the person types.

- Markup: `cf-inwrap` › `<span class="cf-prefix" aria-hidden="true">₹</span>` + `<input inputmode="numeric">`; the label says what the amount is ("Amount", "Opening balance").
- Store paise as integers; display ₹1,14,900. Never accept a typed minus: a refund or drawing is its own record.
- Errors explain the gap ("Payers add up to ₹5,100 more than the amount."). Use `cf-inwrap--sm` with right-aligned text inside split rows.

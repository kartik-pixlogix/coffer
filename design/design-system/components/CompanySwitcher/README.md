# Company switcher

Use the company switcher in the top bar to move between the companies a person belongs to; each row shows the company type and the person's role there, and the list ends with New company.

- Trigger: `cf-switcher` (54 px pill) with the graphite initials disc, name and "Joint Venture · Admin". Menu: `cf-pop cf-menu` with `role="listbox"`, the current company `aria-selected="true"` with a check.
- Switching keeps the person on the same page in the new company when they can see it there, otherwise it goes to Dashboard.

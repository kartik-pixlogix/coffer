# Status pill

Use a status pill for the state of a record (Approved, Pending, Rejected, Billable, Non-billable, OWES, OWED, Settled, Auto, Production, Staging, Dev), always with an icon or a word that carries the meaning without colour.

- Classes: `cf-pill` (neutral) plus `--pos` (Approved, OWED, Settled, income), `--warn` (Pending, Staging, expiring), `--neg` (Rejected, OWES, breached), `--info` (Billable), `--graphite` (Production), `--outline` (Dev), `--lime` (New). `--micro` is the caps style for settlement states; `--sm` is 20 px.
- Pills are not buttons. To filter by status use Filter chips.

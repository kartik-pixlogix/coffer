# Password field

Use the vault password field to show a stored secret masked, with Reveal (after a 6-digit check) and Copy; use the plain sign-in variant with a show/hide eye for your own password.

- Vault: `cf-inwrap` › mono secret text + Reveal (`cf-btn--secondary cf-btn--sm`) + Copy (`cf-iconbtn --sm --bare`). Revealed secrets hide again after 30 seconds, and copied ones are cleared from the clipboard after 30 seconds; say so in the help text.
- Verifying: replace Reveal with a loading button and keep the secret masked. Copy confirms with a check for two seconds.
- Every reveal and copy is written to the audit log.

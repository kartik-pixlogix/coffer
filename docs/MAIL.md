# Mail: local and production

Coffer sends these emails: confirm your email, password reset, company invites, invite
renewal requests, notifications (approvals, payments, vault events) and the contact /
Book a demo lead. Every one is **queued on the `mail` queue** (D119), so a worker must run.

## Local

- `.env.example` ships with `MAIL_MAILER=log`. Nothing is delivered: each message is
  written to `storage/logs/laravel.log`. The "Confirm your email" page says so while
  `APP_ENV=local` and the mailer is `log`. Open the log and copy the link.
- `composer run dev` starts the app, Vite and one worker that listens on every queue
  (`default,mail,exports,imports,vault`). Without it queued mail never leaves.
- To see real messages, use a local catcher, e.g. Mailpit (`mailpit` on port 1025, web UI
  on 8025):

  ```env
  MAIL_MAILER=smtp
  MAIL_HOST=127.0.0.1
  MAIL_PORT=1025
  MAIL_USERNAME=null
  MAIL_PASSWORD=null
  MAIL_FROM_ADDRESS="hello@coffer.test"
  ```

  Then `php artisan config:clear`.

## Production (SMTP or a provider)

```env
MAIL_MAILER=smtp            # or postmark / ses / resend
MAIL_SCHEME=smtps           # port 465; leave null for STARTTLS on 587
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=465
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=hello@your-domain.com
MAIL_FROM_NAME="Coffer"
```

- Set SPF, DKIM and DMARC for the sending domain (docs/DEPLOY.md §9).
- Horizon (or `queue:work --queue=default,mail,...`) must be running; check `/horizon`.
- Test: sign up with a new address (confirm-your-email arrives), invite yourself to a
  company, and send the contact form.
- A failed send is logged at critical with the job class (D97) and retried by the queue.

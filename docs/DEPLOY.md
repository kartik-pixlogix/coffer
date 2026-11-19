# Deploying Coffer

A runbook for one Linux VPS (Ubuntu 24.04 LTS) managed with **Laravel Forge** or **Ploi**, with
notes for doing it by hand and an optional Docker outline. It ends with the **production
checklist**, the acceptance criterion of Phase 9: every box ticked before real customers.

Decisions behind this: D92–D99 in `docs/DECISIONS.md`. Billing go-live: `docs/BILLING_TESTING.md` §7.

---

## 1. What runs where

| Process | Command | Managed by |
|---|---|---|
| Web (PHP-FPM + nginx) | `public/index.php` | Forge / Ploi site |
| Queue workers | `php artisan horizon` | supervisor (Forge "Daemon", Ploi "Daemon") |
| Scheduler | `php artisan schedule:run` every minute | cron (Forge "Scheduler", Ploi "Cronjob") |
| SSR for the marketing site | `php artisan inertia:start-ssr` | supervisor |
| MySQL 8 | `coffer` database | the server, or a managed database |
| Redis 7 | queues, cache, Horizon | the server |

Queues (D95): `default`, `mail`, `exports`, `imports`, `vault`. Horizon runs two supervisors:
`app` (default + mail) and `heavy` (exports + imports + vault), configured in `config/horizon.php`.

## 2. Server

- **PHP 8.3 or newer** (8.4 recommended) with these extensions: `bcmath`, `ctype`, `curl`, `dom`,
  `fileinfo`, `gd` (PDF exports), `intl` (Indian number formatting), `mbstring`, `openssl`,
  `pcntl` and `posix` (Horizon), `pdo_mysql`, `redis` (phpredis), `sodium` (vault export, Argon2id),
  `tokenizer`, `xml`, `zip` (backups and data exports). Check with `php -m`.
- **Composer 2**, **Node.js 22 LTS or newer** (builds and the SSR server).
- **MySQL 8.0+** (MariaDB 10.6+ works; PostgreSQL 16 is supported by the migrations and the
  backup command but not exercised in CI). `utf8mb4`, `sql_mode` default.
- **Redis 7** bound to 127.0.0.1 with a password.
- **mysqldump** (comes with the MySQL client) for backups, and **7-Zip** (`p7zip-full`) for the
  restore drill.
- Timezone of the OS: UTC. The app shows India time (`APP_TIMEZONE=Asia/Kolkata`).
- An India region (e.g. Mumbai) if customers need in-country hosting (spec §23).

### Forge
1. Create a server (App server, PHP 8.4, MySQL 8, Redis). Forge installs nginx, PHP-FPM,
   supervisor and Redis.
2. Create a site for `app.example.com` (Project type: Laravel, web directory `/public`).
3. Connect the Git repository, branch `main`. Don't enable "Install Composer dependencies" twice:
   the deploy script below does it.
4. SSL: Let's Encrypt for the domain, then "Force HTTPS".
5. Daemons (supervisor): `php artisan horizon` (user `forge`, directory the site), and
   `php artisan inertia:start-ssr`.
6. Scheduler: enable "Laravel Scheduler" for the site (`* * * * * php artisan schedule:run`).
7. Environment: paste the production `.env` (section 4).

### Ploi
Same steps: Server (PHP 8.4, MySQL 8, Redis) → Site → Repository → SSL (Let's Encrypt) →
Daemons (`php artisan horizon`, `php artisan inertia:start-ssr`) → Cronjobs
(`php /home/ploi/app.example.com/artisan schedule:run`, every minute) → Environment.

### By hand (supervisor)
```ini
; /etc/supervisor/conf.d/coffer.conf
[program:coffer-horizon]
command=php /var/www/coffer/artisan horizon
user=www-data
autostart=true
autorestart=true
stopwaitsecs=660
redirect_stderr=true
stdout_logfile=/var/www/coffer/storage/logs/horizon.log

[program:coffer-ssr]
command=php /var/www/coffer/artisan inertia:start-ssr
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/www/coffer/storage/logs/ssr.log
```
`stopwaitsecs` is longer than the longest job (600 s) so a deploy never kills an import halfway.
Cron: `* * * * * www-data php /var/www/coffer/artisan schedule:run >> /dev/null 2>&1`.

## 3. Deploy script

Forge / Ploi deploy script (the order matters: maintenance mode, code, dependencies, build,
migrate, caches, restart the long-running processes):

```bash
cd /home/forge/app.example.com
php artisan down --render="errors::503" --retry=60 || true

git pull origin main
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build:ssr            # vp build && vp build --ssr → public/build + bootstrap/ssr
                             # (Wayfinder runs php while building: PHP must be on PATH)

php artisan migrate --force  # plans are seeded by their migration; never run the demo seeders
php artisan storage:link
php artisan filament:upgrade # Filament's own CSS/JS for /admin (git-ignored)
php artisan optimize         # config, routes, events, views
php artisan horizon:terminate          # supervisor restarts Horizon with the new code
php artisan inertia:stop-ssr || true   # supervisor restarts SSR with the new bundle
php artisan queue:restart              # for any plain queue:work process

php artisan up
```

Notes:
- `composer.json` declares `ext-pcntl` / `ext-posix` under `config.platform` so Windows dev
  machines can install Horizon; the server has the real extensions (D95).
- `bootstrap/ssr` and `public/build` are built on the server and git-ignored.
- Zero-downtime: Forge's "Zero Downtime Deployments" (Envoyer-style releases) work as long as
  `storage/` is shared between releases and `storage:link` is re-run per release.
- **SSR** (D83): server-side rendering is on for the marketing routes only (`welcome`,
  `marketing.*`). The SSR server listens on 127.0.0.1:13714 (`config/inertia.php`); if it is down,
  Inertia falls back to client rendering, so the site stays up but crawlers get an empty root.
  Check it with `curl http://127.0.0.1:13714/health` → `{"status":"OK"}`, and that
  `curl -s https://app.example.com/ | grep '<h1'` shows the hero headline. App pages behind sign-in
  render in the browser. In local development the `@inertiajs/vite` plugin serves SSR from the
  Vite dev server, so no SSR process is needed there. Verified 17 Nov 2026: `/` and `/pricing`
  came back with the title, description, canonical, Open Graph, JSON-LD and the `<h1>` rendered on
  the server; `/login` returned an empty root, as intended (this section replaces
  `docs/DEPLOY_NOTES_SSR.md`).

## 4. Environment (`.env`)

Start from `.env.example`: every key the config reads is listed there (a test keeps it that way,
D99). Production values that differ from the example:

```dotenv
APP_ENV=production
APP_DEBUG=false                         # never true in production: debug pages expose paths and config
APP_URL=https://app.example.com        # canonical, OG and signed URLs use it; https
APP_KEY=                               # php artisan key:generate --show, once; never change it
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=30

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=coffer
DB_USERNAME=coffer
DB_PASSWORD=<strong>

SESSION_DRIVER=database                # spec §17: Sessions list and "sign out others"
SESSION_SECURE_COOKIE=true             # defaults on in production anyway (D93)
SESSION_ENCRYPT=true

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_PASSWORD=<strong>
REDIS_QUEUE_RETRY_AFTER=660            # > the longest job (600 s)

MAIL_MAILER=smtp                       # or postmark / ses / resend
MAIL_FROM_ADDRESS=hello@example.com

VAULT_MASTER_KEY=base64:...            # section 6
VAULT_FINGERPRINT_KEY=base64:...
VAULT_HIBP_ENABLED=false               # opt in per workspace once reviewed

BACKUP_PASSWORD=<long random>          # section 7
BACKUP_DISK=backups-offsite            # an S3-compatible disk in another region (add it to config/filesystems.php)
HEALTH_CHECK_TOKEN=<long random>       # section 8
HORIZON_NOTIFY_EMAIL=ops@example.com

STRIPE_KEY=pk_live_...                 # docs/BILLING_TESTING.md §7
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_...=price_...

GOOGLE_CLIENT_ID=...                   # optional "Continue with Google"
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"

MARKETING_CONTACT_EMAIL=sales@example.com
PLATFORM_MFA_REQUIRED=true
PLATFORM_ADMIN_EMAIL=                  # empty after the first admin exists
PLATFORM_ADMIN_PASSWORD=
```

After changing `.env`: `php artisan optimize` (config is cached) and `php artisan horizon:terminate`.

## 5. First deploy, once

```bash
php artisan migrate --force
php artisan coffer:platform-admin --email=you@example.com --name="Your Name"
```
Then sign in at `/admin`: the panel asks you to set up an authenticator app and shows recovery
codes (D94). Keep them in the password manager. Horizon is at `/horizon` for platform admins only.

**Never** run `php artisan db:seed` in production: the demo seeders refuse (D93) and plans come
from their migration.

## 6. Vault master key

The master key wraps every company's vault data key (AES-256-GCM, spec §14.3). Lose it and every
vault secret is gone; leak it and anyone with a database copy can read them.

- **Generate** (once, on a trusted machine): `php artisan coffer:vault-key` prints a new
  `VAULT_MASTER_KEY` and `VAULT_FINGERPRINT_KEY` and writes nothing. Put both in the production
  `.env` and in the company password manager (two people should be able to reach it).
- **Never** put it in the repository, a backup ZIP or a ticket. Backups contain only wrapped keys,
  so a backup is useless without the master key, and the key is useless without a backup.
- **Rotate** (yearly, or at once if exposed):
  1. Put the current value at the front of `VAULT_PREVIOUS_MASTER_KEYS` (comma-separated).
  2. Generate a new key with `coffer:vault-key` and set it as `VAULT_MASTER_KEY`.
  3. `php artisan optimize && php artisan horizon:terminate`.
  4. `php artisan coffer:vault-rotate --master` re-wraps every data key with the new master.
  5. Check `/health`: `vault` must be `ok` (every data key is wrapped by a configured master).
  6. After the next backup, remove the old key from `VAULT_PREVIOUS_MASTER_KEYS`. Keep it in the
     password manager until every backup made before the rotation has expired (14 days).
- If a key is exposed, also rotate the data keys: `php artisan coffer:vault-rotate --all`
  (re-encrypts every secret on the `vault` queue) and ask customers to rotate the credentials the
  vault holds.
- `VAULT_FINGERPRINT_KEY` only fingerprints passwords for the reuse check; changing it resets the
  reuse report until items are saved again. Don't rotate it casually.

## 7. Backups and the restore drill

`php artisan coffer:backup` runs nightly at 01:30 (D96): a `mysqldump --single-transaction` plus
`storage/app/private` and `storage/app/public`, in one ZIP with every entry AES-256 encrypted by
`BACKUP_PASSWORD`, written to `BACKUP_DISK`; backups older than `BACKUP_KEEP_DAYS` (14) are deleted
(never the newest). Use an **off-site** disk: add an `s3` disk for a bucket in another region with
versioning and a lifecycle rule, and set `BACKUP_DISK` to it. Store `BACKUP_PASSWORD` next to the
vault master key. Managed MySQL snapshots are a good second layer, not a replacement.

Manual run: `php artisan coffer:backup` (or `--no-files` for the database only).

**Restore drill** (do it before launch, then quarterly, on a scratch server, never production):
1. Download the newest `coffer-YYYY-MM-DD-HHMMSS.zip` from the backup disk.
2. `7z x coffer-….zip -orestore` and enter `BACKUP_PASSWORD` (AES-256 ZIP; `unzip` can't).
3. `mysql -u root -p -e "CREATE DATABASE coffer_restore CHARACTER SET utf8mb4"`;
   `mysql -u root -p coffer_restore < restore/database.sql`.
4. Copy `restore/storage/private` → `storage/app/private` and `restore/storage/public` →
   `storage/app/public` of a checkout of the same release.
5. Point that checkout's `.env` at `coffer_restore`, with the **same** `APP_KEY`,
   `VAULT_MASTER_KEY` (and any previous master keys of that date) and `VAULT_FINGERPRINT_KEY`.
6. `php artisan coffer:ledger-integrity` must report every company balanced; sign in, open a
   company's dashboard, a receipt, and reveal one vault item.
7. Write down the date, the backup used and how long it took (the recovery time).

## 8. Health, monitoring and errors

- `GET /up`: liveness for the load balancer (framework, no data).
- `GET /health` with `Authorization: Bearer $HEALTH_CHECK_TOKEN` (or as a signed-in platform
  admin; anyone else gets 404): JSON `ok` / `warn` / `fail` for database, cache, queue heartbeat,
  scheduler heartbeat, vault master key and the last backup; HTTP 503 when anything fails (D97).
  Point an uptime monitor (Better Stack, UptimeRobot, Oh Dear…) at it every minute and alert on
  non-200. The queue check needs Horizon running and the scheduler queuing its heartbeat.
- **Errors** go to the log stack (`LOG_STACK=daily`, `storage/logs`); failed jobs and failed
  backups are logged at `critical`. To be paged, add a Slack channel (`LOG_SLACK_WEBHOOK_URL`, put
  `slack` in `LOG_STACK`) or ship logs to your provider.
- **Sentry (optional):** `composer require sentry/sentry-laravel`, `php artisan sentry:publish
  --dsn=…` (sets `SENTRY_LARAVEL_DSN`), deploy. `bootstrap/app.php` hands exceptions to Sentry
  automatically once the package is installed and the DSN is set; nothing else changes. Keep
  `send_default_pii` off: the app's own logs never carry vault secrets and Sentry shouldn't either.
- **Horizon** (`/horizon`, platform admins): failed jobs, throughput, wait times. Long waits email
  `HORIZON_NOTIFY_EMAIL`.

## 9. Stripe and mail

- Stripe live mode: follow `docs/BILLING_TESTING.md` §7. The webhook endpoint is
  `https://app.example.com/stripe/webhook`; create it with `php artisan cashier:webhook` (uses
  `APP_URL`) and put its signing secret in `STRIPE_WEBHOOK_SECRET`. It is CSRF-exempt and
  signature-checked.
- Mail: a transactional provider with SPF, DKIM and DMARC set for the sending domain. Test with
  an invite to yourself. Notifications, invites, email confirmation, password resets and contact
  leads all go out on the `mail` queue, so a worker must listen on it. SMTP settings and the
  local setup are in docs/MAIL.md.

## 10. Security notes

- HTTPS only; HSTS is sent in production (1 year, subdomains). Submit to the preload list only
  when every subdomain is HTTPS, then set `SECURITY_HSTS_PRELOAD=true`.
- The CSP is strict for the app (nonces), relaxed for `/admin` and `/horizon` (D93). To try a CSP
  change live, set `SECURITY_CSP_REPORT_ONLY=true` (and `SECURITY_CSP_REPORT_URI`) first.
- Keep `/admin` and `/horizon` reachable only over HTTPS; consider an IP allow-list in nginx for
  both.
- `composer audit` and `npm audit --omit=dev` before each release (clean at launch, D93).
- Database user `coffer` gets privileges on the `coffer` database only. Redis has a password and
  listens on localhost.

## 11. Optional: Docker outline

Not used in production today; an outline for a container platform:
- **Image** (`php:8.4-fpm-bookworm`): install the extensions in section 2 (`docker-php-ext-install
  bcmath gd intl pcntl pdo_mysql zip`, `pecl install redis`), Composer, Node 22; `composer install
  --no-dev`, `npm ci && npm run build:ssr` in a build stage; copy the app; `www-data` user.
- **Services** (compose): `web` (nginx + php-fpm, or FrankenPHP / Octane after testing), `horizon`
  (same image, `php artisan horizon`), `scheduler` (same image, `php artisan schedule:work`),
  `ssr` (same image, `php artisan inertia:start-ssr`), `mysql:8.4`, `redis:7`.
- **Volumes:** `storage/` (shared by web, horizon, scheduler), MySQL data, Redis data.
- **Release:** a one-off `php artisan migrate --force && php artisan optimize` container before
  rolling the services; `horizon:terminate` is replaced by restarting the `horizon` service.
- Secrets (`APP_KEY`, `VAULT_*`, `BACKUP_PASSWORD`, `DB_*`, Stripe) come from the platform's
  secret store, never baked into the image.

---

## 12. Hostinger shared hosting (coffer.unboundsolutions.in)

Production since 19 Nov 2026 (D146); Laravel Cloud is staging. Account `u728361439`, Single Web
Hosting (`hostinger_starter_v3`), LiteSpeed, PHP 8.4 (account-wide; sodium on), MySQL
`u728361439_coffer` on `127.0.0.1`. No SSH, Composer, Node, Redis or always-on processes.

| What | Where |
|---|---|
| App (code, `.env`, storage) | `~/coffer-app`, outside the web root |
| Web root | `public_html/coffer` → symlink to `~/coffer-app/public` |
| Upload inbox | `public_html/.coffer-inbox` (its `.htaccess` denies all web access) |
| PHP CLI | `/opt/alt/php84/usr/bin/php -d disable_functions=` (the account disables `proc_open`, `exec` and `symlink`; the CLI override lets the scheduler run commands) |

**Deploy a release** (everything is built on a dev machine):
1. `git archive HEAD | tar -x -C <dir>`, then in `<dir>`: `composer install --no-dev --optimize-autoloader --no-scripts`.
2. `npm run build` in the repo and copy `public/build` into `<dir>/public/build` (never `public/hot`).
3. `tar -czf coffer.tar.gz .` (leave out `tests`, `design`, `docs`, `node_modules`).
4. Upload `coffer.tar.gz` (and `env.production` only when the `.env` changes) to `.coffer-inbox`
   with `hosting_files_generate-upload-url` (TUS), plus `deploy.sh`.
5. Run `bash ~/domains/unboundsolutions.in/public_html/.coffer-inbox/deploy.sh` once from a
   temporary cron job (no SSH): maintenance mode, unpack, `package:discover`, `migrate --force`,
   `filament:upgrade`, `optimize`, `queue:restart`, `up`. It exits at once when no release is
   waiting, so a repeating cron can't take the site down. Delete the cron job afterwards.

**Cron jobs (hPanel → Advanced → Cron Jobs):**
- every minute: `/opt/alt/php84/usr/bin/php -d disable_functions= /home/u728361439/coffer-app/artisan schedule:run`
- every minute: `/opt/alt/php84/usr/bin/php -d disable_functions= /home/u728361439/coffer-app/artisan queue:work --queue=default,mail,exports,imports,vault --stop-when-empty --max-time=50 --tries=3`

The plan allows **two** cron jobs and both are taken, so a deploy deletes the queue-worker job,
uses its slot for the run-once deploy job, then puts the worker back.

**Gotchas met on the first deploy:**
- The account's CLI `php` is 8.3; use the 8.4 path above. The scheduler starts each command as a
  child `php` without the `-d` override, so a command that shells out (`coffer:backup` runs
  `mysqldump`) fails there; run it from its own cron job if backups are needed on this host.
- `storage:link` fails (no `exec`/`symlink` in PHP): the link is made with `ln -s` in a shell script.
- LiteSpeed answers 500 when a response header passes ~8 KB, before Laravel can log anything. The
  preload `Link` header had reached 11 KB, so it is capped at 20 assets (`bootstrap/app.php`).
- Hostinger's CDN (`Server: hcdn`) can serve a cached page for a minute after a change.

## Production checklist

The Phase 9 acceptance criterion. Tick every box before launch; repeat the ones marked (each
release) on every deploy.

**Server and code**
- [ ] PHP 8.3+ with every extension in section 2 (`php -m`), Node 22+, MySQL 8, Redis 7 (password, localhost).
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` is the public https URL, `APP_KEY` set and stored in the password manager.
- [ ] HTTPS certificate installed and auto-renewing; HTTP redirects to HTTPS; `Strict-Transport-Security` header present.
- [ ] Deploy script as in section 3; `php artisan optimize` ran; `php artisan about` shows production, caches on. (each release)
- [ ] `composer audit` and `npm audit --omit=dev` clean. (each release)
- [ ] Test suite green on the release commit (`php artisan test`), plus `npm run types`, `npm test`, `vendor/bin/phpstan`. (each release)

**Data and secrets**
- [ ] `php artisan migrate --force` ran; `plans` has the four plans with the real prices and limits (edited in `/admin` › Plans).
- [ ] No demo data: `users` has no `@vertexlabs.in` / `@coffer.test` accounts.
- [ ] `VAULT_MASTER_KEY` and `VAULT_FINGERPRINT_KEY` generated with `coffer:vault-key`, stored in the password manager by two people; `/health` shows `vault: ok`.
- [ ] `BACKUP_PASSWORD` set and stored next to the master key; `BACKUP_DISK` is off-site.
- [ ] `.env` is readable by the app user only (`chmod 600`), not in the repository.

**Processes**
- [ ] Horizon runs under supervisor (`php artisan horizon:status` → running); `/horizon` shows both supervisors (`app`, `heavy`).
- [ ] The scheduler cron runs every minute (`php artisan schedule:list` shows every task; `/health` shows `scheduler: ok`).
- [ ] SSR runs under supervisor: `curl http://127.0.0.1:13714/health` → OK, and the home page HTML contains the hero headline.
- [ ] `php artisan storage:link` done; receipts and avatars load.

**Backups and monitoring**
- [ ] `php artisan coffer:backup` ran by hand and wrote a ZIP to the off-site disk.
- [ ] Restore drill done (section 7) and its date and recovery time written down.
- [ ] Uptime monitor on `/health` with the bearer token (alerts on non-200), and on `/up`.
- [ ] Error alerting chosen and tested: Slack log channel or Sentry (`SENTRY_LARAVEL_DSN`); a test exception reaches it.
- [ ] `HORIZON_NOTIFY_EMAIL` set; a failed job shows up in Horizon and at `critical` in the log.

**Access**
- [ ] First platform admin created with `coffer:platform-admin`; authenticator app set up at `/admin`; recovery codes stored. `PLATFORM_ADMIN_*` cleared from `.env`.
- [ ] `/admin` and `/horizon` refuse a tenant user (403 / sign-in page) and need the platform admin's second factor.
- [ ] Optional: nginx IP allow-list for `/admin` and `/horizon`.

**Billing, mail, auth**
- [ ] Stripe live keys, live price ids, `cashier:webhook` endpoint and `STRIPE_WEBHOOK_SECRET` set; a live test checkout and refund done (`docs/BILLING_TESTING.md` §7). GST decision made.
- [ ] Mail provider set with SPF / DKIM / DMARC; an invite and a password reset arrive.
- [ ] Google sign-in: production OAuth client with the https callback, or the keys left empty (button hidden).

**Security and privacy**
- [ ] Response headers checked on the live site (securityheaders.com or `curl -I`): CSP, HSTS, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy. No CSP errors in the browser console on the dashboard, vault and marketing pages.
- [ ] Legal pages (Terms, Privacy, Cookies, DPA) reviewed by counsel; the draft banners removed; Grievance Officer named (D84).
- [ ] DPDPA: a personal data export and a workspace export downloaded on staging; an account deletion and a workspace deletion ran through `coffer:privacy-purge` on staging (D98).
- [ ] Ledger check: `php artisan coffer:ledger-integrity` reports every company balanced. (each release)

# Inertia SSR for the marketing site (D83)

Server-side rendering is switched on for the public marketing routes only (`welcome` and
`marketing.*`; see `Inertia::disableSsr` in `AppServiceProvider`). App pages behind sign-in,
and the auth pages, render in the browser as before. If the SSR server is down, Inertia
falls back to client rendering, so the site stays up but crawlers get an empty root.

## Build and run

```bash
npm run build:ssr                 # vp build && vp build --ssr  → public/build + bootstrap/ssr/app.js
php artisan inertia:start-ssr     # Node SSR server on 127.0.0.1:13714 (config/inertia.php)
php artisan inertia:stop-ssr      # stop it (e.g. on deploy, before restarting)
```

In production, run `inertia:start-ssr` under the process supervisor next to the queue
workers, and restart it on every deploy after `npm run build:ssr` (`inertia:stop-ssr`, and
the supervisor starts it again). `bootstrap/ssr` is git-ignored: build it on the server.

In local development with `composer run dev`, the `@inertiajs/vite` plugin serves SSR from
the Vite dev server (`/__inertia_ssr`), so no separate SSR process is needed.

## Proof (17 Nov 2026)

1. `npm run build:ssr` (built `bootstrap/ssr/app.js`).
2. `php artisan inertia:start-ssr` in the background: "Inertia SSR server started";
   `GET http://127.0.0.1:13714/health` → `{"status":"OK"}`.
3. `php artisan serve --port=8010` in the background.
4. `curl http://127.0.0.1:8010/` and `/pricing`. The first HTML response contained:
   - `/`: `<title>Run every company from one place - Coffer</title>`, the meta
     description, `og:title`, `og:image`, `twitter:card`, `<link rel="canonical">`, the
     JSON-LD `<script type="application/ld+json">` (Organization + SoftwareApplication),
     and `<h1 id="hero-h">Every company you run, <span>in one quiet place.</span></h1>`
     inside the app root.
   - `/pricing`: `<title>Pricing - Coffer</title>`, the same meta set with its own canonical,
     `<h1>Pay for the companies you run, …</h1>`, and the plan prices (₹2,499 for Growth).
   - `/login` (not a marketing route) returned `<div id="app"></div>`: SSR is off there,
     as intended.
5. Both processes were stopped.

Note: canonical and OG URLs come from `APP_URL`; set it to the public https origin in
production.

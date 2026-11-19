#!/bin/bash
# Coffer deploy on Hostinger shared hosting: unpack the uploaded release into ~/coffer-app,
# migrate, cache, and point public_html/coffer at the app's public folder.
set -euo pipefail
PHP=/opt/alt/php84/usr/bin/php
H=/home/u728361439
APP=$H/coffer-app
IN=$H/domains/unboundsolutions.in/public_html/.coffer-inbox
WEB=$H/domains/unboundsolutions.in/public_html/coffer

[ -f "$IN/coffer.tar.gz" ] || { echo "no release to deploy"; exit 0; }
mkdir -p "$APP"
if [ -f "$APP/artisan" ] && [ -f "$APP/.env" ]; then (cd "$APP" && $PHP artisan down --retry=60) || true; fi

echo "== unpack"
tar -xzf "$IN/coffer.tar.gz" -C "$APP"
rm -f "$IN/coffer.tar.gz"
if [ -f "$IN/env.production" ]; then mv "$IN/env.production" "$APP/.env"; fi
chmod 600 "$APP/.env"

cd "$APP"
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
rm -f bootstrap/cache/*.php

echo "== artisan"
$PHP artisan package:discover --no-ansi
$PHP artisan migrate --force --no-ansi
[ -L public/storage ] || $PHP artisan storage:link --no-ansi
$PHP artisan filament:upgrade --no-ansi || true
$PHP artisan optimize --no-ansi
$PHP artisan queue:restart --no-ansi

if [ ! -L "$WEB" ]; then rm -rf "$WEB"; ln -s "$APP/public" "$WEB"; fi
$PHP artisan up --no-ansi || true
echo "== DEPLOY_OK $(date)"

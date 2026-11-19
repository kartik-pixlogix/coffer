#!/bin/bash
# Upload one file into the website's public_html over TUS (docs/DEPLOY.md §12).
# Needs HURL, HAUTH and HREST from `hosting_files_generate-upload-url` in the environment.
# Usage: scripts/hostinger/upload.sh <local file> <path inside public_html>
set -euo pipefail
: "${HURL:?}" "${HAUTH:?}" "${HREST:?}"
FILE="$1"; DEST="$2"; SIZE=$(wc -c < "$FILE" | tr -d ' ')
c1=$(curl -sS -o /dev/null -w "%{http_code}" -X POST "$HURL/$DEST?override=true" -H "X-Auth: $HAUTH" -H "X-Auth-Rest: $HREST" -H "Tus-Resumable: 1.0.0" -H "Upload-Length: $SIZE" -H "Upload-Offset: 0")
c2=$(curl -sS -o /dev/null -w "%{http_code}" -X PATCH "$HURL/$DEST?override=true" -H "X-Auth: $HAUTH" -H "X-Auth-Rest: $HREST" -H "Tus-Resumable: 1.0.0" -H "Content-Type: application/offset+octet-stream" -H "Upload-Offset: 0" --data-binary "@$FILE")
echo "$DEST size=$SIZE create=$c1 patch=$c2"

#!/usr/bin/env bash
set -e
export PATH="/usr/bin:/usr/local/bin:$PATH"
cd "$(dirname "$0")"

(cd apps/api && docker compose up -d)

npx --yes concurrently -k -n ai,api,queue,web -c magenta,blue,yellow,green \
  "cd apps/ai && . .venv/bin/activate && uvicorn main:app --reload --port 8001" \
  "cd apps/api && php artisan serve --port=8000" \
  "cd apps/api && php artisan queue:listen --tries=3 --timeout=260" \
  "cd apps/web && npm run dev"

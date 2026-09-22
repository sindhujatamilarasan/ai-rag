#!/usr/bin/env bash
# One command for the whole dev stack. Ctrl+C stops everything.
set -e
cd "$(dirname "$0")"

docker compose -f apps/api/docker-compose.yml up -d

npx --yes concurrently -k \
  -n ai,api,queue,web \
  -c magenta,blue,yellow,green \
  "cd apps/ai && . .venv/bin/activate && uvicorn main:app --reload --port 8001" \
  "cd apps/api && php artisan serve" \
  "cd apps/api && php artisan queue:listen --tries=3" \
  "cd apps/web && npm run dev"

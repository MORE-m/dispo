#!/usr/bin/env bash
# SPT-008 E2E server harness: prepare isolated SQLite DB, seed, serve with log.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PORT="${E2E_SPT008_PORT:-8033}"
DB="${E2E_SPT008_DB:-${ROOT}/database/e2e-spt-008.sqlite}"
LOG="${ROOT}/storage/logs/spt008-server.log"

mkdir -p "$(dirname "$DB")" "$(dirname "$LOG")"
: >"$LOG"

rm -f "$DB"
touch "$DB"
php -d memory_limit=512M artisan migrate --force >>"$LOG" 2>&1
php -d memory_limit=512M artisan db:seed --class=E2ESpotDistributionExportSeeder --force >>"$LOG" 2>&1

php -d memory_limit=512M artisan serve \
  --host=127.0.0.1 \
  --port="${PORT}" \
  >>"$LOG" 2>&1 &
SERVER_PID=$!

cleanup() {
  local ec=$?
  echo "SPT008_SERVER_EXIT code=${ec} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG" || true
}
trap cleanup EXIT

wait "${SERVER_PID}"
exit $?

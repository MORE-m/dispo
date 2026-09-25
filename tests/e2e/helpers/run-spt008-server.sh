#!/usr/bin/env bash
# SPT-008 E2E server harness: prepare isolated SQLite DB, seed, serve with log.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PORT="${E2E_SPT008_PORT:-8033}"
DB="${E2E_SPT008_DB:-${ROOT}/database/e2e-spt-008.sqlite}"
LOG="${E2E_SPT008_LOG:-${ROOT}/storage/logs/spt008-server.log}"

mkdir -p "$(dirname "$DB")" "$(dirname "$LOG")"
: >"$LOG"

rm -f "$DB"
touch "$DB"
php -d memory_limit=512M artisan migrate --force >>"$LOG" 2>&1
php -d memory_limit=512M artisan db:seed --class=E2ESpotDistributionExportSeeder --force >>"$LOG" 2>&1

SERVER_PID=""
STOPPING=0
SERVER_WAIT_EC=0

kill_server() {
  if [[ -n "${SERVER_PID}" ]] && kill -0 "${SERVER_PID}" 2>/dev/null; then
    kill "${SERVER_PID}" 2>/dev/null || true
    for _ in 1 2 3 4 5 6 7 8 9 10; do
      kill -0 "${SERVER_PID}" 2>/dev/null || break
      sleep 0.1
    done
    if kill -0 "${SERVER_PID}" 2>/dev/null; then
      kill -9 "${SERVER_PID}" 2>/dev/null || true
    fi
    wait "${SERVER_PID}" 2>/dev/null || true
  fi

  if command -v lsof >/dev/null 2>&1; then
    for p in $(lsof -t -nP -iTCP:"${PORT}" -sTCP:LISTEN 2>/dev/null || true); do
      kill -9 "$p" 2>/dev/null || true
    done
  fi
}

log_exit() {
  local ec="$1"
  local note=""
  if [[ "${ec}" -eq 139 ]]; then
    note=" signal=SIGSEGV(11)"
  elif [[ "${ec}" -gt 128 ]]; then
    note=" signal=$((ec - 128))"
  fi
  echo "SPT008_SERVER_EXIT code=${ec}${note} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG" || true
}

on_signal() {
  STOPPING=1
  kill_server
  log_exit 0
  exit 0
}

on_exit() {
  if [[ "${STOPPING}" -eq 1 ]]; then
    return
  fi
  kill_server
}

trap on_exit EXIT
trap on_signal TERM INT

php -d memory_limit=512M artisan serve \
  --host=127.0.0.1 \
  --port="${PORT}" \
  >>"$LOG" 2>&1 &
SERVER_PID=$!

set +e
wait "${SERVER_PID}"
SERVER_WAIT_EC=$?
set -e

# wait returned → process already gone; clear PID so EXIT trap does not re-kill
SERVER_PID=""

log_exit "${SERVER_WAIT_EC}"

STOPPING=1
# Preserve native crash (139); do not mask as success
exit "${SERVER_WAIT_EC}"

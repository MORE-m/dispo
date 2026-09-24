#!/usr/bin/env bash
# SPT-008 diagnose-only server wrapper (test harness).
# Starts php artisan serve, tee stdout/stderr to a log, records parent/child PIDs and exit.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PORT="${E2E_SPT008_PORT:-8033}"
DB="${E2E_SPT008_DB:-${ROOT}/database/e2e-spt-008.sqlite}"
LOG_DIR="${E2E_SPT008_LOG_DIR:-${ROOT}/storage/logs/spt008}"
PHASE="${E2E_SPT008_PHASE:-default}"

mkdir -p "$LOG_DIR" "$(dirname "$DB")"
LOG="${LOG_DIR}/server-${PHASE}-${PORT}.log"
PID_FILE="${LOG_DIR}/server-${PHASE}-${PORT}.pid"
CHILD_PID_FILE="${LOG_DIR}/server-${PHASE}-${PORT}.child.pid"
EXIT_FILE="${LOG_DIR}/server-${PHASE}-${PORT}.exit"
META_FILE="${LOG_DIR}/server-${PHASE}-${PORT}.meta"

: >"$LOG"
{
  echo "SPT008_SERVER_START phase=${PHASE} port=${PORT} db=${DB} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "SPT008_PHP_VERSION=$(php -v | head -1)"
  echo "SPT008_PHP_MEMORY_LIMIT=$(php -r 'echo ini_get("memory_limit");')"
} | tee -a "$LOG"

rm -f "$DB"
touch "$DB"
php -d memory_limit=512M artisan migrate --force >>"$LOG" 2>&1
php -d memory_limit=512M artisan db:seed --class=E2ESpotDistributionExportSeeder --force >>"$LOG" 2>&1

# Foreground serve (no --no-reload here: diagnose must not change serve mode yet)
php -d memory_limit=512M artisan serve \
  --host=127.0.0.1 \
  --port="${PORT}" \
  >>"$LOG" 2>&1 &
PARENT_PID=$!
echo "${PARENT_PID}" >"$PID_FILE"
echo "SPT008_SERVER_PARENT_PID=${PARENT_PID}" | tee -a "$LOG"
echo "SPT008_SERVER_OPTS=default (no --no-reload)" | tee -a "$LOG"

# artisan serve typically spawns a PHP built-in server child
sleep 0.5
CHILD_PID=""
if command -v pgrep >/dev/null 2>&1; then
  CHILD_PID="$(pgrep -P "${PARENT_PID}" 2>/dev/null | head -n 1 || true)"
fi
if [[ -n "${CHILD_PID}" ]]; then
  echo "${CHILD_PID}" >"$CHILD_PID_FILE"
  echo "SPT008_SERVER_CHILD_PID=${CHILD_PID}" | tee -a "$LOG"
else
  rm -f "$CHILD_PID_FILE"
  echo "SPT008_SERVER_CHILD_PID=unknown" | tee -a "$LOG"
fi

{
  echo "phase=${PHASE}"
  echo "port=${PORT}"
  echo "parent_pid=${PARENT_PID}"
  echo "child_pid=${CHILD_PID:-unknown}"
  echo "log=${LOG}"
  echo "started_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} >"$META_FILE"

cleanup() {
  local ec=$?
  local ended
  ended="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "${ec}" >"$EXIT_FILE"
  echo "SPT008_SERVER_EXIT phase=${PHASE} code=${ec} at=${ended}" | tee -a "$LOG"
  if [[ -n "${CHILD_PID}" ]] && kill -0 "${CHILD_PID}" 2>/dev/null; then
    echo "SPT008_SERVER_CHILD_STILL_ALIVE pid=${CHILD_PID}" | tee -a "$LOG"
  fi
  if kill -0 "${PARENT_PID}" 2>/dev/null; then
    echo "SPT008_SERVER_PARENT_STILL_ALIVE pid=${PARENT_PID}" | tee -a "$LOG"
  fi
}
trap cleanup EXIT

# Stay foreground until Playwright stops the process group / parent exits
wait "${PARENT_PID}"
exit $?

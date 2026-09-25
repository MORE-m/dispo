#!/usr/bin/env bash
# SPT-008 E2E server harness: prepare isolated SQLite DB, seed, serve with log.
# Diagnose: startet denselben Server wie die Suite; Exit 139 = SIGSEGV.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PORT="${E2E_SPT008_PORT:-8033}"
DB="${E2E_SPT008_DB:-${ROOT}/database/e2e-spt-008.sqlite}"
LOG="${ROOT}/storage/logs/spt008-server.log"
MODE="${SPT008_SERVER_MODE:-artisan}" # artisan | raw

mkdir -p "$(dirname "$DB")" "$(dirname "$LOG")"
: >"$LOG"

signal_name() {
  local code="$1"
  case "$code" in
    129) echo "SIGHUP(1)" ;;
    130) echo "SIGINT(2)" ;;
    134) echo "SIGABRT(6)" ;;
    137) echo "SIGKILL(9)" ;;
    139) echo "SIGSEGV(11)" ;;
    143) echo "SIGTERM(15)" ;;
    *)
      if [[ "$code" -gt 128 ]]; then
        echo "signal=$((code - 128))"
      else
        echo "no_signal"
      fi
      ;;
  esac
}

{
  echo "SPT008_SERVER_START at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "SPT008_SERVER_MODE=${MODE}"
  echo "SPT008_SERVER_PORT=${PORT}"
  echo "SPT008_SERVER_DB=${DB}"
  echo "SPT008_SERVER_PHP=$(command -v php)"
  php -r 'echo "SPT008_SERVER_PHP_VERSION=".PHP_VERSION.PHP_EOL;'
  echo "SPT008_SERVER_PID_PARENT=$$"
} >>"$LOG"

rm -f "$DB"
touch "$DB"
php -d memory_limit=512M artisan migrate --force >>"$LOG" 2>&1
php -d memory_limit=512M artisan db:seed --class=E2ESpotDistributionExportSeeder --force >>"$LOG" 2>&1

ROUTER="${ROOT}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
if [[ ! -f "$ROUTER" ]]; then
  echo "SPT008_SERVER_ROUTER_MISSING path=${ROUTER}" >>"$LOG"
  exit 2
fi

SERVER_PID=""
if [[ "$MODE" == "raw" ]]; then
  # Direkt PHP Built-in Server (ohne artisan ServeCommand-Wrapper).
  # cwd muss public/ sein (server.php nutzt getcwd()).
  (
    cd "${ROOT}/public"
    exec php -d memory_limit=512M -S "127.0.0.1:${PORT}" "$ROUTER"
  ) >>"$LOG" 2>&1 &
  SERVER_PID=$!
else
  php -d memory_limit=512M artisan serve \
    --host=127.0.0.1 \
    --port="${PORT}" \
    >>"$LOG" 2>&1 &
  SERVER_PID=$!
fi

echo "SPT008_SERVER_PID=${SERVER_PID}" >>"$LOG"
echo "SPT008_SERVER_ROUTER=${ROUTER}" >>"$LOG"

cleanup() {
  local ec=$?
  local sig
  sig="$(signal_name "$ec")"
  {
    echo "SPT008_SERVER_EXIT code=${ec} signal=${sig} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    if [[ -n "${SERVER_PID}" ]] && kill -0 "${SERVER_PID}" 2>/dev/null; then
      echo "SPT008_SERVER_STILL_ALIVE pid=${SERVER_PID}"
    else
      echo "SPT008_SERVER_DEAD pid=${SERVER_PID:-unknown}"
    fi
  } >>"$LOG" || true
}
trap cleanup EXIT

wait "${SERVER_PID}"
exit $?

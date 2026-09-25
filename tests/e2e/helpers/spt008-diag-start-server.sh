#!/usr/bin/env bash
# Startet Diagnose-Server (artisan|raw) im Hintergrund und schreibt PID-Datei.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

MODE="${1:-artisan}" # artisan | raw
PORT="${SPT008_DIAG_PORT}"
PID_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.pid"
LOG_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.log"
ROUTER="${SPT008_DIAG_ROOT}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"

: >"$LOG_FILE"

if [[ -f "$PID_FILE" ]]; then
  old="$(cat "$PID_FILE" || true)"
  if [[ -n "$old" ]] && kill -0 "$old" 2>/dev/null; then
    kill "$old" 2>/dev/null || true
    wait "$old" 2>/dev/null || true
  fi
  rm -f "$PID_FILE"
fi

{
  echo "SPT008_DIAG_SERVER_START mode=${MODE} port=${PORT} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "php=$(command -v php) version=$(php -r 'echo PHP_VERSION;')"
} >>"$LOG_FILE"

if [[ "$MODE" == "raw" ]]; then
  (
    cd "${SPT008_DIAG_ROOT}/public"
    exec php -d memory_limit=512M -S "127.0.0.1:${PORT}" "$ROUTER"
  ) >>"$LOG_FILE" 2>&1 &
  echo $! >"$PID_FILE"
else
  php -d memory_limit=512M artisan serve \
    --host=127.0.0.1 \
    --port="${PORT}" \
    >>"$LOG_FILE" 2>&1 &
  echo $! >"$PID_FILE"
fi

PID="$(cat "$PID_FILE")"
echo "SPT008_DIAG_SERVER_PID=${PID}" >>"$LOG_FILE"

# Health wait
ok=0
for _ in $(seq 1 60); do
  if ! kill -0 "$PID" 2>/dev/null; then
    echo "Server died during boot" >&2
    tail -n 50 "$LOG_FILE" >&2 || true
    exit 139
  fi
  if curl -fsS "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
    ok=1
    break
  fi
  sleep 0.5
done

if [[ "$ok" -ne 1 ]]; then
  echo "Health check failed for mode=${MODE} port=${PORT}" >&2
  tail -n 80 "$LOG_FILE" >&2 || true
  exit 1
fi

echo "$PID"

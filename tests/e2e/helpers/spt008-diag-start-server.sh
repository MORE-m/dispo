#!/usr/bin/env bash
# Startet Diagnose-Server (artisan|raw) im Hintergrund und schreibt PID-Datei.
# Wichtig: eigene Env-Datei + Process-Group, damit Child php -S mitstirbt.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

MODE="${1:-artisan}" # artisan | raw
PORT="${SPT008_DIAG_PORT}"
PID_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.pid"
PGID_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.pgid"
LOG_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.log"
ENV_FILE="${SPT008_DIAG_LOG_DIR}/server.env"
ROUTER="${SPT008_DIAG_ROOT}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"

: >"$LOG_FILE"

# Kill previous instance (process group if known)
if [[ -f "$PGID_FILE" ]]; then
  old_pgid="$(cat "$PGID_FILE" || true)"
  if [[ -n "${old_pgid}" ]]; then
    kill -- "-${old_pgid}" 2>/dev/null || true
    sleep 0.3
    kill -9 -- "-${old_pgid}" 2>/dev/null || true
  fi
  rm -f "$PGID_FILE"
fi
if [[ -f "$PID_FILE" ]]; then
  old="$(cat "$PID_FILE" || true)"
  if [[ -n "$old" ]]; then
    kill "$old" 2>/dev/null || true
    kill -9 "$old" 2>/dev/null || true
  fi
  rm -f "$PID_FILE"
fi

# Explizite Env für Child-Prozesse (Laravel überschreibt bestehende Env nicht aus .env)
cat >"$ENV_FILE" <<EOF
APP_ENV=testing
E2E_SERVER=1
APP_KEY=${APP_KEY}
DB_CONNECTION=sqlite
DB_DATABASE=${SPT008_DIAG_DB}
DB_URL=
APP_URL=http://127.0.0.1:${PORT}
SESSION_DRIVER=file
CACHE_STORE=array
QUEUE_CONNECTION=sync
EOF

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

{
  echo "SPT008_DIAG_SERVER_START mode=${MODE} port=${PORT} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "php=$(command -v php) version=$(php -r 'echo PHP_VERSION;')"
  echo "DB_DATABASE=${DB_DATABASE}"
  echo "APP_ENV=${APP_ENV}"
} >>"$LOG_FILE"

if [[ "$MODE" == "raw" ]]; then
  (
    cd "${SPT008_DIAG_ROOT}/public"
    if command -v setsid >/dev/null 2>&1; then
      exec setsid php -d memory_limit=512M -S "127.0.0.1:${PORT}" "$ROUTER"
    else
      exec php -d memory_limit=512M -S "127.0.0.1:${PORT}" "$ROUTER"
    fi
  ) >>"$LOG_FILE" 2>&1 &
  echo $! >"$PID_FILE"
else
  (
    if command -v setsid >/dev/null 2>&1; then
      exec setsid php -d memory_limit=512M artisan serve \
        --host=127.0.0.1 \
        --port="${PORT}"
    else
      exec php -d memory_limit=512M artisan serve \
        --host=127.0.0.1 \
        --port="${PORT}"
    fi
  ) >>"$LOG_FILE" 2>&1 &
  echo $! >"$PID_FILE"
fi

PID="$(cat "$PID_FILE")"
# PGID == PID when started via setsid
echo "$PID" >"$PGID_FILE"
echo "SPT008_DIAG_SERVER_PID=${PID}" >>"$LOG_FILE"
echo "SPT008_DIAG_SERVER_PGID=${PID}" >>"$LOG_FILE"

ok=0
for _ in $(seq 1 60); do
  if ! kill -0 "$PID" 2>/dev/null; then
    echo "Server died during boot" >&2
    tail -n 80 "$LOG_FILE" >&2 || true
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

# Sanity: DB must contain seeded orders
COUNT="$(sqlite3 "$SPT008_DIAG_DB" 'select count(*) from dispo_orders;' 2>/dev/null || echo 0)"
echo "SPT008_DIAG_ORDER_COUNT=${COUNT}" >>"$LOG_FILE"
if [[ "$COUNT" == "0" ]]; then
  echo "Seeded DB appears empty at ${SPT008_DIAG_DB}" >&2
  exit 7
fi

echo "$PID"

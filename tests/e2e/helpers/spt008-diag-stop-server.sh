#!/usr/bin/env bash
# Stoppt Diagnose-Server inkl. Process-Group (Child php -S).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

MODE="${1:-artisan}"
PID_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.pid"
PGID_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.pgid"
LOG_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.log"

stop_pgid() {
  local pgid="$1"
  [[ -z "$pgid" ]] && return 0
  kill -- "-${pgid}" 2>/dev/null || true
  sleep 0.2
  kill -9 -- "-${pgid}" 2>/dev/null || true
}

if [[ -f "$PGID_FILE" ]]; then
  pgid="$(cat "$PGID_FILE" || true)"
  stop_pgid "$pgid"
  echo "SPT008_DIAG_SERVER_STOPPED pgid=${pgid} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG_FILE"
  rm -f "$PGID_FILE"
fi

if [[ -f "$PID_FILE" ]]; then
  pid="$(cat "$PID_FILE" || true)"
  if [[ -n "$pid" ]]; then
    kill "$pid" 2>/dev/null || true
    kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$PID_FILE"
fi

# Port freimachen falls Zombie Listener
if command -v lsof >/dev/null 2>&1; then
  for p in $(lsof -t -nP -iTCP:"${SPT008_DIAG_PORT}" -sTCP:LISTEN 2>/dev/null || true); do
    kill "$p" 2>/dev/null || true
    kill -9 "$p" 2>/dev/null || true
  done
fi

echo "stopped"

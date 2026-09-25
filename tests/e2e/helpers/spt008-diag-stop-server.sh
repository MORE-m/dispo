#!/usr/bin/env bash
# Stoppt Diagnose-Server und reportet Exit-Code falls bekannt.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

MODE="${1:-artisan}"
PID_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.pid"
LOG_FILE="${SPT008_DIAG_LOG_DIR}/server-${MODE}.log"

if [[ ! -f "$PID_FILE" ]]; then
  echo "no_pid_file"
  exit 0
fi

PID="$(cat "$PID_FILE")"
if [[ -z "$PID" ]]; then
  rm -f "$PID_FILE"
  echo "empty_pid"
  exit 0
fi

if kill -0 "$PID" 2>/dev/null; then
  kill "$PID" 2>/dev/null || true
  # Wait up to 5s
  for _ in $(seq 1 50); do
    if ! kill -0 "$PID" 2>/dev/null; then
      break
    fi
    sleep 0.1
  done
  if kill -0 "$PID" 2>/dev/null; then
    kill -9 "$PID" 2>/dev/null || true
  fi
  echo "SPT008_DIAG_SERVER_STOPPED pid=${PID} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG_FILE"
  echo "stopped"
else
  echo "SPT008_DIAG_SERVER_ALREADY_DEAD pid=${PID} at=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$LOG_FILE"
  echo "already_dead"
fi

rm -f "$PID_FILE"

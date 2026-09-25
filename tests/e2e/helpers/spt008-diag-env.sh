#!/usr/bin/env bash
# Gemeinsame Env für SPT-008-Diagnose (kein Produktcode).
# shellcheck disable=SC2034
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

export SPT008_DIAG_ROOT="$ROOT"
export SPT008_DIAG_PORT="${SPT008_DIAG_PORT:-8133}"
export SPT008_DIAG_DB="${SPT008_DIAG_DB:-${ROOT}/database/e2e-spt008-diag.sqlite}"
export SPT008_DIAG_LOG_DIR="${SPT008_DIAG_LOG_DIR:-${ROOT}/storage/logs/spt008-diag}"
export SPT008_DIAG_REPORT="${SPT008_DIAG_REPORT:-${SPT008_DIAG_LOG_DIR}/matrix-report.txt}"
export SPT008_DIAG_COOKIE_JAR="${SPT008_DIAG_COOKIE_JAR:-${SPT008_DIAG_LOG_DIR}/cookies.txt}"
export SPT008_DIAG_ORDERS_JSON="${ROOT}/database/e2e-spt008-orders.json"

export APP_ENV=testing
export E2E_SERVER=1
export APP_KEY="${APP_KEY:-base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=}"
export DB_CONNECTION=sqlite
export DB_DATABASE="$SPT008_DIAG_DB"
export DB_URL=""

mkdir -p "$SPT008_DIAG_LOG_DIR" "$(dirname "$SPT008_DIAG_DB")"

diag_log() {
  echo "$*" | tee -a "$SPT008_DIAG_REPORT"
}

diag_section() {
  {
    echo
    echo "======== $* ========"
  } | tee -a "$SPT008_DIAG_REPORT"
}

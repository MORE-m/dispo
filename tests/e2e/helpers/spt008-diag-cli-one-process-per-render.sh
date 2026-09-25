#!/usr/bin/env bash
# Diagnose: jeder XLSX-Render in einem separaten PHP-Prozess.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

TOTAL="${1:-50}"
KEYS=(calendar tandem average mixed multi)
OUT="${SPT008_DIAG_LOG_DIR}/cli-one-process-per-render.log"
: >"$OUT"

diag_section "CLI one-process-per-render total=${TOTAL}"

n=0
fails=0
while [[ "$n" -lt "$TOTAL" ]]; do
  for key in "${KEYS[@]}"; do
    n=$((n + 1))
    if [[ "$n" -gt "$TOTAL" ]]; then
      break 2
    fi
    set +e
    php -d memory_limit=512M \
      "${SCRIPT_DIR}/spt008-diag-cli-one-render.php" "$key" >>"$OUT" 2>&1
    ec=$?
    set -e
    if [[ "$ec" -ne 0 ]]; then
      fails=$((fails + 1))
      diag_log "FAIL n=${n} key=${key} exit=${ec}"
      if [[ "$ec" -eq 139 ]]; then
        diag_log "SIGSEGV in separate process at n=${n} key=${key}"
        exit 139
      fi
    else
      echo "OK n=${n} key=${key}" >>"$OUT"
    fi
  done
done

diag_log "one-process-per-render done total=${TOTAL} fails=${fails}"
exit 0

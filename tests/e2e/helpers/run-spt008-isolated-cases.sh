#!/usr/bin/env bash
# SPT-008: one Playwright invocation + fresh PHP server + fresh SQLite DB per case.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

CONFIG="playwright.spt008.config.ts"
TOTAL=7

# Explicit ordered case list: tag|log-basename
CASES=(
  '@spt008-calendar|spt008-calendar'
  '@spt008-tandem|spt008-tandem'
  '@spt008-average|spt008-average'
  '@spt008-mixed|spt008-mixed'
  '@spt008-multi|spt008-multi'
  '@spt008-empty|spt008-empty'
  '@spt008-pm-denied|spt008-pm-denied'
)

mkdir -p "${ROOT}/storage/logs"

idx=0
for entry in "${CASES[@]}"; do
  idx=$((idx + 1))
  tag="${entry%%|*}"
  log_base="${entry##*|}"
  log_path="${ROOT}/storage/logs/${log_base}.log"

  echo "SPT008_CASE_START case=${idx}/${TOTAL} tag=${tag} log=${log_path}"
  export E2E_SPT008_LOG="${log_path}"

  npx playwright test -c "${CONFIG}" --grep "${tag}"

  echo "SPT008_CASE_PASS case=${idx}/${TOTAL} tag=${tag}"
done

echo "SPT008_SUITE_PASS cases=${TOTAL}/${TOTAL}"

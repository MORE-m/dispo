#!/usr/bin/env bash
# Frische isolierte SPT-008-DB + Seeder für Diagnose.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

rm -f "$SPT008_DIAG_DB"
touch "$SPT008_DIAG_DB"

php -d memory_limit=512M artisan migrate --force >/dev/null
php -d memory_limit=512M artisan db:seed --class=E2ESpotDistributionExportSeeder --force >/dev/null

if [[ ! -f "$SPT008_DIAG_ORDERS_JSON" ]]; then
  echo "Missing orders fixture: ${SPT008_DIAG_ORDERS_JSON}" >&2
  exit 1
fi

echo "DB ready: ${SPT008_DIAG_DB}"

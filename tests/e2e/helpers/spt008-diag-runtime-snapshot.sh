#!/usr/bin/env bash
# Runtime-Snapshot für SPT-008-Diagnose (keine Secrets).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

OUT="${SPT008_DIAG_LOG_DIR}/runtime-snapshot.txt"
: >"$OUT"

{
  echo "=== SPT008 DIAG RUNTIME SNAPSHOT $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
  echo
  echo "--- php -v ---"
  php -v
  echo
  echo "--- php --ini ---"
  php --ini
  echo
  echo "--- php -m ---"
  php -m
  echo
  for ext in zip zlib libxml dom xml xmlwriter pdo_sqlite; do
    echo "--- php --ri ${ext} ---"
    php --ri "$ext" 2>&1 || echo "(missing or unavailable: ${ext})"
    echo
  done
  echo "--- composer show ---"
  composer show phpoffice/phpspreadsheet 2>&1 || true
  composer show maennchen/zipstream-php 2>&1 || true
  echo
  echo "--- uname -a ---"
  uname -a
  echo
  echo "--- ulimit -a ---"
  ulimit -a
  echo
  echo "--- command -v gdb ---"
  command -v gdb || echo "gdb: not found"
  echo
  echo "--- laravel server.php ---"
  ls -la vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
} | tee "$OUT"

diag_log "Runtime snapshot written: ${OUT}"

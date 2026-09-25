#!/usr/bin/env bash
# BL-P9-01b E2E webserver: PHP built-in server with UPL-006 upload limits.
# IMPORTANT: Do NOT use `php -d … artisan serve` — ServeCommand starts a child
# `php -S` without forwarding -d flags.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PORT="${E2E_BLP901B_PORT:-8041}"
HOST="${E2E_BLP901B_HOST:-127.0.0.1}"
UPLOAD_MAX="${E2E_BLP901B_UPLOAD_MAX_FILESIZE:-50M}"
POST_MAX="${E2E_BLP901B_POST_MAX_SIZE:-55M}"

SERVER_PHP="${ROOT}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
if [[ ! -f "$SERVER_PHP" ]]; then
  echo "Laravel server.php not found: ${SERVER_PHP}" >&2
  exit 1
fi

php \
  -d "upload_max_filesize=${UPLOAD_MAX}" \
  -d "post_max_size=${POST_MAX}" \
  -r 'fwrite(STDERR, "BLP901B_UPLOAD_RUNTIME upload_max_filesize=".ini_get("upload_max_filesize")." post_max_size=".ini_get("post_max_size").PHP_EOL);'

cd "${ROOT}/public"
exec php \
  -d "upload_max_filesize=${UPLOAD_MAX}" \
  -d "post_max_size=${POST_MAX}" \
  -S "${HOST}:${PORT}" \
  "$SERVER_PHP"

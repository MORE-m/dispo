#!/usr/bin/env bash
# Authentifizierter HTTP-XLSX-Download gegen laufenden Diagnose-Server.
# Inertia/Fortify: CSRF kommt aus XSRF-TOKEN Cookie (nicht Meta-Tag).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

KEY="${1:?fixture key required}"
EMAIL="${2:-sales@example.com}"
PORT="${SPT008_DIAG_PORT}"
BASE="http://127.0.0.1:${PORT}"
JAR="$SPT008_DIAG_COOKIE_JAR"
OUT_XLSX="${SPT008_DIAG_LOG_DIR}/download-${KEY}-$$.xlsx"

ORDER_ID="$(php -r '
$f=json_decode(file_get_contents(getenv("SPT008_DIAG_ORDERS_JSON")), true);
$key=$argv[1];
if (!isset($f[$key]["id"])) { fwrite(STDERR, "unknown key\n"); exit(2); }
echo (int)$f[$key]["id"];
' "$KEY")"

rm -f "$JAR"

# Session + XSRF cookie holen
curl -fsS -c "$JAR" -b "$JAR" "${BASE}/login" -o /dev/null

# XSRF-TOKEN Cookie URL-decoden für Header X-XSRF-TOKEN
XSRF="$(php -r '
$jar = file_get_contents(getenv("SPT008_DIAG_COOKIE_JAR"));
if (!preg_match("/\tXSRF-TOKEN\t([^\t\r\n]+)/", $jar, $m)) {
  fwrite(STDERR, "XSRF-TOKEN cookie missing\n");
  exit(3);
}
echo urldecode($m[1]);
')"

# Fortify/Inertia Login
LOGIN_CODE="$(curl -sS -o /tmp/spt008-login-resp.txt -w '%{http_code}' \
  -c "$JAR" -b "$JAR" \
  -X POST "${BASE}/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "X-XSRF-TOKEN: ${XSRF}" \
  --data "{\"email\":\"${EMAIL}\",\"password\":\"password\"}")"

if [[ "$LOGIN_CODE" != "200" && "$LOGIN_CODE" != "204" && "$LOGIN_CODE" != "302" ]]; then
  echo "login_failed http=${LOGIN_CODE} body=$(head -c 300 /tmp/spt008-login-resp.txt)" >&2
  exit 6
fi

# Cookie nach Login ggf. refreshed – Token neu lesen
XSRF="$(php -r '
$jar = file_get_contents(getenv("SPT008_DIAG_COOKIE_JAR"));
if (!preg_match("/\tXSRF-TOKEN\t([^\t\r\n]+)/", $jar, $m)) {
  fwrite(STDERR, "XSRF-TOKEN cookie missing after login\n");
  exit(3);
}
echo urldecode($m[1]);
')"

HTTP_CODE="$(curl -sS -o "$OUT_XLSX" -w '%{http_code}' \
  -c "$JAR" -b "$JAR" \
  -H "Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,*/*" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "X-XSRF-TOKEN: ${XSRF}" \
  "${BASE}/dispoauftraege/${ORDER_ID}/spotverteilung.xlsx" || true)"

BYTES=0
if [[ -f "$OUT_XLSX" ]]; then
  BYTES="$(wc -c <"$OUT_XLSX" | tr -d ' ')"
fi

echo "http=${HTTP_CODE} bytes=${BYTES} file=${OUT_XLSX} order_id=${ORDER_ID} key=${KEY}"

if [[ "$HTTP_CODE" != "200" ]]; then
  echo "download_body_head=$(head -c 400 "$OUT_XLSX" | tr '\n' ' ')" >&2
  if [[ -f "${SPT008_DIAG_ROOT}/storage/logs/laravel.log" ]]; then
    echo "laravel_log_tail:" >&2
    tail -n 40 "${SPT008_DIAG_ROOT}/storage/logs/laravel.log" >&2 || true
  fi
  exit 4
fi
if [[ "$BYTES" -lt 100 ]]; then
  exit 5
fi

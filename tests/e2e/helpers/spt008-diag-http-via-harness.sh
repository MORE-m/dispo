#!/usr/bin/env bash
# HTTP-Stress über denselben Harness wie die Playwright-Suite (Port 8033).
# Vermeidet Env-Drift gegenüber run-spt008-server.sh.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
cd "$ROOT"

MODE="${1:-artisan}" # artisan | raw
ROUNDS="${2:-5}"
PORT="${E2E_SPT008_PORT:-8033}"
DB="${E2E_SPT008_DB:-${ROOT}/database/e2e-spt-008.sqlite}"
LOG="${ROOT}/storage/logs/spt008-server.log"
OUT="${ROOT}/storage/logs/spt008-diag/http-via-harness-${MODE}.log"
ORDERS="${ROOT}/database/e2e-spt008-orders.json"
JAR="${ROOT}/storage/logs/spt008-diag/harness-cookies.txt"

mkdir -p "$(dirname "$OUT")" "$(dirname "$LOG")"
: >"$OUT"

export APP_ENV=testing
export E2E_SERVER=1
export APP_KEY="${APP_KEY:-base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=}"
export DB_CONNECTION=sqlite
export DB_DATABASE="$DB"
export DB_URL=""
export E2E_SPT008_PORT="$PORT"
export E2E_SPT008_DB="$DB"
export SPT008_SERVER_MODE="$MODE"

echo "SPT008_HARNESS_STRESS mode=${MODE} rounds=${ROUNDS} port=${PORT}" | tee -a "$OUT"

pass=0
fail=0
declare -a crash_positions=()

for round in $(seq 1 "$ROUNDS"); do
  # Kill leftovers on port
  if command -v lsof >/dev/null 2>&1; then
    for p in $(lsof -t -nP -iTCP:"${PORT}" -sTCP:LISTEN 2>/dev/null || true); do
      kill "$p" 2>/dev/null || true
      kill -9 "$p" 2>/dev/null || true
    done
  fi

  : >"$LOG"
  if command -v setsid >/dev/null 2>&1; then
    setsid bash "${SCRIPT_DIR}/run-spt008-server.sh" >>"$OUT" 2>&1 &
  else
    bash "${SCRIPT_DIR}/run-spt008-server.sh" >>"$OUT" 2>&1 &
  fi
  WRAPPER_PID=$!
  echo "$WRAPPER_PID" >"${ROOT}/storage/logs/spt008-diag/harness-wrapper.pid"

  ok=0
  for _ in $(seq 1 90); do
    if ! kill -0 "$WRAPPER_PID" 2>/dev/null; then
      echo "round=${round} wrapper died during boot" | tee -a "$OUT"
      break
    fi
    if curl -sS "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
      ok=1
      break
    fi
    sleep 0.5
  done

  if [[ "$ok" -ne 1 ]]; then
    fail=$((fail + 1))
    crash_positions+=("round${round}:boot")
    kill -- "-${WRAPPER_PID}" 2>/dev/null || true
    wait "$WRAPPER_PID" 2>/dev/null || true
    continue
  fi

  keys=(calendar tandem average mixed multi)
  crashed=0
  last_ok="none"
  for key in "${keys[@]}"; do
    if ! kill -0 "$WRAPPER_PID" 2>/dev/null; then
      echo "round=${round} wrapper dead before key=${key} last_ok=${last_ok}" | tee -a "$OUT"
      crashed=1
      crash_positions+=("round${round}:before_${key}")
      break
    fi

    ORDER_ID="$(php -r '$f=json_decode(file_get_contents($argv[1]),true); echo (int)$f[$argv[2]]["id"];' "$ORDERS" "$key")"
    rm -f "$JAR"
    curl -sS -c "$JAR" -b "$JAR" "http://127.0.0.1:${PORT}/login" -o /dev/null
    XSRF="$(php -r '
$jar=file_get_contents(getenv("JAR"));
if (!preg_match("/\tXSRF-TOKEN\t([^\t\r\n]+)/", $jar, $m)) {fwrite(STDERR,"no xsrf\n"); exit(3);}
echo urldecode($m[1]);
' 2>/dev/null)" || XSRF=""
    export JAR
    XSRF="$(JAR="$JAR" php -r '
$jar=file_get_contents(getenv("JAR"));
if (!preg_match("/\tXSRF-TOKEN\t([^\t\r\n]+)/", $jar, $m)) {fwrite(STDERR,"no xsrf\n"); exit(3);}
echo urldecode($m[1]);
')"

    curl -sS -o /dev/null -c "$JAR" -b "$JAR" \
      -X POST "http://127.0.0.1:${PORT}/login" \
      -H "Content-Type: application/json" \
      -H "Accept: application/json" \
      -H "X-Requested-With: XMLHttpRequest" \
      -H "X-XSRF-TOKEN: ${XSRF}" \
      --data "{\"email\":\"sales@example.com\",\"password\":\"password\"}" >/dev/null

    XSRF="$(JAR="$JAR" php -r '
$jar=file_get_contents(getenv("JAR"));
if (!preg_match("/\tXSRF-TOKEN\t([^\t\r\n]+)/", $jar, $m)) {fwrite(STDERR,"no xsrf\n"); exit(3);}
echo urldecode($m[1]);
')"

    OUT_XLSX="${ROOT}/storage/logs/spt008-diag/harness-${MODE}-r${round}-${key}.xlsx"
    echo "SPT008_STRESS round=${round} key=${key} before $(date -u +%Y-%m-%dT%H:%M:%SZ)" | tee -a "$OUT"
    CODE="$(curl -sS -o "$OUT_XLSX" -w '%{http_code}' \
      -c "$JAR" -b "$JAR" \
      -H "X-XSRF-TOKEN: ${XSRF}" \
      "http://127.0.0.1:${PORT}/dispoauftraege/${ORDER_ID}/spotverteilung.xlsx" || echo 000)"
    BYTES=0
    [[ -f "$OUT_XLSX" ]] && BYTES="$(wc -c <"$OUT_XLSX" | tr -d ' ')"
    echo "SPT008_STRESS round=${round} key=${key} after http=${CODE} bytes=${BYTES}" | tee -a "$OUT"

    if [[ "$CODE" != "200" ]] || ! kill -0 "$WRAPPER_PID" 2>/dev/null; then
      crashed=1
      crash_positions+=("round${round}:${key}:http=${CODE}")
      echo "CRASH round=${round} key=${key} http=${CODE} wrapper_alive=$(kill -0 "$WRAPPER_PID" 2>/dev/null && echo yes || echo no)" | tee -a "$OUT"
      tail -n 20 "$LOG" | tee -a "$OUT" || true
      break
    fi
    last_ok="$key"
  done

  if [[ "$crashed" -eq 0 ]]; then
    pass=$((pass + 1))
    echo "round=${round} PASS" | tee -a "$OUT"
  else
    fail=$((fail + 1))
  fi

  # Cleanup wrapper + listeners
  kill "$WRAPPER_PID" 2>/dev/null || true
  if command -v setsid >/dev/null 2>&1; then
    kill -- "-${WRAPPER_PID}" 2>/dev/null || true
  fi
  wait "$WRAPPER_PID" 2>/dev/null || true
  if command -v lsof >/dev/null 2>&1; then
    for p in $(lsof -t -nP -iTCP:"${PORT}" -sTCP:LISTEN 2>/dev/null || true); do
      kill -9 "$p" 2>/dev/null || true
    done
  fi
  # Also kill artisan serve children by pattern on this port
  pkill -f "artisan serve --host=127.0.0.1 --port=${PORT}" 2>/dev/null || true
  pkill -f "127.0.0.1:${PORT}" 2>/dev/null || true
  sleep 0.5
done

echo "SUMMARY mode=${MODE} PASS=${pass}/${ROUNDS} FAIL=${fail}/${ROUNDS} positions=${crash_positions[*]-none}" | tee -a "$OUT"
rg -n 'SPT008_SERVER_EXIT' "$LOG" "$OUT" 2>/dev/null | tee -a "$OUT" || true

if [[ "$fail" -gt 0 ]]; then
  exit 1
fi
exit 0

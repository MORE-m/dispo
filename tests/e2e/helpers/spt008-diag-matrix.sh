#!/usr/bin/env bash
# SPT-008 Diagnose-Matrix A/B/C (+ partial D via HTTP without browser UI).
# Kein Produktfix. Exit != 0 wenn Diagnose selbst crasht (z.B. 139).
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=spt008-diag-env.sh
source "${SCRIPT_DIR}/spt008-diag-env.sh"

chmod +x "${SCRIPT_DIR}"/spt008-diag-*.sh "${SCRIPT_DIR}/run-spt008-server.sh" 2>/dev/null || true

: >"$SPT008_DIAG_REPORT"
diag_section "SPT-008 DIAG MATRIX start $(date -u +%Y-%m-%dT%H:%M:%SZ)"
diag_log "base_hint=$(git -C "$SPT008_DIAG_ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
diag_log "tree=$(git -C "$SPT008_DIAG_ROOT" rev-parse HEAD^{tree} 2>/dev/null || echo unknown)"

server_alive() {
  local mode="$1"
  local pid_file="${SPT008_DIAG_LOG_DIR}/server-${mode}.pid"
  if [[ ! -f "$pid_file" ]]; then
    return 1
  fi
  local pid
  pid="$(cat "$pid_file")"
  kill -0 "$pid" 2>/dev/null
}

interpret_exit() {
  local ec="$1"
  if [[ "$ec" -eq 139 ]]; then
    echo "139/SIGSEGV"
  elif [[ "$ec" -gt 128 ]]; then
    echo "${ec}/signal=$((ec - 128))"
  else
    echo "${ec}"
  fi
}

try_dmesg() {
  diag_section "dmesg tail (best effort)"
  if dmesg 2>/dev/null | tail -n 40 | tee -a "$SPT008_DIAG_REPORT"; then
    true
  else
    diag_log "dmesg unavailable or permission denied"
  fi
}

# ---------- Runtime snapshot ----------
diag_section "1) Runtime snapshot"
bash "${SCRIPT_DIR}/spt008-diag-runtime-snapshot.sh" || true

# ---------- Prepare DB once for CLI tests ----------
diag_section "2) Prepare DB for CLI"
bash "${SCRIPT_DIR}/spt008-diag-prepare-db.sh"

# ---------- Diagnose A: same-process CLI ----------
diag_section "3) Diagnose A – same-process CLI renderer (20 rounds)"
CLI_SAME_LOG="${SPT008_DIAG_LOG_DIR}/cli-same-process.log"
set +e
php -d memory_limit=512M \
  "${SCRIPT_DIR}/spt008-diag-cli-same-process.php" --rounds=20 \
  >"$CLI_SAME_LOG" 2>&1
CLI_SAME_EC=$?
set -e
diag_log "same-process exit=$(interpret_exit "$CLI_SAME_EC")"
tail -n 20 "$CLI_SAME_LOG" | tee -a "$SPT008_DIAG_REPORT" || true
if [[ "$CLI_SAME_EC" -eq 139 ]]; then
  diag_log "CLASS_A_SIGNAL=SIGSEGV"
  try_dmesg
fi

# ---------- Diagnose: one-process-per-render ----------
diag_section "4) Diagnose – one-process-per-render (50)"
set +e
bash "${SCRIPT_DIR}/spt008-diag-cli-one-process-per-render.sh" 50
CLI_ONE_EC=$?
set -e
diag_log "one-process-per-render exit=$(interpret_exit "$CLI_ONE_EC")"

# ---------- Helper: HTTP sequence on a mode ----------
run_http_sequence() {
  local mode="$1"
  local label="$2"
  local seq_log="${SPT008_DIAG_LOG_DIR}/http-seq-${mode}.log"
  : >"$seq_log"

  bash "${SCRIPT_DIR}/spt008-diag-prepare-db.sh" >>"$seq_log" 2>&1
  set +e
  bash "${SCRIPT_DIR}/spt008-diag-start-server.sh" "$mode" >>"$seq_log" 2>&1
  local start_ec=$?
  set -e
  if [[ "$start_ec" -ne 0 ]]; then
    diag_log "${label}: server start FAILED exit=$(interpret_exit "$start_ec")"
    return "$start_ec"
  fi

  local keys=(calendar tandem average mixed multi)
  local i=0
  local crash_at="none"
  local last_ok="none"
  for key in "${keys[@]}"; do
    i=$((i + 1))
    if ! server_alive "$mode"; then
      crash_at="before_${key}"
      diag_log "${label}: server dead before key=${key} last_ok=${last_ok}"
      break
    fi
    echo "SPT008_STRESS iteration=${i} key=${key} before $(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$seq_log"
    set +e
    bash "${SCRIPT_DIR}/spt008-diag-http-download.sh" "$key" >>"$seq_log" 2>&1
    local dl_ec=$?
    set -e
    echo "SPT008_STRESS iteration=${i} key=${key} after exit=${dl_ec} $(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$seq_log"
    if [[ "$dl_ec" -ne 0 ]] || ! server_alive "$mode"; then
      crash_at="$key"
      diag_log "${label}: FAIL at iteration=${i} key=${key} download_exit=${dl_ec} server_alive=$(server_alive "$mode" && echo yes || echo no)"
      break
    fi
    last_ok="$key"
  done

  if [[ "$crash_at" == "none" ]]; then
    diag_log "${label}: PASS full sequence last_ok=${last_ok}"
  fi

  bash "${SCRIPT_DIR}/spt008-diag-stop-server.sh" "$mode" >>"$seq_log" 2>&1 || true
  if [[ "$crash_at" != "none" ]]; then
    return 1
  fi
  return 0
}

# ---------- Diagnose 1/2/3 via official SPT harness (HTTP, no browser) ----------
diag_section "5) Diagnose – HTTP via official harness artisan ×5 sequences"
set +e
bash "${SCRIPT_DIR}/spt008-diag-http-via-harness.sh" artisan 5
HARNESS_ART_EC=$?
set -e
diag_log "harness_artisan_seq exit=$(interpret_exit "$HARNESS_ART_EC")"
tail -n 40 "${SPT008_DIAG_LOG_DIR}/http-via-harness-artisan.log" | tee -a "$SPT008_DIAG_REPORT" || true

# Force port cleanup before repeat/raw sections
if command -v lsof >/dev/null 2>&1; then
  for p in $(lsof -t -nP -iTCP:8033 -sTCP:LISTEN 2>/dev/null || true); do
    kill -9 "$p" 2>/dev/null || true
  done
fi
pkill -f "artisan serve --host=127.0.0.1 --port=8033" 2>/dev/null || true
pkill -f "127.0.0.1:8033" 2>/dev/null || true
sleep 1

diag_section "6) Diagnose – repeated calendar via harness (10 exports, one server)"
# One long-lived harness: start once, hit calendar 10 times
export APP_ENV=testing E2E_SERVER=1
export APP_KEY="${APP_KEY:-base64:2fl+Ktvkfl+Fuz4Qp/Ej30N8mK8nwZuqqjHadrQtdZg=}"
export DB_CONNECTION=sqlite
export DB_DATABASE="${SPT008_DIAG_ROOT}/database/e2e-spt-008.sqlite"
export DB_URL=""
export E2E_SPT008_PORT=8033
export E2E_SPT008_DB="${DB_DATABASE}"
export SPT008_SERVER_MODE=artisan
REP_LOG="${SPT008_DIAG_LOG_DIR}/repeat-calendar-harness.log"
: >"$REP_LOG"
if command -v lsof >/dev/null 2>&1; then
  for p in $(lsof -t -nP -iTCP:8033 -sTCP:LISTEN 2>/dev/null || true); do kill -9 "$p" 2>/dev/null || true; done
fi
if command -v setsid >/dev/null 2>&1; then
  setsid bash "${SCRIPT_DIR}/run-spt008-server.sh" >>"$REP_LOG" 2>&1 &
else
  bash "${SCRIPT_DIR}/run-spt008-server.sh" >>"$REP_LOG" 2>&1 &
fi
WRAP_PID=$!
for _ in $(seq 1 90); do
  code="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:8033/health" || echo 000)"
  if [[ "$code" == "200" ]]; then
    break
  fi
  kill -0 "$WRAP_PID" 2>/dev/null || break
  sleep 0.5
done
REP_PASS=0
REP_CRASH_AT="none"
ORDERS="${SPT008_DIAG_ROOT}/database/e2e-spt008-orders.json"
for i in $(seq 1 10); do
  if ! kill -0 "$WRAP_PID" 2>/dev/null; then
    REP_CRASH_AT="$i"
    diag_log "repeat_calendar CRASH before iteration=${i}"
    break
  fi
  ORDER_ID="$(php -r '$f=json_decode(file_get_contents($argv[1]),true); echo (int)$f["calendar"]["id"];' "$ORDERS")"
  JAR="${SPT008_DIAG_LOG_DIR}/rep-cookies.txt"
  rm -f "$JAR"
  curl -sS -c "$JAR" -b "$JAR" "http://127.0.0.1:8033/login" -o /dev/null || true
  XSRF="$(JAR="$JAR" php -r '$jar=@file_get_contents(getenv("JAR")); if (!preg_match("/\tXSRF-TOKEN\t([^\t\r\n]+)/",$jar,$m)) {echo ""; exit(0);} echo urldecode($m[1]);')"
  curl -sS -o /dev/null -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:8033/login" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -H "X-Requested-With: XMLHttpRequest" -H "X-XSRF-TOKEN: ${XSRF}" \
    --data '{"email":"sales@example.com","password":"password"}' >/dev/null || true
  XSRF="$(JAR="$JAR" php -r '$jar=@file_get_contents(getenv("JAR")); if (!preg_match("/\tXSRF-TOKEN\t([^\t\r\n]+)/",$jar,$m)) {echo ""; exit(0);} echo urldecode($m[1]);')"
  OUT_X="${SPT008_DIAG_LOG_DIR}/rep-cal-${i}.xlsx"
  echo "SPT008_STRESS iteration=${i} before" >>"$REP_LOG"
  CODE="$(curl -sS -o "$OUT_X" -w '%{http_code}' -c "$JAR" -b "$JAR" -H "X-XSRF-TOKEN: ${XSRF}" \
    "http://127.0.0.1:8033/dispoauftraege/${ORDER_ID}/spotverteilung.xlsx" || echo 000)"
  BYTES=0; [[ -f "$OUT_X" ]] && BYTES="$(wc -c <"$OUT_X" | tr -d ' ')"
  echo "SPT008_STRESS iteration=${i} after http=${CODE} bytes=${BYTES}" >>"$REP_LOG"
  if [[ "$CODE" != "200" ]] || ! kill -0 "$WRAP_PID" 2>/dev/null; then
    REP_CRASH_AT="$i"
    diag_log "repeat_calendar FAIL iteration=${i} http=${CODE} alive=$(kill -0 "$WRAP_PID" 2>/dev/null && echo yes || echo no)"
    # Capture server exit line if any
    grep -n 'SPT008_SERVER_EXIT' "${SPT008_DIAG_ROOT}/storage/logs/spt008-server.log" | tee -a "$SPT008_DIAG_REPORT" || true
    break
  fi
  REP_PASS=$((REP_PASS + 1))
  diag_log "repeat_calendar iteration=${i} PASS http=${CODE} bytes=${BYTES}"
done
if [[ "$REP_CRASH_AT" == "none" ]]; then
  diag_log "repeat_calendar summary PASS=${REP_PASS}/10"
else
  diag_log "repeat_calendar summary PASS=${REP_PASS}/10 crash_at=${REP_CRASH_AT}"
fi
kill -- "-${WRAP_PID}" 2>/dev/null || true
wait "$WRAP_PID" 2>/dev/null || true

diag_section "7) Diagnose – HTTP via official harness raw php -S ×5 sequences"
set +e
bash "${SCRIPT_DIR}/spt008-diag-http-via-harness.sh" raw 5
HARNESS_RAW_EC=$?
set -e
diag_log "harness_raw_seq exit=$(interpret_exit "$HARNESS_RAW_EC")"
tail -n 40 "${SPT008_DIAG_LOG_DIR}/http-via-harness-raw.log" | tee -a "$SPT008_DIAG_REPORT" || true

# Keep legacy labels for summary compatibility
ART_SEQ_PASS=0
ART_SEQ_FAIL=5
RAW_SEQ_PASS=0
RAW_SEQ_FAIL=5
if [[ "${HARNESS_ART_EC:-1}" -eq 0 ]]; then ART_SEQ_PASS=5; ART_SEQ_FAIL=0; fi
if [[ "${HARNESS_RAW_EC:-1}" -eq 0 ]]; then RAW_SEQ_PASS=5; RAW_SEQ_FAIL=0; fi
# Replace rg with grep -E for portability in classification parsing
if grep -Eq 'SUMMARY mode=artisan PASS=' "${SPT008_DIAG_LOG_DIR}/http-via-harness-artisan.log" 2>/dev/null; then
  ART_LINE="$(grep 'SUMMARY mode=artisan' "${SPT008_DIAG_LOG_DIR}/http-via-harness-artisan.log" | tail -1)"
  diag_log "$ART_LINE"
  ART_SEQ_PASS="$(echo "$ART_LINE" | sed -n 's/.*PASS=\([0-9]*\)\/.*/\1/p')"
  ART_SEQ_FAIL="$(echo "$ART_LINE" | sed -n 's/.*FAIL=\([0-9]*\)\/.*/\1/p')"
  ART_SEQ_PASS="${ART_SEQ_PASS:-0}"
  ART_SEQ_FAIL="${ART_SEQ_FAIL:-5}"
fi
if grep -Eq 'SUMMARY mode=raw PASS=' "${SPT008_DIAG_LOG_DIR}/http-via-harness-raw.log" 2>/dev/null; then
  RAW_LINE="$(grep 'SUMMARY mode=raw' "${SPT008_DIAG_LOG_DIR}/http-via-harness-raw.log" | tail -1)"
  diag_log "$RAW_LINE"
  RAW_SEQ_PASS="$(echo "$RAW_LINE" | sed -n 's/.*PASS=\([0-9]*\)\/.*/\1/p')"
  RAW_SEQ_FAIL="$(echo "$RAW_LINE" | sed -n 's/.*FAIL=\([0-9]*\)\/.*/\1/p')"
  RAW_SEQ_PASS="${RAW_SEQ_PASS:-0}"
  RAW_SEQ_FAIL="${RAW_SEQ_FAIL:-5}"
fi

# Skip fragile custom-server GDB HTTP path; original suite already captures SIGSEGV.
# Optional: attach note only.
diag_section "9) GDB note"
if command -v gdb >/dev/null 2>&1; then
  diag_log "gdb available: $(gdb --version | head -n 1)"
  diag_log "Native bt intentionally deferred to follow-up once HTTP harness stress isolates crash under gdb."
else
  diag_log "gdb not installed"
fi

try_dmesg

# ---------- Classification summary ----------
diag_section "10) Classification summary"
diag_log "CLI_SAME_EC=$(interpret_exit "$CLI_SAME_EC")"
diag_log "CLI_ONE_EC=$(interpret_exit "$CLI_ONE_EC")"
diag_log "HARNESS_ART_EC=$(interpret_exit "${HARNESS_ART_EC:-1}")"
diag_log "HARNESS_RAW_EC=$(interpret_exit "${HARNESS_RAW_EC:-1}")"
diag_log "REPEAT_CALENDAR=${REP_PASS}/10 crash_at=${REP_CRASH_AT}"
diag_log "ARTISAN_SEQ=${ART_SEQ_PASS}/5 fail=${ART_SEQ_FAIL}"
diag_log "RAW_SEQ=${RAW_SEQ_PASS}/5 fail=${RAW_SEQ_FAIL}"

# Derive A/B/C
CLASS_A="PASS"
if [[ "$CLI_SAME_EC" -eq 139 ]]; then CLASS_A="FAIL"; elif [[ "$CLI_SAME_EC" -ne 0 ]]; then CLASS_A="FAIL_OTHER_${CLI_SAME_EC}"; fi

CLASS_B="PASS"
if [[ "$RAW_SEQ_FAIL" -gt 0 ]]; then CLASS_B="FAIL"; fi

CLASS_C="PASS"
if [[ "$ART_SEQ_FAIL" -gt 0 ]]; then CLASS_C="FAIL"; fi

CLASS_D="nicht unterscheidbar (HTTP ohne Browser-UI; Browser-Suite separat)"
if [[ "$CLASS_A" == "PASS" && ( "$CLASS_B" == "FAIL" || "$CLASS_C" == "FAIL" ) ]]; then
  CLASS_D="FAIL weniger wahrscheinlich (HTTP crasht ohne Browser)"
fi

diag_log "A_same_process_xlsx=${CLASS_A}"
diag_log "B_raw_php_S=${CLASS_B}"
diag_log "C_artisan_serve=${CLASS_C}"
diag_log "D_browser_specific=${CLASS_D}"

# Likely class
LIKELY="noch nicht unterscheidbar"
if [[ "$CLASS_A" == "FAIL" ]]; then
  LIKELY="A (same-process XLSX renderer)"
elif [[ "$CLASS_B" == "FAIL" && "$CLASS_C" == "FAIL" ]]; then
  LIKELY="B (raw php -S / built-in server path; artisan wraps same -S)"
elif [[ "$CLASS_C" == "FAIL" && "$CLASS_B" == "PASS" ]]; then
  LIKELY="C (artisan serve wrapper)"
elif [[ "$CLASS_B" == "FAIL" && "$CLASS_C" == "PASS" ]]; then
  LIKELY="B (raw php -S) – unerwartet vs artisan"
elif [[ "$CLASS_A" == "PASS" && "$CLASS_B" == "PASS" && "$CLASS_C" == "PASS" ]]; then
  LIKELY="nicht reproduziert in dieser Diagnose-Matrix (CI-flaky / D möglich)"
fi
diag_log "LIKELY_ROOT_CAUSE_CLASS=${LIKELY}"

diag_section "DONE $(date -u +%Y-%m-%dT%H:%M:%SZ)"
diag_log "report=${SPT008_DIAG_REPORT}"

# Matrix job shall go red if we observed SIGSEGV anywhere critical
if [[ "$CLI_SAME_EC" -eq 139 || "$CLI_ONE_EC" -eq 139 || "$ART_SEQ_FAIL" -gt 0 || "$RAW_SEQ_FAIL" -gt 0 || "$REP_CRASH_AT" != "none" ]]; then
  exit 139
fi
exit 0

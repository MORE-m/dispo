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

# ---------- Diagnose 1: mixed fresh ×5 (own server each) ----------
diag_section "5) Diagnose 1 – mixed fresh ×5 (fresh DB+server each)"
MIXED_PASS=0
MIXED_FAIL=0
for run in 1 2 3 4 5; do
  bash "${SCRIPT_DIR}/spt008-diag-prepare-db.sh" >/dev/null
  set +e
  bash "${SCRIPT_DIR}/spt008-diag-start-server.sh" artisan >/dev/null
  start_ec=$?
  set -e
  if [[ "$start_ec" -ne 0 ]]; then
    MIXED_FAIL=$((MIXED_FAIL + 1))
    diag_log "mixed_fresh run=${run} SERVER_BOOT_FAIL exit=$(interpret_exit "$start_ec")"
    continue
  fi
  started_at=$(date +%s)
  set +e
  out="$(bash "${SCRIPT_DIR}/spt008-diag-http-download.sh" mixed 2>&1)"
  dl_ec=$?
  set -e
  elapsed=$(( $(date +%s) - started_at ))
  alive="no"
  if server_alive artisan; then alive="yes"; fi
  if [[ "$dl_ec" -eq 0 && "$alive" == "yes" ]]; then
    MIXED_PASS=$((MIXED_PASS + 1))
    diag_log "mixed_fresh run=${run} PASS ${out} elapsed_s=${elapsed} server_alive=${alive}"
  else
    MIXED_FAIL=$((MIXED_FAIL + 1))
    diag_log "mixed_fresh run=${run} FAIL exit=${dl_ec} ${out} elapsed_s=${elapsed} server_alive=${alive}"
  fi
  bash "${SCRIPT_DIR}/spt008-diag-stop-server.sh" artisan >/dev/null || true
done
diag_log "mixed_fresh summary PASS=${MIXED_PASS}/5 FAIL=${MIXED_FAIL}/5"

# ---------- Diagnose 2: repeated calendar export on one server ----------
diag_section "6) Diagnose 2 – repeated calendar export ×10 (one artisan server)"
bash "${SCRIPT_DIR}/spt008-diag-prepare-db.sh" >/dev/null
set +e
bash "${SCRIPT_DIR}/spt008-diag-start-server.sh" artisan >/dev/null
set -e
REP_PASS=0
REP_CRASH_AT="none"
for i in $(seq 1 10); do
  if ! server_alive artisan; then
    REP_CRASH_AT="$i"
    diag_log "repeat_calendar CRASH before iteration=${i}"
    break
  fi
  echo "SPT008_STRESS iteration=${i} route=calendar before" >>"${SPT008_DIAG_LOG_DIR}/repeat-calendar.log"
  set +e
  out="$(bash "${SCRIPT_DIR}/spt008-diag-http-download.sh" calendar 2>&1)"
  dl_ec=$?
  set -e
  echo "SPT008_STRESS iteration=${i} after exit=${dl_ec} ${out}" >>"${SPT008_DIAG_LOG_DIR}/repeat-calendar.log"
  if [[ "$dl_ec" -ne 0 ]] || ! server_alive artisan; then
    REP_CRASH_AT="$i"
    diag_log "repeat_calendar FAIL iteration=${i} exit=${dl_ec} server_alive=$(server_alive artisan && echo yes || echo no) ${out}"
    break
  fi
  REP_PASS=$((REP_PASS + 1))
  diag_log "repeat_calendar iteration=${i} PASS ${out}"
done
if [[ "$REP_CRASH_AT" == "none" ]]; then
  diag_log "repeat_calendar summary PASS=${REP_PASS}/10"
else
  diag_log "repeat_calendar summary PASS=${REP_PASS}/10 crash_at=${REP_CRASH_AT}"
fi
bash "${SCRIPT_DIR}/spt008-diag-stop-server.sh" artisan >/dev/null || true

# ---------- Diagnose 3: original sequence ×5 artisan ----------
diag_section "7) Diagnose 3 – original sequence ×5 (artisan serve)"
ART_SEQ_PASS=0
ART_SEQ_FAIL=0
declare -a ART_CRASH_POS=()
for run in 1 2 3 4 5; do
  set +e
  run_http_sequence artisan "artisan_seq run=${run}"
  ec=$?
  set -e
  if [[ "$ec" -eq 0 ]]; then
    ART_SEQ_PASS=$((ART_SEQ_PASS + 1))
  else
    ART_SEQ_FAIL=$((ART_SEQ_FAIL + 1))
    # Extract crash key from last log lines if present
    ART_CRASH_POS+=("run${run}")
  fi
done
diag_log "artisan_sequence summary PASS=${ART_SEQ_PASS}/5 FAIL=${ART_SEQ_FAIL}/5 positions=${ART_CRASH_POS[*]-none}"

# ---------- Diagnose 6: raw php -S sequence ×5 ----------
diag_section "8) Diagnose 6 – original sequence ×5 (raw php -S)"
RAW_SEQ_PASS=0
RAW_SEQ_FAIL=0
declare -a RAW_CRASH_POS=()
for run in 1 2 3 4 5; do
  set +e
  run_http_sequence raw "raw_seq run=${run}"
  ec=$?
  set -e
  if [[ "$ec" -eq 0 ]]; then
    RAW_SEQ_PASS=$((RAW_SEQ_PASS + 1))
  else
    RAW_SEQ_FAIL=$((RAW_SEQ_FAIL + 1))
    RAW_CRASH_POS+=("run${run}")
  fi
done
diag_log "raw_sequence summary PASS=${RAW_SEQ_PASS}/5 FAIL=${RAW_SEQ_FAIL}/5 positions=${RAW_CRASH_POS[*]-none}"

# ---------- Optional GDB on artisan/raw if crash reproducible ----------
diag_section "9) GDB attempt (best effort)"
if command -v gdb >/dev/null 2>&1; then
  # Catch-on-crash pattern: start raw php -S under gdb in background via
  # a wrapper that dumps bt when the inferior receives SIGSEGV.
  bash "${SCRIPT_DIR}/spt008-diag-prepare-db.sh" >/dev/null
  GDB_LOG="${SPT008_DIAG_LOG_DIR}/gdb-raw.log"
  ROUTER="${SPT008_DIAG_ROOT}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
  : >"$GDB_LOG"
  (
    cd "${SPT008_DIAG_ROOT}/public"
    gdb -q -nx \
      -ex "set pagination off" \
      -ex "set confirm off" \
      -ex "handle SIGPIPE nostop noprint pass" \
      -ex "run" \
      -ex "echo \\n=== GDB BACKTRACE AFTER STOP ===\\n" \
      -ex "bt" \
      -ex "info sharedlibrary" \
      -ex "quit" \
      --args php -d memory_limit=512M -S "127.0.0.1:${SPT008_DIAG_PORT}" "$ROUTER" \
      >"$GDB_LOG" 2>&1 &
    echo $! >"${SPT008_DIAG_LOG_DIR}/gdb.pid"
  )
  # Wait for listen (gdb overhead)
  for _ in $(seq 1 60); do
    if curl -fsS "http://127.0.0.1:${SPT008_DIAG_PORT}/health" >/dev/null 2>&1; then
      break
    fi
    sleep 0.5
  done
  for key in calendar tandem average mixed multi calendar tandem average mixed multi; do
    bash "${SCRIPT_DIR}/spt008-diag-http-download.sh" "$key" >>"${SPT008_DIAG_LOG_DIR}/gdb-hits.log" 2>&1 || true
    if [[ -f "${SPT008_DIAG_LOG_DIR}/gdb.pid" ]] && ! kill -0 "$(cat "${SPT008_DIAG_LOG_DIR}/gdb.pid")" 2>/dev/null; then
      diag_log "gdb inferior stopped during key=${key}"
      break
    fi
  done
  if [[ -f "${SPT008_DIAG_LOG_DIR}/gdb.pid" ]]; then
    kill "$(cat "${SPT008_DIAG_LOG_DIR}/gdb.pid")" 2>/dev/null || true
    wait "$(cat "${SPT008_DIAG_LOG_DIR}/gdb.pid")" 2>/dev/null || true
    rm -f "${SPT008_DIAG_LOG_DIR}/gdb.pid"
  fi
  diag_log "gdb log: ${GDB_LOG}"
  tail -n 100 "$GDB_LOG" | tee -a "$SPT008_DIAG_REPORT" || true
else
  diag_log "gdb not installed – skip native backtrace"
fi

try_dmesg

# ---------- Classification summary ----------
diag_section "10) Classification summary"
diag_log "CLI_SAME_EC=$(interpret_exit "$CLI_SAME_EC")"
diag_log "CLI_ONE_EC=$(interpret_exit "$CLI_ONE_EC")"
diag_log "MIXED_FRESH=${MIXED_PASS}/5"
diag_log "REPEAT_CALENDAR=${REP_PASS}/10 crash_at=${REP_CRASH_AT}"
diag_log "ARTISAN_SEQ=${ART_SEQ_PASS}/5"
diag_log "RAW_SEQ=${RAW_SEQ_PASS}/5"

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

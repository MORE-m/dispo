#!/usr/bin/env bash
# Lokaler Entwicklungsserver für Dispo (XAMPP/MySQL).
# Nutzung: ./scripts/start-dev.sh
# Erzwungener Neustart: DISPO_RESTART=1 ./scripts/start-dev.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PORT="${DISPO_PORT:-8000}"
HOST="${DISPO_HOST:-127.0.0.1}"
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/xamppfiles/bin/mysql}"
readonly MAX_STOP_WAIT="${DISPO_STOP_WAIT:-10}"

resolve_php_bin() {
    local candidate version_major version_minor

    for candidate in \
        "${PHP_BIN:-}" \
        "$(command -v php 2>/dev/null || true)" \
        /opt/homebrew/bin/php \
        /opt/homebrew/opt/php/bin/php \
        /opt/homebrew/opt/php@8.5/bin/php \
        /opt/homebrew/opt/php@8.4/bin/php \
        /opt/homebrew/opt/php@8.3/bin/php \
        /usr/local/bin/php \
        /usr/local/opt/php/bin/php \
        /Applications/XAMPP/xamppfiles/bin/php
    do
        [[ -z "$candidate" || ! -x "$candidate" ]] && continue

        version_major="$("$candidate" -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || true)"
        version_minor="$("$candidate" -r 'echo PHP_MINOR_VERSION;' 2>/dev/null || true)"

        if [[ "$version_major" -gt 8 ]] || [[ "$version_major" -eq 8 && "$version_minor" -ge 3 ]]; then
            echo "$candidate"
            return 0
        fi
    done

    return 1
}

port_listener_pids() {
    lsof -nP -iTCP:"${PORT}" -sTCP:LISTEN -t 2>/dev/null | sort -u
}

process_command() {
    local pid="$1"
    ps -p "$pid" -o command= 2>/dev/null | sed 's/^ *//'
}

process_cwd() {
    local pid="$1"
    lsof -a -p "$pid" -d cwd -Fn 2>/dev/null | awk -F'/' '/^n/ { print substr($0, 2); exit }'
}

is_project_artisan_serve_pid() {
    local pid="$1"
    local cmd cwd parent_pid parent_cmd parent_cwd
    local laravel_server="${ROOT}/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"

    cmd="$(process_command "$pid")"
    cwd="$(process_cwd "$pid")"

    if [[ "$cmd" == *"artisan serve"* && "$cwd" == "$ROOT" ]]; then
        return 0
    fi

    if [[ "$cmd" == *"php -S"* && "$cmd" == *"${ROOT}/vendor/laravel/"* && "$cmd" == *"server.php"* ]]; then
        return 0
    fi

    if [[ -f "$laravel_server" && "$cmd" == *"php -S"* && "$cmd" == *"$laravel_server"* ]]; then
        return 0
    fi

    parent_pid="$(ps -p "$pid" -o ppid= 2>/dev/null | tr -d ' ' || true)"
    if [[ -n "$parent_pid" ]]; then
        parent_cmd="$(process_command "$parent_pid")"
        parent_cwd="$(process_cwd "$parent_pid")"
        if [[ "$parent_cmd" == *"artisan serve"* && "$parent_cwd" == "$ROOT" ]]; then
            return 0
        fi
    fi

    return 1
}

server_responds() {
    curl -sf --max-time 2 "http://${HOST}:${PORT}/up" >/dev/null 2>&1
}

report_foreign_process() {
    local pid="$1"
    local cmd

    cmd="$(process_command "$pid")"
    cmd="${cmd:-unbekannt}"

    echo "FEHLER: Port ${PORT} ist von einem fremden Prozess belegt (PID ${pid})."
    echo "        Kommando: ${cmd}"
    echo "        Bitte den Port manuell freigeben."
    echo "        Ein erzwungener Neustart ist nur für den eigenen Dispo-Server möglich."
    exit 1
}

stop_pid_gracefully() {
    local pid="$1"
    local waited=0

    if ! kill -0 "$pid" 2>/dev/null; then
        return 0
    fi

    kill -TERM "$pid" 2>/dev/null || return 1

    while kill -0 "$pid" 2>/dev/null && [[ "$waited" -lt "$MAX_STOP_WAIT" ]]; do
        sleep 1
        waited=$((waited + 1))
    done

    if kill -0 "$pid" 2>/dev/null; then
        echo "Prozess ${pid} reagiert nicht auf TERM – sende KILL …"
        kill -KILL "$pid" 2>/dev/null || true
        sleep 1
    fi

    ! kill -0 "$pid" 2>/dev/null
}

stop_project_listeners() {
    local pid

    while IFS= read -r pid; do
        [[ -z "$pid" ]] && continue
        if is_project_artisan_serve_pid "$pid"; then
            echo "Beende Dispo-Server (PID ${pid}) …"
            stop_pid_gracefully "$pid" || {
                echo "FEHLER: Dispo-Server (PID ${pid}) konnte nicht beendet werden."
                exit 1
            }
        else
            report_foreign_process "$pid"
        fi
    done < <(port_listener_pids)
}

if ! PHP_BIN="$(resolve_php_bin)"; then
    echo "FEHLER: PHP >= 8.3 nicht gefunden (XAMPP liefert nur 8.2)."
    echo "        Homebrew: brew install php"
    echo "        Oder: PHP_BIN=/pfad/zu/php ./scripts/start-dev.sh"
    exit 1
fi

echo "== Dispo Dev-Server =="
echo "Projekt: $ROOT"
echo "PHP:     $("$PHP_BIN" -r 'echo PHP_VERSION;')"
echo "URL:     http://${HOST}:${PORT}"
echo

if [[ ! -f .env ]]; then
    echo "FEHLER: .env fehlt. Einmalig: cp .env.example .env && php artisan key:generate"
    exit 1
fi

SKIP_SERVE=0
EXISTING_PID=""

if listener_pids="$(port_listener_pids)"; then
    first_pid="$(printf '%s\n' "$listener_pids" | head -n 1)"

    if is_project_artisan_serve_pid "$first_pid"; then
        if [[ "${DISPO_RESTART:-}" == "1" ]]; then
            echo "Neustart angefordert (DISPO_RESTART=1) …"
            EXISTING_PID="$first_pid"
            stop_project_listeners
            EXISTING_PID=""
        elif server_responds; then
            EXISTING_PID="$first_pid"
            SKIP_SERVE=1
            echo "Dispo-Server läuft bereits auf http://${HOST}:${PORT} (PID ${EXISTING_PID})"
            echo "Nur Setup (Migrationen, Testbenutzer) – Server bleibt unverändert."
            echo
        else
            echo "FEHLER: Dispo-Prozess auf Port ${PORT} (PID ${first_pid}) antwortet nicht auf /up."
            echo "        Für einen kontrollierten Neustart: DISPO_RESTART=1 ./scripts/start-dev.sh"
            exit 1
        fi
    else
        report_foreign_process "$first_pid"
    fi
elif [[ "${DISPO_RESTART:-}" == "1" ]]; then
    echo "Hinweis: DISPO_RESTART=1 gesetzt, Port ${PORT} ist frei – starte neuen Server."
fi

DB_CONNECTION="$(grep -E '^DB_CONNECTION=' .env | cut -d= -f2- | tr -d '\r' || true)"
DB_DATABASE="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '\r' || true)"
DB_USERNAME="$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '\r' || true)"
DB_PASSWORD="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '\r' || true)"

if [[ "${DB_CONNECTION:-mysql}" == "mysql" ]]; then
    if [[ ! -x "$MYSQL_BIN" ]]; then
        echo "WARNUNG: mysql nicht gefunden ($MYSQL_BIN)."
        echo "         XAMPP starten oder MYSQL_BIN setzen."
    elif ! "$MYSQL_BIN" -u"${DB_USERNAME:-root}" ${DB_PASSWORD:+-p"$DB_PASSWORD"} -e "SELECT 1" >/dev/null 2>&1; then
        echo "FEHLER: MySQL antwortet nicht."
        echo "        Bitte XAMPP Control Panel öffnen und MySQL starten."
        exit 1
    else
        echo "MySQL OK – Datenbank ${DB_DATABASE:-dispo} prüfen …"
        "$MYSQL_BIN" -u"${DB_USERNAME:-root}" ${DB_PASSWORD:+-p"$DB_PASSWORD"} -e \
            "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE:-dispo}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
            >/dev/null 2>&1 || true
    fi
fi

echo "Migrationen …"
"$PHP_BIN" artisan migrate --force

echo "Testbenutzer …"
"$PHP_BIN" artisan db:seed --class=DevUserSeeder --force

echo "Katalog (falls noch leer) …"
"$PHP_BIN" artisan db:seed --class=E2ECalculationSeeder --force

if [[ ! -d public/build ]] || [[ -z "$(ls -A public/build 2>/dev/null)" ]]; then
    echo "Frontend-Build fehlt – npm run build …"
    npm run build
fi

if [[ "$SKIP_SERVE" == "1" ]]; then
    current_pid="$(port_listener_pids | head -n 1 || true)"

    if [[ -z "$current_pid" || "$current_pid" != "$EXISTING_PID" ]]; then
        echo "FEHLER: Server-PID hat sich unerwartet geändert (war ${EXISTING_PID}, ist ${current_pid:-keine})."
        exit 1
    fi

    if ! server_responds; then
        echo "FEHLER: Server (PID ${EXISTING_PID}) antwortet nach Setup nicht mehr auf /up."
        exit 1
    fi

    echo
    echo "Fertig. Browser: http://${HOST}:${PORT}"
    echo "Login: test@example.com / password"
    echo "Server-Neustart erzwingen: DISPO_RESTART=1 ./scripts/start-dev.sh"
    exit 0
fi

if listener_pids="$(port_listener_pids)"; then
    echo "FEHLER: Port ${PORT} ist noch belegt, obwohl kein Server gestartet werden sollte."
    printf '%s\n' "$listener_pids" | while IFS= read -r pid; do
        [[ -z "$pid" ]] && continue
        echo "        PID ${pid}: $(process_command "$pid")"
    done
    exit 1
fi

echo
echo "Starte php artisan serve auf http://${HOST}:${PORT} …"
echo "Terminal offen lassen – Abbruch mit Ctrl+C"
echo "Login: test@example.com / password  (alternativ sales@example.com)"
echo

exec "$PHP_BIN" artisan serve --host="$HOST" --port="$PORT"

#!/usr/bin/env bash
# Lokaler Entwicklungsserver für Dispo (XAMPP/MySQL).
# Nutzung: ./scripts/start-dev.sh
# Bei „Server down“ oder leerer DB einfach erneut ausführen.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PORT="${DISPO_PORT:-8000}"
HOST="${DISPO_HOST:-127.0.0.1}"
MYSQL_BIN="${MYSQL_BIN:-/Applications/XAMPP/xamppfiles/bin/mysql}"

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

server_responds() {
    curl -sf --max-time 2 "http://${HOST}:${PORT}/up" >/dev/null 2>&1
}

SKIP_SERVE=0
if lsof -ti ":${PORT}" >/dev/null 2>&1; then
    if [[ "${DISPO_RESTART:-}" != "1" ]] && server_responds; then
        echo "Dispo-Server läuft bereits auf http://${HOST}:${PORT}"
        echo "Nur Setup (Migrationen, Testbenutzer) – Server bleibt unverändert."
        echo
        SKIP_SERVE=1
    else
        echo "Beende Prozess auf Port ${PORT} …"
        lsof -ti ":${PORT}" | xargs kill -9 2>/dev/null || true
        sleep 1
    fi
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
    echo
    echo "Fertig. Browser: http://${HOST}:${PORT}"
    echo "Login: test@example.com / password"
    echo "Server-Neustart erzwingen: DISPO_RESTART=1 ./scripts/start-dev.sh"
    exit 0
fi

echo
echo "Starte php artisan serve auf http://${HOST}:${PORT} …"
echo "Terminal offen lassen – Abbruch mit Ctrl+C"
echo "Login: test@example.com / password  (alternativ sales@example.com)"
echo

exec "$PHP_BIN" artisan serve --host="$HOST" --port="$PORT"

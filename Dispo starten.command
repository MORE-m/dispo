#!/usr/bin/env bash
# Doppelklick im Finder → Terminal öffnet sich und startet den Dispo-Dev-Server.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

# PHP >= 8.3 von Homebrew; MySQL weiterhin über XAMPP (siehe MYSQL_BIN in start-dev.sh).
export PATH="/opt/homebrew/bin:/usr/local/bin:/Applications/XAMPP/xamppfiles/bin:${PATH:-/usr/bin:/bin}"

if "$ROOT/scripts/start-dev.sh"; then
    echo
    echo "Fertig – Enter drücken zum Schließen …"
    read -r
else
    echo
    echo "Fehler – Enter drücken zum Schließen …"
    read -r
fi

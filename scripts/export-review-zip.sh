#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="${1:-v6}"
COMMIT="$(git rev-parse HEAD)"
SHORT="$(git rev-parse --short HEAD)"
PARENT="$(dirname "$ROOT")"
EXPORT_BASE="$(mktemp -d /tmp/dispo-review-export.XXXXXX)"
EXPORT_DIR="$EXPORT_BASE/dispo"
ZIP_PATH="$PARENT/dispo-ux-gate-a-b-review-${VERSION}-${SHORT}.zip"

cleanup() {
    rm -rf "$EXPORT_BASE"
}

trap cleanup EXIT INT TERM

mkdir -p "$EXPORT_DIR"
git archive HEAD | tar -x -C "$EXPORT_DIR"

{
    echo "commit=$COMMIT"
    echo "branch=$(git branch --show-current)"
    echo "exported_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "version=$VERSION"
} > "$EXPORT_DIR/REVIEW-MANIFEST.txt"

(
    cd "$EXPORT_BASE"
    zip -rq "$ZIP_PATH" dispo
)

SHA256="$(shasum -a 256 "$ZIP_PATH" | awk '{print $1}')"

echo "ZIP: $ZIP_PATH"
echo "SHA256: $SHA256"
echo "COMMIT: $COMMIT"

fail=0
if ! grep -q "^commit=$COMMIT$" <(unzip -p "$ZIP_PATH" dispo/REVIEW-MANIFEST.txt); then
    echo "FAIL: Manifest-Commit stimmt nicht"
    fail=1
fi
if ! unzip -l "$ZIP_PATH" 'dispo/.env.example' >/dev/null 2>&1; then
    echo "FAIL: .env.example fehlt"
    fail=1
fi
if unzip -l "$ZIP_PATH" 'dispo/.env' >/dev/null 2>&1; then
    echo "FAIL: .env enthalten"
    fail=1
fi
if unzip -l "$ZIP_PATH" | grep -qE '\.git/'; then
    echo "FAIL: .git enthalten"
    fail=1
fi
if unzip -l "$ZIP_PATH" | grep -qE 'vendor/'; then
    echo "FAIL: vendor enthalten"
    fail=1
fi
if unzip -l "$ZIP_PATH" | grep -qE 'node_modules/'; then
    echo "FAIL: node_modules enthalten"
    fail=1
fi
if unzip -l "$ZIP_PATH" | grep -qE 'public/build/'; then
    echo "FAIL: public/build enthalten"
    fail=1
fi
if unzip -l "$ZIP_PATH" | grep -qE 'resources/js/(actions|routes|wayfinder)/'; then
    echo "FAIL: Wayfinder-Ausgaben enthalten"
    fail=1
fi
if unzip -l "$ZIP_PATH" | grep -qE 'bootstrap/cache/(packages|services)\.php'; then
    echo "FAIL: bootstrap/cache generierte Dateien enthalten"
    fail=1
fi
if unzip -l "$ZIP_PATH" | grep -qE 'database/.*\.sqlite'; then
    echo "FAIL: SQLite-Datenbank enthalten"
    fail=1
fi

if [[ "$fail" -ne 0 ]]; then
    exit 1
fi

echo "Review-Export validiert."

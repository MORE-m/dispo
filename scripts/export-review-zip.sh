#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="${1:-v5}"
COMMIT="$(git rev-parse HEAD)"
SHORT="$(git rev-parse --short HEAD)"
EXPORT_DIR="$(mktemp -d /tmp/dispo-review-export.XXXXXX)"
ZIP_NAME="dispo-phase0-review-${VERSION}-${SHORT}.zip"
ZIP_PATH="$ROOT/$ZIP_NAME"

RSYNC_EXCLUDES=(
    --exclude '.git'
    --exclude '.env'
    --exclude '.env.*'
    --exclude 'vendor'
    --exclude 'node_modules'
    --exclude 'public/build'
    --exclude 'public/hot'
    --exclude 'bootstrap/cache/*.php'
    --exclude 'storage/framework/cache'
    --exclude 'storage/framework/sessions'
    --exclude 'storage/framework/views'
    --exclude 'storage/logs'
    --exclude 'storage/inertia-devtools'
    --exclude 'storage/app'
    --exclude 'database/*.sqlite'
    --exclude 'database/*.sqlite-journal'
    --exclude 'resources/js/actions'
    --exclude 'resources/js/routes'
    --exclude 'resources/js/wayfinder'
    --exclude 'test-results'
    --exclude 'playwright-report'
    --exclude 'blob-report'
    --exclude '.phpunit.cache'
    --exclude '.phpunit.result.cache'
    --exclude 'dispo-phase0-review-*.zip'
)

rsync -a "${RSYNC_EXCLUDES[@]}" ./ "$EXPORT_DIR/dispo/"

{
    echo "commit=$COMMIT"
    echo "branch=$(git branch --show-current)"
    echo "exported_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "version=$VERSION"
} > "$EXPORT_DIR/dispo/REVIEW-MANIFEST.txt"

(
    cd "$EXPORT_DIR"
    zip -rq "$ZIP_PATH" dispo
)

rm -rf "$EXPORT_DIR"

SHA256="$(shasum -a 256 "$ZIP_PATH" | awk '{print $1}')"

echo "ZIP: $ZIP_PATH"
echo "SHA256: $SHA256"
echo "COMMIT: $COMMIT"

fail=0
if ! grep -q "^commit=$COMMIT$" <(unzip -p "$ZIP_PATH" dispo/REVIEW-MANIFEST.txt); then
    echo "FAIL: Manifest-Commit stimmt nicht"
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

if [[ "$fail" -ne 0 ]]; then
    exit 1
fi

echo "Review-Export validiert."

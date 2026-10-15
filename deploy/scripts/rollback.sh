#!/usr/bin/env bash
# Go back to an earlier version of the code.   Usage:  bash deploy/scripts/rollback.sh <commit>
# (deploy.sh prints the commit to use.)  Database changes are NOT undone: see deploy/DEPLOY-GUIDE.md.
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/topup}"
PHP="${PHP:-php}"
TARGET="${1:-}"

if [ -z "$TARGET" ]; then
    echo "Usage: bash deploy/scripts/rollback.sh <commit>"
    exit 1
fi

cd "$APP_DIR"

if ! git cat-file -e "${TARGET}^{commit}" 2>/dev/null; then
    echo "Unknown version: $TARGET"
    exit 1
fi

echo "Going back from $(git rev-parse --short HEAD) to $TARGET"

$PHP artisan down --retry=60 --refresh=15
git reset --hard "$TARGET"

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build

if [ -f tools/payway-worker/package.json ]; then
    (
        cd tools/payway-worker
        if [ -f package-lock.json ]; then
            npm ci --omit=dev --no-audit --no-fund
        else
            npm install --omit=dev --no-audit --no-fund
        fi
        npx playwright install chromium
    )
fi

$PHP artisan optimize:clear
$PHP artisan optimize
$PHP artisan queue:restart
$PHP artisan up

echo
echo "Rolled back to $(git rev-parse --short HEAD)."
echo "NOTE: database migrations that already ran were NOT undone. If the old code cannot work with the new tables,"
echo "restore the database backup taken before the update (deploy/DEPLOY-GUIDE.md, section Backups)."

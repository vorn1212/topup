#!/usr/bin/env bash
# Update the live site. Run on the server as the deploy user:
#   bash /var/www/topup/deploy/scripts/deploy.sh
# The site is put in maintenance mode, updated, checked and switched back on.
# If something fails the site STAYS in maintenance mode on purpose (a half-updated shop must not take payments).
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/topup}"
BRANCH="${BRANCH:-main}"
PHP="${PHP:-php}"

cd "$APP_DIR"

if [ ! -f .env ]; then
    echo "There is no .env in $APP_DIR. Stop."
    exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "The server copy has local changes. Remove or commit them first:"
    git status --short
    exit 1
fi

PREVIOUS="$(git rev-parse --short HEAD)"
echo "Current version: $PREVIOUS"
echo "To go back later:  bash deploy/scripts/rollback.sh $PREVIOUS"

failed() {
    echo
    echo "DEPLOY FAILED near line $1. The site was left in maintenance mode on purpose."
    echo "Fix the problem and run this script again, or go back with: bash deploy/scripts/rollback.sh $PREVIOUS"
    exit 1
}
trap 'failed $LINENO' ERR

$PHP artisan down --retry=60 --refresh=15

git fetch --prune origin
git merge --ff-only "origin/$BRANCH"

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

$PHP artisan migrate --force

if [ ! -L public/storage ]; then
    $PHP artisan storage:link
fi

$PHP artisan optimize:clear
$PHP artisan optimize

# Workers finish the job they are doing, stop, and Supervisor starts them again with the new code.
$PHP artisan queue:restart

$PHP artisan up
trap - ERR

echo
echo "Site is back online. Running the health check:"
$PHP artisan ops:check || echo "WARNING: ops:check reported a problem. Read it above, or open /admin/health."

echo "Done. New version: $(git rev-parse --short HEAD)"

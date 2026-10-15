# Phase 12 - Deployment

## What is new
- `deploy/DEPLOY-GUIDE.md`: the step-by-step guide (server, security, HTTPS, workers, mail, backups, real-money switch, go-live checklist, troubleshooting).
- `deploy/nginx/` (2 files), `deploy/php/` (2 files), `deploy/supervisor/topup.conf` (default worker + several ABA PayWay workers), `deploy/cron/topup.cron`.
- `deploy/env/.env.production.example`: every live setting, with no secrets in it.
- `deploy/scripts/`: `deploy.sh` (update), `rollback.sh`, `backup.sh`, `smoke-test.sh`.
- `tests/Feature/DeploymentFilesTest.php`: checks the files above (timing between queue and workers, no secrets in the example, Unix line endings, `.gitignore`).

No database change, no new route, no change to the application code. Nothing in `deploy/` is used on your Windows computer: it is for the live server.

## Install
1. Unzip into the project root (the `deploy` folder and the test file are new).
2. `composer dump-autoload`
3. `php artisan optimize:clear`
4. `php artisan test`
5. Follow `deploy/DEPLOY-GUIDE.md`.

## If DeploymentFilesTest fails
- "has Windows line endings": an editor saved a file in `deploy/` with CRLF. Re-extract that file from the zip, or set the editor to LF.
- ".gitignore must contain ...": add the line `.env` and the line `node_modules` to `.gitignore`.
- A timing message: keep PAYWAY_SESSION_SECONDS < worker `--timeout` < DB_QUEUE_RETRY_AFTER.

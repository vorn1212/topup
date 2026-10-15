# Deploy guide: putting the shop on a real server

Written for one Ubuntu 24.04 LTS server (a VPS) running everything: Nginx, PHP-FPM, MariaDB, the queue workers and the ABA PayWay browser workers.
Follow the parts in order. Every command can be copied as it is, except words in CAPITAL LETTERS (YOUR_DOMAIN, YOUR_SERVER_IP ...), which you replace.

Two users are used on the server:

| User | What it is for |
| --- | --- |
| `admin` | You. Logs in with an SSH key. Has `sudo`. Installs software. |
| `deploy` | Owns the shop files and runs the website, the workers and cron. Cannot log in, cannot use `sudo`. |

To work as the app user, type `sudo -iu deploy`. Type `exit` to come back. Lines marked **(as deploy)** need that.

---

## 0. What you need before you start

- A VPS with Ubuntu 24.04 LTS. A reasonable first size is 2 vCPU and 4 GB RAM (every concurrent ABA payment session adds one Chromium browser, see part 14).
- A domain name, with an **A record** pointing to the server's IP address.
- A private repository on GitHub (or similar) for the code.
- Your real values: ABA PayWay payment link, FazerCards API key (and money on the FazerCards balance), a mail service (part 12).
- All tests green on your own computer: `php artisan test`.

---

## 1. Put the code in git (on your Windows computer)

The `.env` file holds every secret. It must never go into git. Check `.gitignore` before the first commit:

```powershell
cd C:\xampp\htdocs\topup
Select-String .gitignore -Pattern '^\.env$','^node_modules'
```

You need a line `.env` and a line `node_modules` (the payment worker has its own `node_modules` folder). If `node_modules` is missing:

```powershell
Add-Content .gitignore "`nnode_modules"
```

Then (skip `git init` if the project already uses git):

```powershell
git init
git add .
git status
```

Read the `git status` list. It must NOT contain `.env`, `vendor/`, `node_modules/` or `storage/logs`. Then:

```powershell
git commit -m "Ready for deployment"
git branch -M main
git remote add origin YOUR_REPO_URL
git push -u origin main
```

`php artisan test` includes `DeploymentFilesTest`, which checks `.gitignore`, the worker timing and the line endings of the files in `deploy/`.

---

## 2. First login and safe access

From PowerShell on your computer, make an SSH key (press Enter for the questions; use a passphrase if you like):

```powershell
ssh-keygen -t ed25519
```

Log in to the new server once as root (the provider sends the password or key):

```powershell
ssh root@YOUR_SERVER_IP
```

On the server:

```bash
apt update && apt -y upgrade
adduser admin
usermod -aG sudo admin
adduser --disabled-password --gecos "" deploy
```

`adduser admin` asks for a password: choose a long one. Now copy your key to `admin`. Back in **PowerShell on your computer**:

```powershell
type $env:USERPROFILE\.ssh\id_ed25519.pub | ssh root@YOUR_SERVER_IP "mkdir -p /home/admin/.ssh && cat >> /home/admin/.ssh/authorized_keys && chown -R admin:admin /home/admin/.ssh && chmod 700 /home/admin/.ssh && chmod 600 /home/admin/.ssh/authorized_keys"
```

**Open a second PowerShell window** and check that this works before you go on (keep the first window open):

```powershell
ssh admin@YOUR_SERVER_IP
```

When it works, turn off root login and password login (in the admin window):

```bash
sudo tee /etc/ssh/sshd_config.d/99-hardening.conf >/dev/null <<'EOF'
PermitRootLogin no
PasswordAuthentication no
EOF
sudo sshd -t && sudo systemctl reload ssh
```

Firewall, brute-force protection and automatic security updates:

```bash
sudo apt -y install ufw fail2ban unattended-upgrades
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable
sudo ufw status
```

The database port (3306) stays closed: MariaDB is only used from the same server.

---

## 3. Install the software

```bash
sudo apt -y install nginx mariadb-server supervisor git unzip curl certbot composer \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd

curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt -y install nodejs
```

Check. `bcmath` is required (all money maths uses it):

```bash
php -v
php -m | grep -E 'bcmath|mbstring|pdo_mysql'
node -v
composer --version
```

`php -m` must print all three names.

---

## 4. The database

```bash
sudo mysql_secure_installation
openssl rand -base64 24
```

`mysql_secure_installation`: say yes to removing anonymous users, disallowing remote root login and removing the test database. The second command prints a random password: copy it for the next step and for `.env` later.

```bash
sudo mysql
```

Then, inside the `MariaDB [(none)]>` prompt (paste your password in place of PASTE_THE_PASSWORD):

```sql
CREATE DATABASE topup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'topup'@'localhost' IDENTIFIED BY 'PASTE_THE_PASSWORD';
GRANT ALL PRIVILEGES ON topup.* TO 'topup'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 5. Folders and the code

```bash
sudo mkdir -p /var/www/topup /var/log/topup /var/backups/topup /etc/topup /var/www/certbot
sudo chown deploy:deploy /var/www/topup /var/log/topup
sudo chown deploy:deploy /var/backups/topup && sudo chmod 700 /var/backups/topup
```

Give the `deploy` user read-only access to the repository with a **deploy key** (it can read the code but not change it):

```bash
sudo -iu deploy
ssh-keygen -t ed25519 -N "" -f ~/.ssh/topup_deploy
cat ~/.ssh/topup_deploy.pub
```

Copy the line that is printed. On GitHub: your repository, Settings, Deploy keys, Add deploy key, paste it, leave "Allow write access" OFF.

**(as deploy)** continue:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/topup_deploy
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
git clone git@github.com:OWNER/REPO.git /var/www/topup
cd /var/www/topup
```

Replace `OWNER/REPO` with yours. The first connection asks "Are you sure ... (yes/no)": type `yes`.

---

## 6. Install the packages and build the pages

**(as deploy)**, in `/var/www/topup`:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
```

The ABA PayWay worker needs Chromium and a few system libraries:

```bash
cd tools/payway-worker
npm ci --omit=dev          # if it complains there is no lock file: npm install --omit=dev
cd ../..
exit                       # back to the admin user for the next line (it needs sudo)
```

As **admin**:

```bash
cd /var/www/topup/tools/payway-worker
sudo env "PATH=$PATH" npx playwright install-deps chromium
sudo -iu deploy bash -c 'cd /var/www/topup/tools/payway-worker && npx playwright install chromium'
```

---

## 7. The `.env` file

**(as deploy)**:

```bash
cd /var/www/topup
cp deploy/env/.env.production.example .env
chmod 600 .env
php artisan key:generate --force
which node
nano .env
```

In `nano`: fill in every empty value and every `YOUR_DOMAIN`. Save with Ctrl+O, Enter, leave with Ctrl+X.

- `DB_PASSWORD`: the password from part 4.
- `PAYWAY_NODE_BINARY`: the path `which node` printed (normally `/usr/bin/node`).
- `PAYWAY_LINK_URL`: your ABA PayWay payment link.
- `MAIL_*`: part 12. You can come back to it.
- `FAZERCARDS_API_KEY`: your key. Keep `FAZERCARDS_FAKE=false`. Keep `FAZERCARDS_WEBHOOK_SECRET` empty until part 13.
- `GOOGLE_*`: only if you use Google sign-in. In the Google console add `https://YOUR_DOMAIN/auth/google/callback` as an allowed redirect.

Keep a copy of the finished `.env` (especially `APP_KEY`) in your password manager. It is the one file nothing else can recreate.

---

## 8. First start of the application

**(as deploy)**:

```bash
cd /var/www/topup
php artisan migrate --force
php artisan storage:link
chmod -R u+rwX storage bootstrap/cache
php artisan optimize
```

Create the first super admin account. The password is typed without being shown and is not saved in the shell history:

```bash
read -rsp "Choose the super admin password (14+ characters): " SUPER_PW; echo
export SUPER_PW
php artisan tinker --execute='$u = \App\Models\User::create(["name" => "Your Name", "email" => "you@example.com", "password" => getenv("SUPER_PW")]); $u->forceFill(["role" => "super_admin", "email_verified_at" => now()])->save(); echo "created user ".$u->id.PHP_EOL;'
unset SUPER_PW
```

Change `Your Name` and `you@example.com`. If the command complains that `phone` is required, add `"phone" => "YOUR_PHONE_NUMBER", ` after the email part.

Do not run `php artisan db:seed` on the live server unless you know what the seeder creates: it may add sample data.

---

## 9. Nginx and HTTPS

Nginx needs the domain written into the files. Replace `shop.example.com` below with your domain.

As **admin**:

```bash
cd /var/www/topup
sudo cp deploy/php/topup-pool.conf /etc/php/8.3/fpm/pool.d/topup.conf
sudo mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled
sudo cp deploy/php/99-topup.ini /etc/php/8.3/fpm/conf.d/99-topup.ini
sudo systemctl restart php8.3-fpm
ls /run/php/
```

`ls` must show `php8.3-fpm-topup.sock`. Now the temporary HTTP site, so Let's Encrypt can check your domain:

```bash
sudo cp deploy/nginx/topup-bootstrap.conf /etc/nginx/sites-available/topup
sudo sed -i 's/YOUR_DOMAIN/shop.example.com/g' /etc/nginx/sites-available/topup
sudo ln -s /etc/nginx/sites-available/topup /etc/nginx/sites-enabled/topup
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Open `http://shop.example.com` in a browser: the shop must appear. Then get the certificate (use your real e-mail):

```bash
sudo certbot certonly --webroot -w /var/www/certbot -d shop.example.com -m you@example.com --agree-tos --no-eff-email
```

Then the real configuration (HTTPS):

```bash
sudo cp deploy/nginx/topup.conf /etc/nginx/sites-available/topup
sudo sed -i 's/YOUR_DOMAIN/shop.example.com/g' /etc/nginx/sites-available/topup
sudo nginx -t && sudo systemctl reload nginx
```

Make Nginx pick up renewed certificates by itself, and test the renewal:

```bash
sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh >/dev/null <<'EOF'
#!/bin/sh
systemctl reload nginx
EOF
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
sudo certbot renew --dry-run
```

Check from your own computer (Git Bash or WSL; or the server itself):

```bash
bash deploy/scripts/smoke-test.sh shop.example.com
```

Every line must say `OK`.

---

## 10. Workers (Supervisor) and the scheduler (cron)

As **admin**:

```bash
cd /var/www/topup
sudo cp deploy/supervisor/topup.conf /etc/supervisor/conf.d/topup.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
sudo install -m 644 -o root -g root deploy/cron/topup.cron /etc/cron.d/topup
```

`status` must show `topup:topup-default_00` and three `topup:topup-payway_00 ... _02`, all `RUNNING`. Wait two minutes, then **(as deploy)**:

```bash
cd /var/www/topup && php artisan ops:check
```

Everything should pass (the scheduler and worker checks need those two minutes). The same report is in the browser at `/admin/health`.

---

## 11. Backups

Backups use a small file with the database login that only the `deploy` user can read. As **admin**:

```bash
sudo tee /etc/topup/backup.cnf >/dev/null <<'EOF'
[client]
user=topup
password=PASTE_THE_DB_PASSWORD
EOF
sudo chown deploy:deploy /etc/topup/backup.cnf
sudo chmod 600 /etc/topup/backup.cnf
sudo -iu deploy bash /var/www/topup/deploy/scripts/backup.sh
ls -lh /var/backups/topup
```

You must see one `db_...sql.gz` and one `files_...tar.gz`. Cron then repeats this every 6 hours and keeps 14 days.

**A backup on the same server dies with the server.** Also copy `/var/backups/topup` somewhere else every day. For example `rclone` to a cloud storage (set it up with `rclone config`, use its `crypt` option because the files hold customer and money data):

```bash
rclone copy /var/backups/topup YOUR_REMOTE:topup-backups --max-age 48h
```

**Test a restore once, before you need it** (this uses a scratch database, not the live one):

```bash
LATEST=$(ls -t /var/backups/topup/db_*.sql.gz | head -n1)
sudo mysql -e "CREATE DATABASE topup_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
sudo bash -c "gunzip -c '$LATEST' | mysql topup_restore_test"
sudo mysql topup_restore_test -e "SELECT COUNT(*) AS users FROM users; SHOW TABLES;"
sudo mysql -e "DROP DATABASE topup_restore_test"
```

You should see your users and the full list of tables.

**Real restore after a disaster:** put the site in maintenance mode (`php artisan down`), load the backup into the live database (`gunzip -c FILE | mysql topup`), run `php artisan wallet:reconcile` (it proves every wallet equals its ledger), then `php artisan up`. Deposits made after the backup was taken are not in it: check them in ABA PayWay and add them by hand.

---

## 12. E-mail (password reset and notifications)

Gmail is fine to test, but not for a live shop (daily limits, and mail goes out as a private person). Use a mail service that sends from your own domain (for example Brevo, Mailgun, Amazon SES, Postmark or Resend). Create an account, add your domain, and the service shows you:

1. **DNS records** to add at your domain (SPF, DKIM, and a DMARC record). Without them, mail lands in spam.
2. **SMTP settings**: host, port, username, password. Copy them into the `MAIL_*` lines of `.env`. Port 587 works with `MAIL_SCHEME=` left empty; for port 465 use `MAIL_SCHEME=smtps`.

Then **(as deploy)**:

```bash
cd /var/www/topup
php artisan optimize
php artisan tinker --execute='\Illuminate\Support\Facades\Mail::raw("Test from the shop", fn ($m) => $m->to("you@example.com")->subject("Test")); echo "sent".PHP_EOL;'
```

Check the inbox and the spam folder. Then try "Forgot your password?" on the site. When mail works, you may set `NOTIFY_BY_MAIL=true` to also e-mail customers their notifications.

After any change to `.env`, always run `php artisan optimize` and `sudo supervisorctl restart topup:*`. The site and the workers read `.env` only when they start.

---

## 13. Switching to real money

Do these in order, and stop at the first thing that does not behave:

1. **FazerCards:** the real `FAZERCARDS_API_KEY` is in `.env`, `FAZERCARDS_FAKE=false`, and the FazerCards balance has real money on it. Run `php artisan fazercards:sync` and `php artisan fazercards:check-balance`, then look at `/admin/provider`.
2. **Webhook secret:** only set `FAZERCARDS_WEBHOOK_SECRET` after the https site works and you have set the webhook up on the FazerCards side the way your Phase 8 notes describe. Until it is set the endpoint answers 404 on purpose.
3. **Small real deposit:** make a deposit of `DEPOSIT_MIN` (1.00) as an ordinary customer and pay the QR with your own ABA app. Check: the page turns to "paid", the wallet balance went up by exactly that amount, and `/admin/deposits` and `/admin/transactions` show it once.
4. **Small real order:** buy the cheapest product. Check the order completes and the FazerCards balance dropped.
5. **Failure paths:** let one QR expire without paying (the deposit must end as expired, no money added), and cancel/fail one order if you can do that safely (the wallet must be refunded once).
6. **Health:** `php artisan ops:check --strict` passes with no WARN you cannot explain.

Keep `DEPOSIT_MAX` low for the first days and raise it later.

---

## 14. How many customers can pay at the same time

The number of payment QR sessions that can run at once is the `numprocs` of `topup-payway` in `/etc/supervisor/conf.d/topup.conf` (3 to start). Everyone beyond that waits in the queue and sees "High demand: N customer(s) ahead".

Each session is a Chromium browser, so decide the number with a measurement, not a guess:

1. Make 3 deposits at once so 3 browsers run, then on the server:
   ```bash
   ps -eo rss,args | grep -i '[c]hrom' | awk '{sum += $1} END {printf "%.0f MB used by browsers\n", sum/1024}'
   free -m
   ```
   The first number is approximate (memory shared between browser processes is counted more than once), so the real use is a little lower. `free -m` shows what is really left.
2. Divide: `MB used by browsers / 3` = MB per session.
3. Workers you can afford = `(free RAM - a reserve for PHP, MariaDB and the system) / MB per session`. Keep a generous reserve (start with 1.5 GB) and watch `free -m` during real use.

To change the number: edit `numprocs=` in the file (as admin: `sudo nano /etc/supervisor/conf.d/topup.conf`; also keep `deploy/supervisor/topup.conf` in git in step), then:

```bash
sudo supervisorctl update
sudo supervisorctl status
```

For 20 at the same time you need a server with enough memory for 20 browsers, and you must test it with 20 real parallel deposits. I could not check whether ABA PayWay allows that many sessions from one server: test it, and raise the number step by step (3, 6, 10, 20).

If you change `PAYWAY_SESSION_SECONDS`, keep the rule: `PAYWAY_SESSION_SECONDS` < `--timeout` of the payway worker < `DB_QUEUE_RETRY_AFTER`. `php artisan test` checks it.

---

## 15. Updating the live site

Push your changes to git from your computer, then as **admin**:

```bash
sudo -iu deploy bash /var/www/topup/deploy/scripts/deploy.sh
```

The script: maintenance mode, download, `composer install --no-dev`, build pages, `migrate --force`, caches, restart workers, back online, `ops:check`. If a step fails the site **stays in maintenance mode** on purpose. It prints the old version number for going back:

```bash
sudo -iu deploy bash /var/www/topup/deploy/scripts/rollback.sh OLD_VERSION
```

Rollback does not undo database changes. For a risky update, run the backup first (`bash deploy/scripts/backup.sh`).

During maintenance mode callbacks to your site get a 503. Keep updates short and do them when few customers are paying.

---

## 16. Watching the shop

- `/admin/health` and `php artisan ops:check` (after every update).
- `/admin/attention`: orders that need a person. Staff are also alerted by `ops:alert`.
- Free uptime monitoring: add `https://YOUR_DOMAIN/` to any uptime monitor, with an e-mail alert.
- Logs: `/var/www/topup/storage/logs/` (one file per day), workers in `/var/log/topup/`, Nginx in `/var/log/nginx/`.
- Disk space: `df -h` (backups and logs grow).
- Security updates are installed automatically; reboot sometimes (`sudo reboot`) when `/var/run/reboot-required` exists.

---

## 17. Go-live checklist

- [ ] DNS A record points to the server, `https://YOUR_DOMAIN` opens with a valid certificate
- [ ] `deploy/scripts/smoke-test.sh YOUR_DOMAIN` all `OK`
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `FAZERCARDS_FAKE=false`
- [ ] `.env` is mode 600 and a copy is in your password manager
- [ ] Root SSH login and password login are off, firewall allows only 22, 80, 443
- [ ] `sudo supervisorctl status`: all workers RUNNING
- [ ] `php artisan ops:check --strict` passes
- [ ] Password reset e-mail arrives (not in spam)
- [ ] Small real deposit and small real order worked (part 13)
- [ ] Backup ran, a restore test worked, and a copy is stored somewhere else
- [ ] Super admin password is long and unique; staff accounts use their own passwords
- [ ] Khmer pages and payment wording reviewed by a native speaker

---

## 18. When something is wrong

| Symptom | Look here |
| --- | --- |
| 502 Bad Gateway | `ls /run/php/` must show `php8.3-fpm-topup.sock`; `sudo systemctl status php8.3-fpm`; the socket name in the Nginx file must match |
| 500 error page | `tail -n 50 /var/www/topup/storage/logs/laravel-*.log` (newest file) |
| Pictures or logo missing | `ls -l /var/www/topup/public/storage` (the link), run `php artisan storage:link` as deploy |
| Page still shows old design or old text after an update | `php artisan optimize:clear && php artisan optimize` as deploy |
| Deposit stays on "preparing your payment QR" | `sudo supervisorctl status` (payway workers RUNNING?), `tail -n 80 /var/log/topup/worker-payway.log`, `php artisan queue:failed`, Chromium libraries: `sudo env "PATH=$PATH" npx playwright install-deps chromium` in `tools/payway-worker` |
| Same payment started twice | `DB_QUEUE_RETRY_AFTER` is smaller than the job time: keep the rule in part 14 |
| 419 Page expired after login or on forms | `APP_URL` must be the https address, `SESSION_SECURE_COOKIE=true` only works over https; then `php artisan optimize` |
| Mail does not arrive | `tail` the Laravel log; check `MAIL_*`, run the test in part 12; check spam; check the DNS records of the mail service |
| Workers use old settings | `.env` changed but not restarted: `php artisan optimize` and `sudo supervisorctl restart topup:*` |
| `ops:check` says the scheduler is not running | `cat /etc/cron.d/topup`, `sudo systemctl status cron`, wait 2 minutes |
| Disk full | `df -h`, then `du -sh /var/backups/topup /var/log/topup /var/www/topup/storage/logs` |

If you put a service like Cloudflare in front of the site later, the site sees Cloudflare's address instead of the customer's. Tell me first: the proxy settings and the login rate limits then need a change.

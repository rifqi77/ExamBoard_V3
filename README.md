# Exam Dashboard

A full-featured exam administration platform built on **Laravel 12 + Inertia v3 + React 19 + Vite 7 + Tailwind 4**. Admin / teacher / student roles, capability-gated teacher features, token-based exam access, autosave drafts with localStorage backup, server-side scoring engine (Bloom's-taxonomy difficulty + partial-credit for multi-select / numeric / essay), AI-assisted question generation (Pollinations / Gemini / Claude / OpenAI), Excel import / export, per-user UI-state persistence, and per-attempt resume tokens.

## Stack

- **PHP 8.2+** · **Laravel 12** · **Inertia v3** · **MySQL 8 / MariaDB 10.5+**
- **React 19** · **Vite 7** · **Tailwind 4** · **lucide-react** · **MathLive** · **KaTeX**
- **firebase/php-jwt** for HS256 session + exam-access cookies
- **phpoffice/phpspreadsheet** for Excel import / export
- AES-256-GCM at-rest encryption for student plaintext passwords, exam-access token previews, and AI provider keys

## Deployment

### One-shot server bootstrap (recommended)

The repo includes `scripts/bootstrap-server.sh` — run it ONCE on a fresh server right after `git clone`. It will:

1. Verify PHP 8.2+, Composer, Node 20+, and the MySQL client are installed (fails with copy-pasteable install hints if missing)
2. Create the MySQL database + a least-privilege app user (prompts once for the MySQL root password — leaves blank if using Debian's socket auth)
3. Write a production-ready `.env` (with freshly generated `APP_KEY` and 32-byte `SESSION_SECRET`)
4. Install Composer + npm deps, build the front-end, run the initial migration
5. Print the remaining manual steps (web server config, cron, sudoers)

```bash
git clone https://github.com/rifqi77/ExamBoard_V3.git /var/www/exam-board
cd /var/www/exam-board
chmod +x scripts/bootstrap-server.sh
./scripts/bootstrap-server.sh
```

The script is **idempotent** — safe to re-run. If `.env` already exists it won't overwrite your live secrets; if the database already exists it skips the `CREATE`. The remaining steps below are what to do manually if you want full control (or if the bootstrap can't run on your distro).

### 1. Server prerequisites

- PHP 8.2 with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `zip`, `gd`
- Composer 2
- Node 20+ (only needed for the build step)
- MySQL 8 or MariaDB 10.5+

### 2. Clone + install

```bash
git clone https://github.com/rifqi77/ExamBoard_V3.git
cd ExamBoard_V3
composer install --no-dev --optimize-autoloader
npm ci
```

### 3. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set:

- `APP_URL` → your real https URL
- `DB_*` → your MySQL connection (DB_PASSWORD especially)
- `SESSION_SECRET` → **regenerate** with `openssl rand -hex 32`; this is the JWT signing key AND the root of every at-rest encryption key. Back it up — rotating it invalidates all sessions AND every encrypted column.
- AI provider keys (optional — empty = keyless Pollinations fallback)

### 4. Database

```bash
php artisan migrate --force
php artisan db:seed --class=DemoSeeder   # optional: seeds 1 admin + 1 teacher + 1 student + DEMO exam
```

### 5. Build assets

```bash
npm run build
```

### 6. Web server

Point your virtual host at `public/`.

**Nginx**:
```nginx
server {
    listen 443 ssl http2;
    server_name exam.example.com;
    root /var/www/ExamBoard_V3/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

**Caddy** (auto-TLS):
```
exam.example.com {
    root * /var/www/ExamBoard_V3/public
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}
```

### 7. Scheduler — REQUIRED (answer-durability safety net)

The app uses Laravel's scheduler to sweep abandoned exam attempts (students whose time runs out without ever clicking Submit). Add to crontab:

```cron
* * * * * cd /var/www/ExamBoard_V3 && php artisan schedule:run >> /dev/null 2>&1
```

This runs `exams:finalize-expired` every minute, scoring + finalizing any draft session past its duration so no answers ever get stranded.

### 8. Permissions

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 9. (Optional) cache for speed

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run after every deploy / `.env` change.

## Continuous deployment (GitHub Actions)

A workflow at `.github/workflows/deploy.yml` builds Composer + Vite assets in the GitHub runner and rsyncs the artefact to your friend's server on every push to `main` (and on-demand from the Actions tab). After the upload it SSHes in to run migrations, rebuild Laravel caches, and reload PHP-FPM.

### One-time setup

1. **On the server**, create a deploy user and add a fresh SSH public key to its `~/.ssh/authorized_keys`. Generate the key pair on your local machine:
   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/exam_board_deploy -C "exam-board-deploy"
   ssh-copy-id -i ~/.ssh/exam_board_deploy.pub deploy@your-server.example.com
   ```

2. **In the GitHub repo**, go to **Settings → Secrets and variables → Actions → New repository secret** and add:

   | Secret | Value | Required? |
   |---|---|---|
   | `DEPLOY_HOST` | Server hostname or IP (e.g. `exam.friend.dev`) | ✅ |
   | `DEPLOY_USER` | SSH user (e.g. `deploy`) | ✅ |
   | `DEPLOY_PATH` | Absolute path to the app on the server (e.g. `/var/www/exam-board`) | ✅ |
   | `DEPLOY_SSH_KEY` | Contents of `~/.ssh/exam_board_deploy` (the **private** key, including header/footer) | ✅ |
   | `DEPLOY_PORT` | SSH port if not 22 | optional |
   | `DEPLOY_FPM_SERVICE` | PHP-FPM systemd unit name (default `php8.2-fpm`). Leave empty to skip the reload | optional |

3. **On the server**, make sure the deploy user can reload PHP-FPM without a password prompt — add a sudoers drop-in (`/etc/sudoers.d/deploy`):
   ```
   deploy ALL=(root) NOPASSWD: /bin/systemctl reload php8.2-fpm
   ```
   (Adjust the service name if your server runs a different PHP version.) If you don't want to give the deploy user any sudo rights, leave `DEPLOY_FPM_SERVICE` blank — the workflow will skip the reload step and the next request will still pick up the new code (opcache invalidation happens on file mtime change).

4. **Server prerequisites** (one-time, before the first deploy):
   - `.env` exists in the deploy path with the production values filled in (the workflow does **not** push `.env` — your server's secrets stay on the server)
   - The deploy user owns the deploy path
   - `storage/` and `bootstrap/cache/` are writable by both the deploy user and `www-data` (use `setfacl` or a shared group)
   - The cron entry for `php artisan schedule:run` is installed (see step 7 above)
   - Initial migration has been run manually (`php artisan migrate --force` as the deploy user) so the workflow's incremental migration runs cleanly

### What every push does

1. Checkout, install Composer (production) and npm dependencies (cached)
2. Run `npm run build` to produce `public/build/`
3. rsync the tree to the server, **excluding** `.env`, `.git`, runtime cache/sessions/logs, and tests
4. **Pre-migrate snapshot**: `mysqldump | gzip` of the live DB into `backups/pre-deploy-<timestamp>.sql.gz` on the server (kept 14 days, retention pruned automatically). If the dump fails, the deploy is aborted before any migration runs — no migration without a fallback.
5. `php artisan down` → `migrate --force` → `config:cache` → `route:cache` → `view:cache` → optional FPM reload → `php artisan up`

The deploy is wrapped in `php artisan down` / `php artisan up` so requests get a 503 maintenance page during the swap (~5 seconds for migrations + caches on a typical server). If migrations fail mid-deploy, the site **stays in maintenance mode** so it doesn't half-serve — you investigate, restore from the snapshot, then `php artisan up` manually.

### Restoring from a pre-deploy snapshot

If a migration goes wrong, recover in seconds:

```bash
cd /var/www/exam-board
ls -lht backups/pre-deploy-*.sql.gz | head -5   # find the most recent
# Restore (DESTRUCTIVE — overwrites the live DB):
zcat backups/pre-deploy-<timestamp>.sql.gz | mysql -u <db_user> -p <db_name>
# Roll the schema back to that point:
php artisan migrate:rollback --force      # repeat if multiple migrations were applied
php artisan up
```

### Nightly off-host backup (recommended for production)

`scripts/db-backup.sh` is a standalone backup script (no dependencies, reads creds from `.env`) that takes a daily snapshot and optionally ships it to a second machine via rsync or to an S3 bucket. Pre-deploy snapshots live on the same disk; off-host nightly backups protect against server loss.

**Install on the server:**

```bash
cd /var/www/exam-board
chmod +x scripts/db-backup.sh
sudo mkdir -p /var/backups/exam-board
sudo chown deploy:deploy /var/backups/exam-board

# Pick an off-host target — set ONE of these in /etc/cron.d/exam-board-backup:
#   REMOTE_RSYNC_TARGET="backup@nas.lan:/srv/backups/exam-board/"
#   REMOTE_S3_BUCKET="s3://my-exam-backups/exam-board/"

# Cron entry — runs every night at 03:15 local:
sudo tee /etc/cron.d/exam-board-backup > /dev/null <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Set ONE off-host target:
# REMOTE_RSYNC_TARGET=backup@nas.lan:/srv/backups/exam-board/
# REMOTE_S3_BUCKET=s3://my-exam-backups/exam-board/

15 3 * * * deploy /var/www/exam-board/scripts/db-backup.sh >> /var/log/exam-board-backup.log 2>&1
CRON
```

The script:

- Streams `mysqldump | gzip` (no plaintext dump on disk)
- Verifies the output is non-empty AND has the expected SQL header before declaring success
- Logs to syslog as `tag=exam-db-backup`, so you can `journalctl --grep=exam-db-backup`
- Prunes local copies older than 14 days
- Off-host copy happens **after** the local dump is verified — a half-broken backup never reaches your second site
- Idempotent — safe to re-run, safe to skip days, safe to run from cron AND manually

**To smoke-test it**, run once manually as the deploy user:

```bash
/var/www/exam-board/scripts/db-backup.sh
# Should print: "Backup complete: /var/backups/exam-board/<host>-<db>-<date>.sql.gz"
ls -lh /var/backups/exam-board/
```

### Manual deploy from the Actions tab

The workflow has `workflow_dispatch:` so you can hit **Run workflow** from the Actions UI at any time without pushing a new commit — useful for rolling forward after a hotfix on the server, or after you've added/rotated secrets.

## Local development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed --seeder=DemoSeeder
npm run dev               # vite dev server
php artisan serve         # in another terminal
php artisan schedule:work # in another terminal (for the auto-finalize sweep)
```

Open http://127.0.0.1:8000.

### Seeded demo accounts

| Role | Username | Password |
|---|---|---|
| Admin | `RIFQI` | `TestPass2026` |
| Teacher | `teacher1` | `Teacher123` |
| Student | `student1` | `Student123` |

Demo exam code: `DEMO` · access token: `DEMO-2026`.

**Change the admin password immediately on first login of any production deploy.**

## Key features

- **Roles & capabilities**: admin grants per-teacher capabilities (AI generation, curriculum management, per-difficulty / per-type / per-media controls, SEB, …)
- **Bloom's taxonomy difficulty**: 6 cognitive levels (remember / understand / apply / analyze / evaluate / create) + olympiad
- **Question bank** with 5-level tree (subject → topic → subtopic → difficulty → media), Excel / zip import, 6-filter picker, inline edit
- **Curriculum / Learning Objectives** Excel import, four curricula (Merdeka, AS/A Level, IB, Olympiad), per-user scoped catalogs
- **AI-assisted exam generation** with 2-level LO picker, caps-gated parameters, mixed-topic essays, prompt download or auto-generate
- **Exam authoring** with composition targets, scheduling, SEB integration, token issuance, inline question editing, bank picker, auto-fill
- **Token-based exam access** with deadlock-safe redemption, single-attempt enforcement (strict mode), per-attempt resume tokens
- **Answer durability** (defense in depth):
  - Every keystroke → localStorage (synchronous, before any network)
  - Periodic autosave (5s) + `pagehide` / `visibilitychange:hidden` keepalive flush
  - Submit retries 4× with exponential backoff
  - Server-side scheduled sweep finalizes abandoned drafts (`exams:finalize-expired`)
  - localStorage only cleared after a confirmed submission
  - 24h prune of stale local drafts
  - Per-attempt resume tokens (survive 8h exam-access cookie expiry)
- **Scoring engine** with partial credit for multi-select ((correct − wrong) / total) and numeric (tolerance bands 1% / 5% / 10% → 0.8 / 0.5 / 0.2)
- **Per-user UI state persistence** — every filter, tab, tree-expansion state, and form parameter survives navigation, sign-out, and sign-in
- **Live class monitor** for teachers + on-demand scoring
- **Reports** Excel export, **Analyze** dashboard (9 sections + item analysis)
- **Bulk roster import** (Excel / paste), bulk password reset, per-student credentials panel with CSV export
- **Anti-cheat** event tracking (tab blur, fullscreen exit, paste/copy blocked, SEB missing)
- **Math rendering** via KaTeX (`$...$` and `$$...$$` in markdown) and MathLive editor for essay sub-parts
- **Load-tested** at 2000 concurrent students + 20 teachers with zero data loss (see `loadtest/`)

## Production hardening checklist

- [ ] HTTPS enforced (Caddy / Let's Encrypt / Cloudflare)
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `SESSION_SECRET` regenerated with `openssl rand -hex 32` and backed up securely
- [ ] Database backups scheduled (e.g. nightly `mysqldump` to off-site storage)
- [ ] Scheduler cron entry installed (otherwise abandoned exams never finalize)
- [ ] Admin password changed from the seeded default
- [ ] Seeded teacher / student accounts deleted or password-rotated
- [ ] `php artisan config:cache && php artisan route:cache` after every deploy
- [ ] PHP-FPM `opcache.enable=1` for production throughput
- [ ] Optional: Redis for session + cache (`SESSION_DRIVER=redis`, `CACHE_STORE=redis`) when running multiple workers

## License

Proprietary — all rights reserved.

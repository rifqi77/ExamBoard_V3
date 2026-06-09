# For the server admin (your friend) — 5-minute setup

This is the only document you need. Follow it once on a fresh Ubuntu / Debian server.

## You need

- An Ubuntu 22.04+ or Debian 12+ server you can SSH into (any VPS, a home server, friend's machine — anything Linux)
- `sudo` access on that server

## The whole setup

Run these **three commands** on your server. They take ~5 minutes total.

```bash
# 1. Get the code
git clone https://github.com/rifqi77/ExamBoard_V3.git /var/www/exam-board
cd /var/www/exam-board

# 2. Run the bootstrap — it'll prompt for an APP_URL and your MySQL root password (blank if fresh install)
chmod +x scripts/bootstrap-server.sh
./scripts/bootstrap-server.sh
```

That's it. The script will:

- ✅ Install PHP 8.2, MySQL, Node 20, nginx, certbot, rsync (whichever are missing)
- ✅ Create the database + a dedicated app user with a strong random password
- ✅ Write a production `.env` with freshly generated secrets (`APP_KEY`, `SESSION_SECRET`)
- ✅ Run `composer install`, `npm ci`, `npm run build`, and the initial migration
- ✅ Configure nginx with a working virtual host (HTTP)
- ✅ Install the scheduler cron (required — auto-finalizes abandoned exam attempts)
- ✅ Set up the backup directory at `/var/backups/exam-board`
- ✅ Generate an SSH deploy key for GitHub Actions
- ✅ Install a sudoers rule so the deploy user can reload PHP-FPM
- ✅ Print exactly the 6 values to paste into the developer's GitHub repo secrets

## After the script finishes

Two more things, both optional but recommended:

### Enable HTTPS (Let's Encrypt, free, ~30 seconds)

```bash
sudo certbot --nginx -d exam.your-domain.com
```

(Skip this if you're testing over plain HTTP or behind Cloudflare.)

### Nightly off-host database backup

```bash
sudo tee /etc/cron.d/exam-board-backup > /dev/null <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# OPTIONAL: set ONE of these to also copy to a second machine / S3:
# REMOTE_RSYNC_TARGET=backup@nas.lan:/srv/backups/exam-board/
# REMOTE_S3_BUCKET=s3://your-backup-bucket/exam-board/
15 3 * * * exam_app /var/www/exam-board/scripts/db-backup.sh >> /var/log/exam-board-backup.log 2>&1
CRON
```

Without the off-host target, you still get daily local snapshots at `/var/backups/exam-board/` (14-day retention).

## What the developer (the person who pushes code) does

The bootstrap printed 6 values for them. They paste these into the GitHub repo under **Settings → Secrets and variables → Actions**:

| Secret name | Value |
|---|---|
| `DEPLOY_HOST` | (printed) |
| `DEPLOY_USER` | (printed) |
| `DEPLOY_PATH` | (printed) |
| `DEPLOY_PORT` | (printed) |
| `DEPLOY_FPM_SERVICE` | (printed) |
| `DEPLOY_SSH_KEY` | (printed — the entire private key, including the `BEGIN`/`END` lines) |

After that, **every `git push origin main`** automatically deploys to your server. You never have to touch the server again unless something breaks.

## How to verify it's working

After the bootstrap:

```bash
# Visit the site
curl -i http://localhost
# Should return 200 with the login page

# Check the scheduler cron
sudo crontab -u exam_app -l
# Should show: * * * * * cd /var/www/exam-board && php artisan schedule:run ...

# Check the database
mysql -u exam_app -p exam_dashboard -e "SHOW TABLES;"
# Should list ~18 tables (users, exams, exam_sessions, ...)
```

## What to do if something goes wrong

- **Bootstrap script fails halfway**: it's idempotent. Fix the issue (usually a missing package or a typo in the prompted answers) and just run `./scripts/bootstrap-server.sh` again.
- **Site shows 502 Bad Gateway**: PHP-FPM isn't running. `sudo systemctl restart php8.2-fpm`
- **Site shows 404 Not Found from nginx**: nginx config wasn't reloaded. `sudo nginx -t && sudo systemctl reload nginx`
- **Migration failed during a CI deploy**: site stays in maintenance mode by design. SSH in, fix the issue, then `cd /var/www/exam-board && php artisan up`. The pre-migrate snapshot is at `/var/www/exam-board/backups/pre-deploy-<timestamp>.sql.gz` if you need to restore.

## Default seeded login

If the bootstrap was run with the demo seeder enabled:

| Role | Username | Password |
|---|---|---|
| Admin | `RIFQI` | `TestPass2026` |
| Teacher | `teacher1` | `Teacher123` |
| Student | `student1` | `Student123` |

**Change the admin password on first login** — this account has full control of the system.

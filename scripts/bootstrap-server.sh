#!/usr/bin/env bash
#
# One-shot server bootstrap for the Exam Dashboard.
#
# Run this ONCE on a fresh server, right after `git clone`.  It:
#   1. Verifies PHP 8.2+, composer, Node 20+, and the mysql client are installed
#      (fails with a copy-pasteable `apt-get` hint if anything is missing).
#   2. Creates an empty MySQL database (default `exam_dashboard`) + a least-
#      privilege user, prompting for the MySQL root password ONCE.
#   3. Writes a production-ready `.env` from `.env.example`, freshly generated
#      APP_KEY + SESSION_SECRET filled in (32 bytes of entropy each).
#   4. Installs Composer + npm dependencies, runs `npm run build`, and applies
#      the initial `php artisan migrate --force`.
#   5. Prints the remaining manual steps (web-server, cron, sudoers).
#
# Idempotent: safe to re-run.  Re-running on an already-configured server will:
#   - skip the CREATE DATABASE / CREATE USER if they already exist (`IF NOT EXISTS`)
#   - SKIP `.env` writing if `.env` already exists (won't overwrite live secrets)
#   - re-install deps + re-migrate (always safe)
#
# Usage:
#   chmod +x scripts/bootstrap-server.sh
#   ./scripts/bootstrap-server.sh
#
# Or with all defaults overridden:
#   DB_NAME=exam_prod DB_USER=exam_app APP_URL=https://exam.example.com \
#       ./scripts/bootstrap-server.sh
#
set -euo pipefail

# ----- Resolve paths --------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PATH="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$APP_PATH"

# ----- Colour helpers (safe on non-tty) -------------------------------------
if [ -t 1 ]; then
    BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; RESET=$'\033[0m'
else
    BOLD=""; GREEN=""; YELLOW=""; RED=""; RESET=""
fi
info()  { echo "${BOLD}${GREEN}→${RESET} $*"; }
warn()  { echo "${BOLD}${YELLOW}!${RESET} $*"; }
fatal() { echo "${BOLD}${RED}✗${RESET} $*" >&2; exit 1; }

# ----- Config (override via env) --------------------------------------------
DB_NAME="${DB_NAME:-exam_dashboard}"
DB_USER="${DB_USER:-exam_app}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
APP_URL="${APP_URL:-}"

echo
echo "${BOLD}========================================================${RESET}"
echo "${BOLD}  Exam Dashboard — server bootstrap${RESET}"
echo "${BOLD}========================================================${RESET}"
echo "  App path:    $APP_PATH"
echo "  DB name:     $DB_NAME"
echo "  DB user:     $DB_USER"
echo "  DB host:     $DB_HOST:$DB_PORT"
echo "  App URL:     ${APP_URL:-(will prompt later)}"
echo

# ============================================================================
# 1. Prerequisite check
# ============================================================================
info "1. Checking prerequisites…"

missing=()
need() {
    local cmd="$1"; local hint="$2"
    if ! command -v "$cmd" >/dev/null 2>&1; then
        missing+=("$cmd")
        echo "   ✗ $cmd not found — install with:  $hint"
    else
        echo "   ✓ $cmd: $($cmd --version 2>&1 | head -1)"
    fi
}
need php       "sudo apt-get install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-zip php8.2-gd php8.2-bcmath php8.2-curl"
need composer  "curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer"
need node      "curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt-get install -y nodejs"
need npm       "(comes with nodejs)"
need mysql     "sudo apt-get install -y mysql-server mysql-client"
need openssl   "(usually already installed)"

if [ ${#missing[@]} -gt 0 ]; then
    fatal "Install the tools above, then re-run this script."
fi

# Check PHP version is >= 8.2
PHP_MAJ=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MIN=$(php -r 'echo PHP_MINOR_VERSION;')
if [ "$PHP_MAJ" -lt 8 ] || { [ "$PHP_MAJ" -eq 8 ] && [ "$PHP_MIN" -lt 2 ]; }; then
    fatal "PHP $PHP_MAJ.$PHP_MIN found — need 8.2 or newer."
fi

# Check required PHP extensions
PHP_EXTS_REQUIRED=(pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo zip gd)
MISSING_EXTS=()
for ext in "${PHP_EXTS_REQUIRED[@]}"; do
    if ! php -m | grep -qi "^${ext}$"; then
        MISSING_EXTS+=("$ext")
    fi
done
if [ ${#MISSING_EXTS[@]} -gt 0 ]; then
    fatal "PHP extensions missing: ${MISSING_EXTS[*]}. Install with: sudo apt-get install -y $(printf 'php8.2-%s ' "${MISSING_EXTS[@]}")"
fi
info "   ✓ All required PHP extensions present"

# Check Node version is >= 20
NODE_MAJ=$(node -e 'console.log(process.versions.node.split(".")[0])')
if [ "$NODE_MAJ" -lt 20 ]; then
    warn "Node $NODE_MAJ found — recommend 20 or newer. Build may still work."
fi

# ============================================================================
# 2. Database setup
# ============================================================================
info "2. Setting up the database…"

# Test if the database is reachable as the would-be app user (idempotency check)
if MYSQL_PWD="${DB_PASS:-}" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -e "USE $DB_NAME;" 2>/dev/null; then
    info "   ✓ Database '$DB_NAME' + user '$DB_USER' already exist and credentials work — skipping CREATE."
    if [ -z "${DB_PASS:-}" ]; then
        # We need DB_PASS for the .env writing step but the prior run already
        # set it. Prompt for it now without re-running CREATE USER.
        read -r -s -p "   Re-enter the existing DB_PASS for '$DB_USER' (won't be displayed): " DB_PASS
        echo
    fi
else
    echo
    echo "   I'll create the database + user. You'll need MySQL ${BOLD}root${RESET} access."
    echo "   (On a fresh Debian/Ubuntu install, root authenticates via the unix socket — leave the password BLANK if so.)"
    read -r -s -p "   MySQL root password (blank if using socket auth): " MYSQL_ROOT_PASS
    echo

    # Generate a strong DB password if not provided
    if [ -z "${DB_PASS:-}" ]; then
        DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"
        info "   Generated a 32-char DB_PASS for '$DB_USER' — will be written into .env."
    fi

    # Run the privileged SQL.  Use IF NOT EXISTS so re-runs are safe.
    MYSQL_ARGS=(--host="$DB_HOST" --port="$DB_PORT" --user=root)
    if [ -n "$MYSQL_ROOT_PASS" ]; then
        MYSQL_ARGS+=(--password="$MYSQL_ROOT_PASS")
    fi
    if ! mysql "${MYSQL_ARGS[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$DB_HOST';
FLUSH PRIVILEGES;
SQL
    then
        fatal "MySQL setup failed. Check the root password and that MySQL is running."
    fi

    # Verify the app user can connect now
    if ! MYSQL_PWD="$DB_PASS" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -e "USE $DB_NAME;" 2>/dev/null; then
        fatal "App user '$DB_USER' was created but cannot connect. Check MySQL bind-address / host grants."
    fi

    info "   ✓ Database '$DB_NAME' created"
    info "   ✓ User '$DB_USER'@'$DB_HOST' granted ALL on '$DB_NAME'"
fi

# ============================================================================
# 3. .env generation
# ============================================================================
info "3. Writing .env…"

if [ -f .env ]; then
    warn "   .env already exists — leaving it ALONE (refuse to overwrite live secrets)."
    warn "   If you want a fresh .env, rename it first:  mv .env .env.backup-\$(date +%s)"
else
    if [ ! -f .env.example ]; then
        fatal ".env.example not found. Did you run this from the repo root?"
    fi

    # Ask for APP_URL if not given
    if [ -z "$APP_URL" ]; then
        read -r -p "   APP_URL for this deployment (e.g. https://exam.example.com): " APP_URL
        [ -n "$APP_URL" ] || APP_URL="http://localhost"
    fi

    # Generate strong secrets
    SESSION_SECRET="$(openssl rand -hex 32)"

    cp .env.example .env

    # In-place edits.  Use a stable delimiter (|) that won't appear in any of
    # the values to keep sed happy.
    sed -i \
        -e "s|^APP_ENV=.*|APP_ENV=production|" \
        -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
        -e "s|^APP_URL=.*|APP_URL=$APP_URL|" \
        -e "s|^DB_HOST=.*|DB_HOST=$DB_HOST|" \
        -e "s|^DB_PORT=.*|DB_PORT=$DB_PORT|" \
        -e "s|^DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" \
        -e "s|^DB_USERNAME=.*|DB_USERNAME=$DB_USER|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" \
        -e "s|^SESSION_SECRET=.*|SESSION_SECRET=$SESSION_SECRET|" \
        .env

    # APP_KEY needs `artisan key:generate` after composer install (it requires
    # the laravel bootstrap to be there).  Leave it for the install step.
    info "   ✓ .env written  (chmod 600)"
    chmod 600 .env
fi

# ============================================================================
# 4. Install dependencies + build + migrate
# ============================================================================
info "4. Installing Composer dependencies (production)…"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

# APP_KEY: only fill if currently empty (don't clobber an existing key)
if grep -qE '^APP_KEY=$' .env || grep -qE '^APP_KEY=\s*$' .env; then
    info "   Generating APP_KEY…"
    php artisan key:generate --force
fi

info "5. Installing npm dependencies…"
npm ci

info "6. Building front-end assets…"
npm run build

info "7. Running the initial migration…"
php artisan migrate --force --no-interaction

info "8. Storage symlink + cache warm-up…"
php artisan storage:link 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ============================================================================
# 5. Optional: demo seed
# ============================================================================
echo
read -r -p "${BOLD}Seed the demo accounts (RIFQI/teacher1/student1 + DEMO exam)? [y/N]:${RESET} " SEED_REPLY
if [[ "$SEED_REPLY" =~ ^[Yy]$ ]]; then
    info "   Seeding DemoSeeder…"
    php artisan db:seed --class=DemoSeeder --force
    warn "   Demo accounts seeded — CHANGE the admin password before going live!"
    echo "     Admin: RIFQI / TestPass2026"
fi

# ============================================================================
# 6. Next steps banner
# ============================================================================
APP_USER="$(stat -c '%U' "$APP_PATH" 2>/dev/null || stat -f '%Su' "$APP_PATH")"
cat <<EOF

${BOLD}========================================================${RESET}
${BOLD}${GREEN}  Bootstrap complete!${RESET}
${BOLD}========================================================${RESET}

The application is installed at:  $APP_PATH
.env credentials are:             chmod 600, owner $APP_USER

${BOLD}Remaining manual steps${RESET} (one-time, ~5 min total):

${BOLD}1. Web server.${RESET} Point your nginx/Caddy virtual host at:
       $APP_PATH/public
   (See README → Deployment step 6 for nginx + Caddy snippets.)

${BOLD}2. Permissions.${RESET} Let www-data write to storage + cache:
       sudo chown -R www-data:www-data storage bootstrap/cache
       sudo chmod -R 775 storage bootstrap/cache

${BOLD}3. Scheduler cron${RESET} (REQUIRED — auto-finalizes abandoned exams):
       (sudo crontab -l 2>/dev/null; echo "* * * * * cd $APP_PATH && php artisan schedule:run >> /dev/null 2>&1") | sudo crontab -

${BOLD}4. Nightly DB backup${RESET} (recommended):
       chmod +x $APP_PATH/scripts/db-backup.sh
       sudo mkdir -p /var/backups/exam-board && sudo chown $APP_USER /var/backups/exam-board
       (Then add the cron snippet from README → "Nightly off-host backup".)

${BOLD}5. GitHub Actions deploy key${RESET} (for push-to-deploy):
       ssh-keygen -t ed25519 -f ~/.ssh/exam_board_deploy -C "exam-board-deploy" -N ""
       cat ~/.ssh/exam_board_deploy.pub >> ~/.ssh/authorized_keys
       cat ~/.ssh/exam_board_deploy            # paste the PRIVATE key into the
                                               # DEPLOY_SSH_KEY GitHub secret

${BOLD}6. Sudoers for FPM reload${RESET} (optional, lets CI deploy reload PHP-FPM):
       echo '$APP_USER ALL=(root) NOPASSWD: /bin/systemctl reload php8.2-fpm' | sudo tee /etc/sudoers.d/exam-board-deploy

After steps 1–3 you're live.  After step 5 your friend's CI deploys land
automatically on every push.

EOF

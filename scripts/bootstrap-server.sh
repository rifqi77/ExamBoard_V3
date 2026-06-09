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
# 1. Prerequisite install (OS-level packages)
# ============================================================================
info "1. Installing OS prerequisites…"

# Detect distro family. Supported: Debian / Ubuntu (apt). Other distros: print
# what's needed and let the operator install by hand.
OS_FAMILY=""
if command -v apt-get >/dev/null 2>&1; then
    OS_FAMILY="debian"
elif command -v dnf >/dev/null 2>&1; then
    OS_FAMILY="redhat"
elif command -v pacman >/dev/null 2>&1; then
    OS_FAMILY="arch"
fi

is_missing() {
    ! command -v "$1" >/dev/null 2>&1
}

NEEDED=()
is_missing php      && NEEDED+=(php)
is_missing composer && NEEDED+=(composer)
is_missing node     && NEEDED+=(node)
is_missing mysql    && NEEDED+=(mysql)
is_missing nginx    && NEEDED+=(nginx)
is_missing certbot  && NEEDED+=(certbot)
is_missing rsync    && NEEDED+=(rsync)
is_missing openssl  && NEEDED+=(openssl)
is_missing curl     && NEEDED+=(curl)

if [ ${#NEEDED[@]} -eq 0 ]; then
    info "   ✓ All OS prerequisites already installed."
else
    echo "   Missing: ${NEEDED[*]}"
    if [ "$OS_FAMILY" = "debian" ]; then
        warn "   I'll install them with apt-get — you'll be prompted for sudo password."
        sudo apt-get update -qq
        # PHP from the deadsnakes PPA on Ubuntu, or from ondrej/php repo; on
        # Debian 12+ default php is 8.2 so we just use the distro packages.
        if is_missing php; then
            sudo apt-get install -y \
                php8.2-cli php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml \
                php8.2-zip php8.2-gd php8.2-bcmath php8.2-curl php8.2-tokenizer \
                php8.2-fileinfo php8.2-ctype \
                || sudo apt-get install -y \
                php-cli php-fpm php-mysql php-mbstring php-xml \
                php-zip php-gd php-bcmath php-curl
        fi
        if is_missing composer; then
            curl -sS https://getcomposer.org/installer | sudo php -- \
                --install-dir=/usr/local/bin --filename=composer >/dev/null
        fi
        if is_missing node; then
            curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - >/dev/null
            sudo apt-get install -y nodejs
        fi
        if is_missing mysql; then
            sudo apt-get install -y mysql-server mysql-client \
                || sudo apt-get install -y mariadb-server mariadb-client
            sudo systemctl enable --now mysql 2>/dev/null \
                || sudo systemctl enable --now mariadb 2>/dev/null || true
        fi
        is_missing nginx   && sudo apt-get install -y nginx
        is_missing certbot && sudo apt-get install -y certbot python3-certbot-nginx
        is_missing rsync   && sudo apt-get install -y rsync
        is_missing openssl && sudo apt-get install -y openssl
        is_missing curl    && sudo apt-get install -y curl
    else
        echo
        echo "   ✗ Your OS isn't apt-based. Install these packages manually, then re-run:"
        echo "     php 8.2+ (with extensions: pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo zip gd)"
        echo "     composer 2"
        echo "     node 20+ (and npm)"
        echo "     mysql-server (or mariadb-server)"
        echo "     nginx"
        echo "     certbot + python3-certbot-nginx (for HTTPS)"
        echo "     rsync, openssl, curl"
        fatal "Auto-install not supported on this distro."
    fi
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
# 6. File permissions for the web server
# ============================================================================
info "9. Setting filesystem permissions…"
APP_USER="$(stat -c '%U' "$APP_PATH" 2>/dev/null || stat -f '%Su' "$APP_PATH")"
if id www-data >/dev/null 2>&1; then
    sudo chown -R "$APP_USER:www-data" "$APP_PATH/storage" "$APP_PATH/bootstrap/cache" 2>/dev/null || true
    sudo chmod -R 775 "$APP_PATH/storage" "$APP_PATH/bootstrap/cache" 2>/dev/null || true
    info "   ✓ storage/ + bootstrap/cache writable by www-data"
fi

# ============================================================================
# 7. Nginx site config
# ============================================================================
info "10. Configuring nginx…"
NGINX_HOST="$(echo "$APP_URL" | sed -E 's|https?://||' | cut -d/ -f1)"
NGINX_CONF="/etc/nginx/sites-available/exam-board"

if [ -z "$NGINX_HOST" ] || [ "$NGINX_HOST" = "localhost" ]; then
    NGINX_HOST="_"  # nginx default-server catch-all
    warn "   APP_URL had no real hostname — nginx will be configured as the default server (IP-only access)."
fi

# Pick the right PHP-FPM socket path (8.2 on Ubuntu 22.04+, 8.x on Debian, fallback).
FPM_SOCK=""
for cand in /run/php/php8.2-fpm.sock /run/php/php8.1-fpm.sock /run/php/php-fpm.sock /var/run/php-fpm/www.sock; do
    [ -S "$cand" ] && FPM_SOCK="$cand" && break
done
[ -z "$FPM_SOCK" ] && FPM_SOCK="/run/php/php8.2-fpm.sock"

sudo tee "$NGINX_CONF" >/dev/null <<NGINX
# Generated by bootstrap-server.sh — Exam Dashboard
server {
    listen 80;
    listen [::]:80;
    server_name $NGINX_HOST;
    root $APP_PATH/public;
    index index.php;

    # Lets Encrypt ACME challenge — keep above the catch-all location.
    location ^~ /.well-known/acme-challenge/ { allow all; root /var/www/html; }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_read_timeout 90s;
    }

    # Long-cache static assets built by vite (hashed filenames).
    location ~* ^/build/.*\.(js|css|woff2?|ttf|svg|png|jpg|gif|ico)\$ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(?!well-known) { deny all; }
    client_max_body_size 16M;
}
NGINX

# Activate the site
sudo ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/exam-board"
sudo rm -f "/etc/nginx/sites-enabled/default" 2>/dev/null || true
if sudo nginx -t 2>/dev/null; then
    sudo systemctl reload nginx
    info "   ✓ nginx configured + reloaded → http://$NGINX_HOST"
else
    warn "   nginx -t failed. Config is at $NGINX_CONF; fix it and reload manually."
fi

# ============================================================================
# 8. Cron — Laravel scheduler (REQUIRED for auto-finalize)
# ============================================================================
info "11. Installing Laravel scheduler cron…"
CRON_LINE="* * * * * cd $APP_PATH && php artisan schedule:run >> /dev/null 2>&1"
if sudo crontab -u "$APP_USER" -l 2>/dev/null | grep -qF "$APP_PATH"; then
    info "   ✓ Scheduler cron already present"
else
    (sudo crontab -u "$APP_USER" -l 2>/dev/null; echo "$CRON_LINE") | sudo crontab -u "$APP_USER" -
    info "   ✓ Cron installed (runs exams:finalize-expired every minute)"
fi

# ============================================================================
# 9. Backup directory + cron (optional but recommended)
# ============================================================================
info "12. Preparing backup directory…"
sudo mkdir -p /var/backups/exam-board
sudo chown "$APP_USER" /var/backups/exam-board
sudo chmod 700 /var/backups/exam-board
chmod +x "$APP_PATH/scripts/db-backup.sh" 2>/dev/null || true
info "   ✓ /var/backups/exam-board ready (run scripts/db-backup.sh nightly via cron — see README)"

# ============================================================================
# 10. SSH deploy key for GitHub Actions push-to-deploy
# ============================================================================
info "13. Generating GitHub Actions deploy SSH key…"
SSH_DIR="$HOME/.ssh"
mkdir -p "$SSH_DIR" && chmod 700 "$SSH_DIR"
DEPLOY_KEY="$SSH_DIR/exam_board_deploy"
if [ -f "$DEPLOY_KEY" ]; then
    info "   ✓ Deploy key already exists at $DEPLOY_KEY"
else
    ssh-keygen -t ed25519 -N "" -C "exam-board-deploy-$(date -u +%Y%m%d)" -f "$DEPLOY_KEY" >/dev/null
    cat "${DEPLOY_KEY}.pub" >> "$SSH_DIR/authorized_keys"
    chmod 600 "$SSH_DIR/authorized_keys"
    info "   ✓ Generated + authorized: $DEPLOY_KEY"
fi

# ============================================================================
# 11. Sudoers for FPM reload (optional)
# ============================================================================
FPM_SERVICE="$(systemctl list-units --type=service --plain --no-legend 2>/dev/null | grep -oE 'php[0-9.]+-fpm\.service' | head -1 | sed 's/\.service//' || echo 'php8.2-fpm')"
SUDOERS_FILE="/etc/sudoers.d/exam-board-deploy"
if [ ! -f "$SUDOERS_FILE" ]; then
    info "14. Granting NOPASSWD reload of $FPM_SERVICE to $APP_USER…"
    echo "$APP_USER ALL=(root) NOPASSWD: /bin/systemctl reload $FPM_SERVICE" | sudo tee "$SUDOERS_FILE" >/dev/null
    sudo chmod 0440 "$SUDOERS_FILE"
    info "   ✓ Sudoers rule installed"
fi

# ============================================================================
# 12. Done — print the GitHub secrets the operator needs to copy in
# ============================================================================
SERVER_IP="$(curl -s -m 3 https://api.ipify.org 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}' || echo 'YOUR.SERVER.IP')"
cat <<EOF

${BOLD}========================================================${RESET}
${BOLD}${GREEN}  Bootstrap complete — server is live at http://$NGINX_HOST${RESET}
${BOLD}========================================================${RESET}

${BOLD}Now paste these 6 values into the GitHub repo's secrets${RESET}
(${BOLD}Settings → Secrets and variables → Actions → New repository secret${RESET}):

  ${BOLD}DEPLOY_HOST${RESET}        $SERVER_IP
  ${BOLD}DEPLOY_USER${RESET}        $APP_USER
  ${BOLD}DEPLOY_PATH${RESET}        $APP_PATH
  ${BOLD}DEPLOY_PORT${RESET}        22                      (or your custom port)
  ${BOLD}DEPLOY_FPM_SERVICE${RESET} $FPM_SERVICE

  ${BOLD}DEPLOY_SSH_KEY${RESET}     (paste the lines below, INCLUDING headers/footers)
  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄
$(cat "$DEPLOY_KEY")
  ┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄

${BOLD}After that, every \`git push origin main\` from the developer's machine${RESET}
${BOLD}will automatically deploy to this server. Nothing else to configure.${RESET}

${BOLD}Optional next steps:${RESET}
  • HTTPS:  sudo certbot --nginx -d $NGINX_HOST   (free Let's Encrypt cert)
  • Off-host nightly backup: edit /etc/cron.d/exam-board-backup
    (see README → "Nightly off-host backup")

EOF

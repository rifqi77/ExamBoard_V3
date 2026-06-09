# Exam Dashboard

A full-featured exam administration platform built on **Laravel 12 + Inertia v3 + React 19 + Vite 7 + Tailwind 4**. Admin / teacher / student roles, capability-gated teacher features, token-based exam access, autosave drafts with localStorage backup, server-side scoring engine (Bloom's-taxonomy difficulty + partial-credit for multi-select / numeric / essay), AI-assisted question generation (Pollinations / Gemini / Claude / OpenAI), Excel import / export, per-user UI-state persistence, and per-attempt resume tokens.

## Stack

- **PHP 8.2+** · **Laravel 12** · **Inertia v3** · **MySQL 8 / MariaDB 10.5+**
- **React 19** · **Vite 7** · **Tailwind 4** · **lucide-react** · **MathLive** · **KaTeX**
- **firebase/php-jwt** for HS256 session + exam-access cookies
- **phpoffice/phpspreadsheet** for Excel import / export
- AES-256-GCM at-rest encryption for student plaintext passwords, exam-access token previews, and AI provider keys

## Deployment

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

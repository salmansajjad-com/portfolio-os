# Deployment (Hostinger shared hosting via FTP)

This app is packaged locally into two zip archives and uploaded by FTP.  
There is **no Node**, Redis, Docker, or SSH requirement on the server. PHP 8.3+ and MySQL only.

```
/home/uXXXXXX/
├── laravel_app/     ← extract app.zip here (app code + vendor, no public/)
└── public_html/     ← extract public.zip here (web root)
```

`public/index.php` in the repo is **dual-path**: it loads `../laravel_app` on Hostinger and falls back to `..` locally. Package as-is — do not hand-edit `index.php` after extract.

---

## Security warning (loaded gun)

`GET /_ops/{action}?token=…` can run **migrate**, **storage-link**, **cache-clear**, and **optimize** without SSH.

- Set a long random `OPS_TOKEN` only while you need it. **Tokens under 32 characters are refused** and the route 404s.
- Prefer the header form so the token stays out of access logs and history:
  `curl -H "Authorization: Bearer $OPS_TOKEN" https://YOUR_DOMAIN/_ops/migrate`
- Prefer **HTTPS** and a hard-to-guess token (32+ random chars).
- **Rotate / clear `OPS_TOKEN` after successful first deploy** (or at least after migrations).
- Leaving `OPS_TOKEN` empty disables the route entirely (404).
- Rate-limited per IP (`OPS_THROTTLE`, default 5/minute).

Never commit real tokens or production `.env`.

---

## 0. Pre-flight (local)

```bash
# Full suite must be green before packaging
php artisan test

# Frontend assets (Hostinger has no npm)
npm run build

# Optional manual check; packaging scripts run --no-dev themselves then restore
# composer install --no-dev --optimize-autoloader
# …package…
# composer install   # restore dev for local work
```

---

## 1. Build the zips

```bash
# macOS / Linux
chmod +x deploy/package.sh
./deploy/package.sh
# ./deploy/package.sh --help
# ./deploy/package.sh --dry-run
# ./deploy/package.sh --skip-build --skip-composer   # when already built
```

```powershell
# Windows
powershell -File deploy\package.ps1
# powershell -File deploy\package.ps1 -Help
# powershell -File deploy\package.ps1 -DryRun
```

**Output** (gitignored):

| File | Contents |
|------|----------|
| `deploy/dist/app.zip` | App root **except** `public/`, `node_modules/`, `.git/`, `tests/`, `.env`, log/cache bodies — **includes `vendor/` after `composer --no-dev`**. Storage **skeleton** dirs kept. |
| `deploy/dist/public.zip` | **Contents** of `public/` (including `build/`, `.htaccess`, dual-path `index.php`) |

Script order:

1. `npm run build` (unless `--skip-build`)
2. `composer install --no-dev --optimize-autoloader` (unless `--skip-composer`)
3. Stage + zip → `deploy/dist/`
4. `composer install` restore for local dev (unless `--no-restore-dev`)

---

## 2. Create MySQL in hPanel

1. Hostinger hPanel → **Databases** → MySQL → create database + user, grant all on that DB.
2. Note host (often `localhost` / `127.0.0.1`), db name, user, password.

---

## 3. Upload & extract (FileZilla + File Manager)

1. FTP/SFTP with FileZilla into the account home.
2. Upload `app.zip` and `public.zip` (e.g. into home temporarily).
3. In **File Manager**:
   - Create folder `laravel_app` if needed.
   - Extract `app.zip` **into** `laravel_app/` (so you see `laravel_app/artisan`, `laravel_app/vendor`, …).
   - Extract `public.zip` **into** `public_html/` (so you see `public_html/index.php`, `public_html/build/`, …).  
     If `public_html` already has Hostinger defaults, remove/replace carefully; keep mailbox folders if any.
4. Delete the zip files from the server after extract.

Layout check:

```
…/laravel_app/vendor/autoload.php
…/laravel_app/bootstrap/app.php
…/public_html/index.php   # references ../laravel_app/…
…/public_html/.htaccess
```

---

## 4. Production `.env`

1. Copy local `.env.production.example` → server `laravel_app/.env` (create via File Manager editor).
2. Set at least:

   - `APP_KEY` — generate **locally** with `php artisan key:generate --show` and paste (do not reuse a public demo key).
   - `APP_URL` — your real HTTPS URL
   - `APP_ENV=production`, `APP_DEBUG=false`
   - `DB_*` from hPanel
   - `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` (or `file` — **never redis**)
   - Mail SMTP for Hostinger
   - `OPS_TOKEN` — temporary long random value for first migrate

---

## 5. Permissions

In File Manager (or chmod if available), set **writable** for:

- `laravel_app/storage` (and subdirs) — typically **775**
- `laravel_app/bootstrap/cache` — **775**

If uploads still fail, 775 → 755 on owner-writable trees depending on account isolation; keep as restrictive as Hostinger allows while writable by PHP.

---

## 6. Migrations & storage (ops route, no SSH)

After DNS/`APP_URL` points at this site:

```text
https://YOUR_DOMAIN/_ops/migrate?token=YOUR_OPS_TOKEN
https://YOUR_DOMAIN/_ops/storage-link?token=YOUR_OPS_TOKEN
https://YOUR_DOMAIN/_ops/optimize?token=YOUR_OPS_TOKEN
```

| Action | What it runs |
|--------|----------------|
| `migrate` | `php artisan migrate --force` |
| `storage-link` | `storage:link`; if symlink blocked, **copies** `storage/app/public` → `public/storage` |
| `cache-clear` | config/route/view/cache clear |
| `optimize` | `php artisan optimize` |

Plain-text Artisan output. Wrong token → 403. Empty token config → 404 for all ops URLs.

**Never re-run a migration that already applied** on production for old files you edited — ship a **new** migration instead. `migrate` only applies pending files.

After success: set `OPS_TOKEN=` empty (or rotate), upload updated `.env` or edit on server, then optionally `/_ops/cache-clear` once more if config was cached with the old token.

---

## 7. Cron (cPanel / hPanel)

Two jobs (paths are examples — use full path to `php` and `laravel_app`):

**A. Scheduler (every minute)**

```bash
* * * * * cd /home/uXXXXXX/laravel_app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

**B. Queue worker drip (every 5 minutes)** — database queue, no daemon:

```bash
*/5 * * * * cd /home/uXXXXXX/laravel_app && /usr/bin/php artisan queue:work --stop-when-empty --max-time=280 >> /dev/null 2>&1
```

Hostinger may require selecting the PHP version in the cron UI. Prefer CLI PHP 8.3+.

Scheduled app commands (via `schedule:run`): credential expiry alerts, recurring tasks, recurring expenses.

---

## 8. Verify (click-through)

1. Open `APP_URL` → login page loads (styled assets from `/build`).
2. Sign in with seed/admin user if you ran seeders (prefer creating admin offline; production seed is optional).
3. **Credential expiry** — create/near-expiry credential or wait for schedule smoke.
4. **File upload** — project/task attachment succeeds; files land under `laravel_app/storage/`.
5. **Public media** — if `public/storage` missing, either re-run `storage-link` ops, or use fallback URL `/media/public/{path}` for files under `storage/app/public`.
6. **Queued email** — trigger a mailable that uses the queue; wait for cron queue worker; check inbox / mail logs.
7. Hit `/_ops/…` with wrong token → forbidden; with empty token after disable → 404.

---

## Updates after first deploy (short path)

1. Locally: pull, `npm run build`, `php artisan test`, `./deploy/package.sh`.
2. FTP new zips; extract over existing trees (or extract to temp and replace).
3. **Do not overwrite** `laravel_app/.env`.
4. If **`composer.json` / `composer.lock` changed** — re-upload `vendor/` via new `app.zip` (package always includes production vendor).
5. If only PHP/Blade/Livewire/app code changed and no Composer deps — still use `app.zip` or selective overwrite; public assets only need `public.zip` when Vite build changed.
6. Run `/_ops/migrate?token=…` only if there are **new** migrations.
7. `/_ops/cache-clear` and/or `optimize` as needed.
8. Rotate ops token when finished.

**Never edit a migration that already ran on production.**

Rebuilt frontend only → re-upload `public.zip` (or `public/build` + manifest).

---

## storage:link on shared hosting

`symlink()` is often disabled. The ops `storage-link` action:

1. Tries `php artisan storage:link`
2. Falls back to **copying** `storage/app/public` → `public_html/storage` (via `public_path()`)

Copy mode is not live-synced: re-run after new public-disk uploads, or serve via:

```text
/media/public/{relative-path-under-storage-app-public}
```

Private media (default `local` disk under `storage/app/private`) is not web-public by design.

---

## Optional: database backup

```bash
php artisan db:backup
# → storage/app/backups/db-YYYYMMDD-HHMMSS.sql
```

- SQLite: file copy into that path  
- MySQL: pure-PHP SQL export (no `mysqldump` / shell)  

Download via FTP from `laravel_app/storage/app/backups/`. Not exposed as a public web route.

---

## Checklist summary

- [ ] MySQL created  
- [ ] `app.zip` → `laravel_app/`  
- [ ] `public.zip` → `public_html/`  
- [ ] `.env` from `.env.production.example`  
- [ ] storage + bootstrap/cache writable  
- [ ] ops `migrate` + `storage-link` + `optimize`  
- [ ] two cron jobs  
- [ ] login / upload / queue smoke  
- [ ] rotate/clear `OPS_TOKEN`  

---

## Local development unchanged

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Dual-path `index.php` still uses `../vendor` and `../bootstrap` when `laravel_app` is absent.

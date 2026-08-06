# Installation

Local setup, configuration, and first-run notes. For production deployment see [DEPLOYMENT.md](../DEPLOYMENT.md).

## Requirements

| | |
|---|---|
| PHP | 8.3 or newer, with the standard Laravel extensions |
| Composer | 2.x |
| Database | SQLite for local work, MySQL 8 for production |
| Node.js | 20.19+ or 22+ — **only to build CSS/JS.** The server never needs it. |

## Install

```bash
git clone https://github.com/tnandla/portfolio-os.git
cd portfolio-os

composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite    # SQLite is the default in .env.example
php artisan migrate --seed        # schema, roles, permissions, demo portfolio

npm install && npm run build
php artisan serve
```

Open <http://127.0.0.1:8000>.

To use MySQL instead, fill in the `DB_*` block in `.env` and skip the `touch`.

> The test suite renders Blade views, which need the compiled asset manifest. Run `npm run build` before `php artisan test` on a fresh clone.

## Signing in

Seeding creates one account per role, so you can see how much the app changes shape depending on who is signed in.

| Role | Email | Password |
|---|---|---|
| Admin | `admin@example.com` | `password` |
| Partner | `partner@example.com` | `password` |
| Supervisor | `supervisor@example.com` | `password` |
| Staff | `staff@example.com` | `password` |
| Accountant | `accountant@example.com` | `password` |

> [!WARNING]
> `password` is only used when `APP_ENV` is `local` or `testing`. Anywhere else the seeder **generates a random password and prints it once** — copy it from the console, it is not recoverable. Set `ADMIN_PASSWORD` in `.env` to choose your own.

## Demo data

The fake projects, credentials, work, people and money are part of `migrate --seed`, but only when `APP_ENV` is `local` or `testing`.

Anywhere else you get roles, permissions, settings, task templates and one admin account — what a real installation wants. `SEED_DEMO_DATA=true|false` overrides the decision either way.

Demo records use `alpha-demo.test` / `beta-demo.test` domains and `example.com` addresses throughout.

## Configuration

Nothing is hardcoded to one country or currency.

| Setting | Where | Notes |
|---|---|---|
| Organisation name | Settings screen | Sidebar and page titles |
| Base currency, symbol | Settings screen, or `MONEY_BASE_*` | The saved setting wins over the env default |
| Minor-unit exponent | `MONEY_BASE_EXPONENT` | `2` for cents, `0` for JPY/KRW, `3` for KWD/BHD. **Choose before entering data** — see below |
| Second input currency | `MONEY_SOURCE_*` | For revenue received in another currency. Set equal to the base to run single-currency |
| Display timezone | Settings screen, or `APP_DISPLAY_TIMEZONE` | Timestamps are always *stored* in UTC. This is display only, and it defines "today" for attendance |
| Late arrival hour | Settings screen | When a first login counts as late |
| Credential alert windows | Settings screen | Defaults to 30 / 14 / 7 days before expiry |

> [!CAUTION]
> Changing `MONEY_BASE_EXPONENT` after you have entered data reinterprets every stored amount. Stored integers carry no scale, and there is no migration for it. Pick the exponent up front.

## Optional: the AI assistant

Leave `AI_API_KEY` empty and AI features do not exist — no nav entry, no routes that resolve, no code path that calls out.

To enable it:

```env
AI_PROVIDER=openai          # or anthropic
AI_API_KEY=sk-...
AI_MODEL=                   # optional override
AI_MONTHLY_BUDGET_CENTS=2000
AI_CACHE_TTL=3600
```

## Tests

```bash
php artisan test              # full suite, SQLite in memory
php artisan test --filter=Money
vendor/bin/pint               # format
vendor/bin/pint --test        # check without writing
```

## Troubleshooting

**`Vite manifest not found`** — run `npm run build`. Compiled assets are gitignored, so a fresh clone has none.

**Blank page or 500 after changing `.env`** — `php artisan config:clear`.

**Vault rows unreadable** — `APP_KEY` changed. The key *is* the lock; restore the original key or the encrypted values are gone.

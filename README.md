# Portfolio OS

**Run a portfolio of websites without the spreadsheet.** Sites, passwords, content work, the people doing it, and the money — in one place, on hosting that costs a few dollars a month.

[![CI](https://github.com/tnandla/portfolio-os/actions/workflows/ci.yml/badge.svg)](https://github.com/tnandla/portfolio-os/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg)](composer.json)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-ff2d20.svg)](composer.json)
[![Livewire 4](https://img.shields.io/badge/Livewire-4-fb70a9.svg)](composer.json)

![Dashboard](docs/screenshots/dashboard.png)

## The problem

Past a handful of sites, the work scatters: a spreadsheet for revenue, a shared password doc, a task board nobody updates, and a monthly argument about who is owed what.

Portfolio OS puts the disputed parts — approvals, ownership percentages, profit splits, who saw which password — in writing, and audits them.

## What you get

**Never lose a domain again.** Every login lives in an encrypted vault: registrar, hosting, DNS, SSL, CMS, analytics, ad networks. Masked on screen, admin-only, and every reveal is logged with who and when. Anything with an expiry date warns you 30, 14 and 7 days out.

**Approved work bills itself.** Approve an article and the writer's fee posts as an expense. Approve a link, same. No month-end reconciliation between who did the work and who you owe.

**Payouts that can't drift.** Ownership is per site and must total 100%. Pick a month, the system computes each partner's share and locks the run — freezing the percentages onto it. Change someone's equity next quarter and last quarter's payout stays exactly as paid. Corrections are new entries, never edits.

**Old months stay true.** Revenue in another currency stores the amount, the rate used, and the converted total on the row. A rate change today never rewrites what you earned in March.

**No duplicate work.** Each project keeps its keyword list and blocks a second writer from claiming one. Links warn when a source domain repeats.

**Attendance without spyware.** The first login of the day is the check-in. No screenshots, no keystroke logging. Monthly scorecards build from work that already happened.

**One dashboard, whoever you are.** A partner who is also an admin doesn't switch modes. Roles stack, and permissions are the union. `⌘K` jumps anywhere.

## Screens

|  |  |
|---|---|
| ![Projects](docs/screenshots/projects.png) <br> **Portfolio** — status, monetisation and open work at a glance. | ![Project detail](docs/screenshots/project-detail.png) <br> **Project** — ownership split, team, credential vault. |
| ![Tasks](docs/screenshots/tasks.png) <br> **Tasks** — checklists, recurring work, evidence on submit. | ![Approvals](docs/screenshots/approvals.png) <br> **Approvals** — one keyboard queue for tasks, drafts and links. |
| ![Profit and loss](docs/screenshots/pnl.png) <br> **P&L** — revenue, direct cost, shared cost, net. | ![Distributions](docs/screenshots/distributions.png) <br> **Distributions** — profit splits, locked once approved. |
| ![Revenue](docs/screenshots/revenues.png) <br> **Revenue** — monthly or CSV import, FX frozen per row. | ![Scorecard](docs/screenshots/scorecard.png) <br> **Scorecards** — output per person, priced against pay rates. |
| ![Command palette](docs/screenshots/command-palette.png) <br> **⌘K** — jump or create from anywhere. | ![Dark mode](docs/screenshots/dashboard-dark.png) <br> **Dark mode** — full second token set, no flash. |

<details>
<summary>More screens</summary>

| | |
|---|---|
| ![Articles](docs/screenshots/articles.png) | ![Attendance](docs/screenshots/attendance.png) |
| ![Settings](docs/screenshots/settings.png) | ![Login](docs/screenshots/login.png) |

Full navigation on a phone, not a cut-down version:

<img src="docs/screenshots/mobile-dashboard.png" alt="Mobile dashboard" width="320">

</details>

## Features

**Projects & credentials** — Sites with status, CMS, niche, acquisition cost. Encrypted vault with audited reveals and scheduled expiry alerts. Ownership shares totalling 100%, plus team assignment that scopes what staff can see.

**Work** — Tasks with checklists, due dates, attachments and comments. Recurring schedules and reusable templates (an SEO checklist applied to every new site). Articles from brief to published. Link log with monthly budgets. One approval queue across all three, keyboard-driven (`j`/`k` to move, `a` approve, `r` reject).

**People** — Login-based attendance with leave and holidays. Login history. Daily work logs. Monthly scorecards. Mixed pay: salary and/or per article, link or task.

**Money** — Revenue per project per month, typed or imported. Direct and shared expenses, with shared costs allocated by revenue share. P&L per project and portfolio-wide. Partner distributions, ledger and statements. Every amount is an integer in minor units, so rounding never quietly loses someone's share.

**Platform** — Many-to-many roles across 49 granular permissions, no role switcher. Project scoping applied in queries, not views. Audit log for money and credential reveals. A ~28-component design system with dark mode, command palette and a real mobile layout. [Optional AI assistant](#optional-ai-assistant), hidden entirely without a key.

## Install

Full guide: **[docs/INSTALL.md](docs/INSTALL.md)** — configuration, demo accounts, currency and timezone setup, troubleshooting.

```bash
git clone https://github.com/tnandla/portfolio-os.git
cd portfolio-os

composer install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate --seed

npm install && npm run build
php artisan serve
```

Sign in at <http://127.0.0.1:8000> as `admin@example.com` / `password`. Seeding creates one account per role so you can see how the app changes shape for each.

Needs PHP 8.3+, Composer, and Node 20.19+/22+ to build assets. **The server never needs Node.**

```bash
php artisan test      # 75 tests
vendor/bin/pint       # format
```

## Deploy

Full guide: **[DEPLOYMENT.md](DEPLOYMENT.md)**.

```bash
./deploy/package.sh   # → deploy/dist/app.zip + public.zip
```

Upload `app.zip` outside the web root, `public.zip` into it, write a production `.env`, and run migrations through the token-gated `/_ops/migrate` route when the host gives you no SSH. Two cron entries cover the scheduler and a `queue:work --stop-when-empty` drip.

### Why shared hosting shaped this

Deliberate constraints, not missing features:

- Sessions, cache and queues run on the database or filesystem. **Redis is never assumed.**
- **Every queued job is safe run late, out of order, or twice** — a cron drip is not a daemon and will do all three.
- No reliance on `exec()`, `proc_open()` or `symlink()`. `storage:link` falls back to copying; backups are a pure-PHP SQL export.
- Assets build locally and upload. Fonts are self-hosted, so there is no CDN at runtime.
- `public/index.php` is dual-path: it finds the app outside the web root on shared hosting, and one directory up locally.

Deploy to a normal VPS and none of it hurts — you just have headroom you aren't using.

## Security

- **Vault secrets are encrypted at rest** with `APP_KEY`. The key *is* the lock: rotate it and existing rows become unreadable, so back them up first.
- **Audit logging** covers money mutations and every credential reveal.
- **The `/_ops/{action}` route is high-risk by design** — it runs migrations over HTTP for hosts with no SSH. It 404s entirely when `OPS_TOKEN` is empty, is rate-limited, and should be switched off the moment a deploy finishes.
- **The AI never sees secrets.** Credentials and bank details are stripped in code before any prompt is built, not by convention.
- Financial records are never hard-deleted, and approved distribution runs are immutable.

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## Optional AI assistant

Leave `AI_API_KEY` empty and it does not exist. The app is complete without it.

With a key you get an ask-your-data box and drafted monthly summaries, under two rules enforced in code:

1. **An LLM never writes SQL that runs.** Questions map onto a fixed whitelist of read-only reports, each applying the caller's own permissions.
2. **Credentials never leave the building.** The payload is sanitised before the prompt is built.

Includes a monthly spend cap, token logging and a response cache.

## Stack

PHP 8.3+ · Laravel 13 · Livewire 4 · Alpine · Tailwind 4 · MySQL or SQLite · Vite (local only) · Pest · Pint.

No React, no Vue, no Inertia, no build step on the server.

## Known gaps

- **Set `MONEY_BASE_EXPONENT` before entering data.** Changing it later reinterprets every stored amount; there is no migration for that.
- Column names keep `_paisa` / `_pkr_paisa` suffixes. They mean "minor units of the base currency" whatever you configure — renaming would break existing installs for no gain.
- Multi-currency is one base plus one optional input currency, not per-project currencies.
- Two-factor authentication is **schema and settings only** — there is no challenge screen yet.
- No inline editing (modals and side forms instead), and no charts.
- Wide financial tables scroll sideways on phones rather than reflowing.

## Docs

[Install](docs/INSTALL.md) · [Deployment](DEPLOYMENT.md) · [User guide](docs/USER_GUIDE.md) · [Design system](docs/DESIGN_AUDIT.md) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md) · [Changelog](CHANGELOG.md)

## Licence

MIT — see [LICENSE](LICENSE). Bundled fonts (Geist, Geist Mono, Instrument Sans) are SIL OFL 1.1, not covered by MIT; see [resources/fonts/LICENSE](resources/fonts/LICENSE).

By contributing you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).

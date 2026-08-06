# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Revealed credentials no longer reach the browser in Livewire state.** A reveal
  put the decrypted username and secret into a public component property, which
  Livewire serialises into `wire:snapshot` and posts back on every subsequent
  request — leaving the secret readable in the page source long after the reveal.
  Revealed values now live in the server-side session, reach the view without
  entering component state, and re-mask on the next page load.
- The `/_ops/{action}` route can no longer rewrite Blade layouts, refuses to run
  when the configured token is shorter than 32 characters, and accepts the token as
  an `Authorization: Bearer` header so it stays out of access logs and history.
- Editing a task no longer changes its assignee unless the actor holds
  `tasks.assign`. The form hid the field; hiding a field is not authorisation.
- CSV exports escape values a spreadsheet would otherwise execute as a formula.
- Partner payout details are encrypted at rest, with a migration for existing rows.
- Finance edit and delete resolve records through the caller's project scope.
- Password reset requests are rate-limited per IP, and uploads validate type as well
  as size.
- The Settings two-factor toggle no longer implies a protection that does not exist.
  There is no enrollment step and no login challenge; the UI and docs say so.

### Fixed

- **Distribution runs could pay the wrong amount.** Shares were rounded per owner, so
  a split could create or destroy minor units — two 50% owners of one unit were each
  paid one. Shares are now allocated by largest remainder and always sum to the
  profit.
- Approval now rebuilds figures and lines under a row lock rather than crediting
  whatever the draft captured, so revenue arriving after a draft was raised is paid
  and the frozen ownership snapshot matches the lines it describes.
- Concurrent approvals can no longer credit twice, and two drafts for the same month
  can no longer both be approved.
- A project with profit but no ownership split is rejected instead of being silently
  skipped, which previously left its profit in the run totals but credited to nobody.
- Approved runs and their lines are immutable at the model layer, and financial
  records refuse to hard-delete.
- Duplicate auto-generated expenses are prevented by database constraints rather than
  a PHP check that two concurrent requests could both pass.

### Performance

- The portfolio list rendered twelve projects in 362 queries and the dashboard in
  148, because each row asked for its own revenue, cost, profit and open task count
  and the cost lookup ran the full P&L pipeline. Both now batch: **12 and 24**, flat
  as projects are added, with a test holding the budget.
- Permission and role lookups are memoized per request.
- `Model::preventLazyLoading()` is enabled outside production.
- Partner balances resolve in one query, and reporting filters have indexes.

### Changed

- Base currency, its minor-unit exponent and its display symbol are now driven by
  `config/money.php` and the Settings screen instead of being fixed to one currency.
  `App\Support\Currency` resolves them, with a saved setting taking precedence over
  the environment default.
- Display timezone falls back to `APP_DISPLAY_TIMEZONE` (default `UTC`) rather than a
  hardcoded zone.
- Demo seeders use generic, obviously fake data throughout.
- The seeder now generates and prints a random admin password outside `local` and
  `testing`, instead of silently seeding a known one. `ADMIN_PASSWORD` overrides it.
- The demo seeders no longer run outside `local` and `testing`, so `migrate --seed`
  can no longer create fake projects or financial records on a real installation.
  `SEED_DEMO_DATA` forces the decision either way.

### Added

- Open-source project files: MIT `LICENSE`, rewritten `README.md`, `CONTRIBUTING.md`,
  `CODE_OF_CONDUCT.md`, `SECURITY.md`, this changelog, GitHub Actions CI, and issue
  and pull request templates.
- SIL Open Font License notices for the bundled Geist, Geist Mono and Instrument Sans
  subsets (`resources/fonts/LICENSE`).
- `tests/Feature/SmokeRenderTest.php` renders every parameterless page and the main
  detail pages against the demo dataset.
- `tests/Feature/CurrencyTest.php` covers zero-, two- and three-decimal currencies,
  frozen-rate conversion across differing exponents, and settings-over-config
  precedence.

### Fixed

- Currency column defaults no longer ship as one organisation's currency
  (new migration; the original migrations are untouched).

## [1.0.0]

First complete release. Built as seven milestones, each shipped and verified before
the next began.

### Milestone 1 — Foundation

- Laravel application skeleton, authentication, password reset.
- Many-to-many roles and permissions: 49 granular permissions across 5 seeded roles
  (admin, partner, supervisor, staff, accountant). Effective permissions are the union
  of a user's roles; no `role` column and no role switcher.
- User administration, activation state, and an app settings store.
- Application shell: navigation rail, top bar, command palette, mobile drawer and
  thumb bar.
- Design system: tokens, dark mode, density modes and a Blade component library.
- Seeders for roles, permissions, settings and task templates.

### Milestone 2 — Projects & credentials

- Project portfolio CRUD with status, CMS, niche, monetisation state, acquisition cost.
- Per-project ownership shares validated to total 100%, and team assignment that scopes
  staff visibility.
- Encrypted credential vault, with reveal gated by its own permission and every reveal
  written to an audit log.
- Scheduled credential expiry alerts with configurable warning windows.
- Portfolio dashboard.

### Milestone 3 — Work

- Tasks with checklists, priorities, due dates, attachments, comments and evidence
  on submit.
- Recurring task generation and reusable task templates.
- Article pipeline from brief to published, with word-count targets and per-article cost.
- Link building log with per-project monthly budgets.
- A single keyboard-driven approval queue across tasks, articles and links, optionally
  raising the matching expense on approval.

### Milestone 4 — People

- Attendance derived from the first login of the day, with a configurable late hour,
  plus supervisor leave and holiday marking.
- Login history with IP and user agent.
- Daily work logs.
- Monthly scorecards aggregating output per person, costed against mixed pay rates
  (monthly salary and/or per-article, per-link, per-task).

### Milestone 5 — Money

- Revenue per project per month, entered directly or imported from CSV, with the FX
  rate frozen on each row so historical figures never re-convert.
- Direct and shared expenses, receipt uploads, recurring expense templates, paid state.
- Monthly profit and loss with shared costs allocated across projects in proportion
  to revenue.
- Manual partner distributions computed from ownership shares; approving a run locks it
  permanently, and corrections are new adjusting entries.
- Partner ledger and per-partner statements for capital, withdrawals and distribution
  credits.
- All monetary values stored as integer minor units.

### Milestone 6 — Deployment

- FTP packaging scripts for shared hosting (`deploy/package.sh`, `deploy/package.ps1`)
  producing an app archive and a public archive.
- Dual-path `public/index.php` that works both in the split shared-hosting layout and
  locally.
- Token-gated `/_ops/{action}` maintenance route for hosts without SSH, disabled
  entirely when no token is set.
- `storage:link` fallback that copies when `symlink()` is unavailable, plus a public
  media fallback route.
- Pure-PHP database backup command requiring no `mysqldump` or shell access.
- `DEPLOYMENT.md`.

### Milestone 7 — AI assistant

- Optional natural-language "ask your data" assistant and drafted monthly summaries,
  completely hidden when no provider key is configured.
- Questions map onto a fixed whitelist of read-only report methods that re-apply the
  caller's permissions and project scope; a model never generates executed SQL.
- Credentials, passwords, keys and bank details are stripped before any prompt is built.
- Monthly spend cap, per-request token and cost logging, and response caching.

[Unreleased]: https://github.com/tnandla/portfolio-os/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/tnandla/portfolio-os/releases/tag/v1.0.0

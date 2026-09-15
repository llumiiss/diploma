# CLAUDE.md — CertiSub Assistant (assistent_subscription)

Guidance for AI agents working in this repo. Read `docs/AGENT_HANDOFF.md` for the full project brief, feature status, and roadmap — this file is the quick operational contract.

## What this is
University thesis project (author: Maksym Litosh, Uniwersytet Śląski). A PHP 8 / MySQL LAMP web app ("CertiSub Assistant") for tracking SSL certificates, SaaS subscriptions, domains, and renewal payments, with two scopes: **corporate** (`dashboard.php`) and **personal** (`dashboard-personal.php`). Primary human language: Polish; UI is i18n (pl/en/es/de/uk).

## Environment (Windows + Laragon — tools are NOT on the default PATH)
- PHP 8.3.30: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- MySQL 8.4.3: `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin`
- Composer: `C:\laragon\bin\composer\composer.phar`
- These were added to the user's PATH on 2026-09-15, so a **new** terminal has `php` / `composer` / `mysql` directly. In a stale shell, use the full paths above.
- **Laragon services (Apache + MySQL) must be started by the user** from the Laragon app ("Start All") — they are often stopped, and the app cannot start them reliably. Without MySQL you can run PHP + unit tests but not the app, integration tests, or migrations.
- App URL: http://localhost/assistent_subscription/

## Commands
```
composer test        # PHPUnit 11 — unit suite is 10 tests, must stay green
composer stan        # PHPStan level 5 (see phpstan.neon)
composer cs          # PHP-CS-Fixer dry-run (diff only)
composer cs-fix      # PHP-CS-Fixer apply (PSR-12 + short arrays, ordered imports)
php scripts/migrate.php          # idempotent DB migrations (needs MySQL up)
php scripts/test-mail.php you@example.com   # mail smoke test
$env:RUN_INTEGRATION_TESTS=1; composer test # DB integration tests
```
Current quality baseline (2026-09-15): 10/10 unit tests pass; PHPStan reports 2 pre-existing benign "always true" notes (MigrationRunner.php:92, send_reminders.php:120) — not blockers.

## Code conventions (match existing code)
- PHP: `declare(strict_types=1);`, `final` classes, namespace `App\`, PDO prepared statements.
- API endpoints (`api/*.php`): JSON in/out, require auth, require CSRF header `X-CSRF-TOKEN`, validate all input.
- Dashboard UI is Vue 3 + Tailwind (CDN) inside `includes/dashboard_app.php`; one file serves both scopes, switched by `$scope`.
- i18n: put user-facing strings as keys in `lang/*.php` (all five languages) — never hardcode Polish/English in markup.
- Never commit `config/mail.local.php` or other secrets (already gitignored). `vendor/` is gitignored.
- **Focused changes only** — do not expand scope beyond the current request. Add/extend PHPUnit tests when you touch auth or CRUD logic.

## Roadmap priority (from docs/AGENT_HANDOFF.md §9/§12)
1. CRUD for the `subscriptions` table (backend in `SubscriptionManager` + `api/` endpoints + modal in `dashboard_app.php`) — the biggest functional gap.
2. Edit/delete in `ManagerSubscriptionManager` (personal "Moje Subskrypcje").
3. CRUD for users & payers (views exist; wire up write endpoints).
4. RBAC (roles ADMIN/MANAGER/OPERATOR exist in DB but don't restrict anything yet).
5. Extend reminder cron to the `subscriptions` table; UX polish.
Do **not** work on the written thesis chapters unless the user explicitly asks.

## Available tooling in this environment (for the agent)
- 96 cybersecurity/appsec skills are installed globally (auth, OWASP, API security, secrets, email, LLM-injection). Use them when reviewing/hardening auth, CSRF, API, and email code.
- `ui-ux-pro-max` plugin skills (design, ui-styling, design-system) are available for dashboard/UI work.
- 21st MCP is available for UI component generation.

## Git
Local repo initialized 2026-09-15 (no remote). Commit focused changes with clear messages; keep the unit suite green before committing.

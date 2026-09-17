# CLAUDE.md — CertiSub Assistant (assistent_subscription)

Guidance for AI agents working in this repo. **Start with `docs/MAPA_PROJEKTU.md`** — it carries the official thesis description (the authoritative scope), the requirement matrix, the bug list, the phased plan, the decisions D1–D7 and the changelog. `docs/INSTRUKCJA_OBSLUGI.md` is the user manual and demo script. `docs/AGENT_HANDOFF.md` is an older brief whose roadmap is superseded by the map. This file is the quick operational contract.

## What this is
University thesis project (author: Maksym Litosh, Uniwersytet Śląski). A PHP 8 / MySQL LAMP web app ("CertiSub Assistant") for managing **certificates** (qualified signatures and seals, SSL, code signing, domains, SaaS) together with **certificate users (beneficiaries)** and **payers**: renewal ToDo tasks, e-mail invitations with templates and attachments, reminders, archiving, event history and reports. Two dashboard scopes share one UI: **corporate** (`dashboard.php`, the thesis domain) and **personal** (`dashboard-personal.php`, an extra module frozen by decision D1 — keep it working, do not extend it). Primary human language: Polish.

Status (2026-09-16): Etap 0 (security fixes) and Etap 1 (data model) are done. There is no UI or API yet for creating or editing certificates, beneficiaries, payers, tasks, templates or invitations — that starts in Etap 2 (map §7).

## Environment (Windows + Laragon — tools are NOT on the default PATH)
- PHP 8.3.30: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- MySQL 8.4.3: `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin`
- Composer: `C:\laragon\bin\composer\composer.phar`
- These were added to the user's PATH on 2026-09-15, so a **new** terminal has `php` / `composer` / `mysql` directly. In a stale shell, use the full paths above.
- **Laragon services (Apache + MySQL) must be started by the user** from the Laragon app ("Start All") — they are often stopped, and the app cannot start them reliably. Without MySQL you can run PHP + unit tests but not the app, integration tests, or migrations.
- App URL: http://localhost/assistent_subscription/
- php.ini runs PHP in UTC; `bootstrap.php` sets `Europe/Warsaw` so PHP dates match MySQL `CURDATE()`. Keep it that way.

## Commands
```
composer test                                    # PHPUnit 11 — 28 tests; the 2 integration tests skip without the flag below
$env:RUN_INTEGRATION_TESTS=1; composer test      # + MySQL integration tests (they run pending migrations on the real DB)
composer stan                                    # PHPStan level 5 (see phpstan.neon)
composer cs                                      # PHP-CS-Fixer dry-run (diff only)
php scripts/migrate.php                          # idempotent DB migrations (needs MySQL up)
php scripts/seed-demo-data.php --force           # demo data for every panel (replaces previous demo data)
php scripts/cleanup-demo-data.php                # wipes ALL business data + @example.com accounts
php scripts/set-role.php --list                  # list accounts and their roles
php scripts/set-role.php you@example.com ADMIN   # grant a role (ADMIN > MANAGER > OPERATOR)
php scripts/test-mail.php you@example.com        # mail smoke test
```
**Never run `php scripts/migrate.php --fresh` against the user's database** — it drops every table. Test fresh installs in a throwaway database instead.

Quality baseline (2026-09-16, after Etap 1): 28/28 tests pass with integration enabled; PHPStan reports 1 pre-existing benign note (`cron/send_reminders.php:120`). `composer cs` flags most files mainly because @PSR12 wants LF endings and the repo is CRLF — do not run `cs-fix` without a separate line-ending normalization commit.

## Data model (Etap 1 — details in `docs/MAPA_PROJEKTU.md` §2.2)
- `certificates` (formerly `subscriptions`; type column `certificate_type`), `beneficiaries`, `payers`, `users` (staff accounts, owners of records via `certificates.user_id`), `renewal_tasks` (todo / in_progress / done / abandoned, at most one open task per certificate), `email_templates` + `attachments` + `email_template_attachments`, `invitations` + `invitation_attachments`, `events` (timeline — no foreign keys on purpose), `manager_subskrypcji` (frozen personal module), `login_otps`.
- Archive, don't delete: `archived_at` on certificates, beneficiaries and payers. Every "current" read query must filter `archived_at IS NULL`.
- Schema changes go through `App\MigrationRunner` as idempotent migrations (pattern: `classes/Migrations/CertificatesModelMigration.php` with `SchemaInspector` checks) **and** into `database/schema.sql`, which must stay structurally identical to a migrated database. `schema.sql` may contain semicolons only at statement ends — the `--fresh` importer splits on them.
- Stored files live in `storage/attachments/` (blocked over HTTP, contents gitignored).

## Code conventions (match existing code)
- PHP: `declare(strict_types=1);`, `final` classes, namespace `App\`, PDO prepared statements.
- API endpoints (`api/*.php`): JSON in/out, require auth, require CSRF header `X-CSRF-TOKEN`, validate all input, and check the role with `App\Rbac` before returning or writing organisation-wide data.
- Authorization: `App\Rbac` defines the hierarchy ADMIN > MANAGER > OPERATOR. Read queries that can return other people's records take an `?int $ownerId` and filter on it; OPERATOR never sees the org-wide directories of people and payers. Hiding things only in Vue is not access control.
- CLI-only scripts (`scripts/*.php`, `cron/*.php`) begin with a `PHP_SAPI !== 'cli'` guard *before* `require bootstrap.php`. The project folder is the web docroot, so the root `.htaccess` blocks internal directories — extend it whenever you add a directory that must not be served.
- Dashboard UI is Vue 3 + Tailwind (CDN) inside `includes/dashboard_app.php`; one file serves both scopes, switched by `$scope`. The view still calls rows "subscriptions" — certificate labels arrive with the Etap 2 UI.
- i18n (decision D4): Polish is the default locale; add new user-facing keys to `lang/pl.php` and `lang/en.php` only (es/de/uk fall back to English). Never hardcode Polish/English in markup.
- Never commit `config/mail.local.php` or other secrets (already gitignored). `vendor/` is gitignored.
- **Focused changes only** — do not expand scope beyond the current request. Add/extend PHPUnit tests when you touch auth, CRUD or domain logic.
- Do **not** work on the written thesis chapters unless the user explicitly asks.

## Available tooling in this environment (for the agent)
- 96 cybersecurity/appsec skills are installed globally (auth, OWASP, API security, secrets, email, LLM-injection). Use them when reviewing/hardening auth, CSRF, API, and email code.
- `ui-ux-pro-max` plugin skills (design, ui-styling, design-system) are available for dashboard/UI work.
- 21st MCP is available for UI component generation.

## Git
Local repo, no remote, main branch `master`. Commit only when the user asks, with clear focused messages; keep the test suite green before committing.

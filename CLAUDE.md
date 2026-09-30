# CLAUDE.md — CertiSub Assistant (assistent_subscription)

Guidance for AI agents working in this repo. **Start with `docs/MAPA_PROJEKTU.md`** — it carries the official thesis description (the authoritative scope), the requirement matrix, the bug list, the phased plan, the decisions D1–D8 and the changelog. `docs/INSTRUKCJA_OBSLUGI.md` is the user manual and demo script. `docs/AGENT_HANDOFF.md` is an older brief whose roadmap is superseded by the map. This file is the quick operational contract.

## What this is
University thesis project (author: Maksym Litosh, Uniwersytet Śląski). A PHP 8 / MySQL LAMP web app ("CertiSub Assistant") for managing **certificates** (qualified signatures and seals, SSL, code signing, domains, SaaS) together with **certificate users (beneficiaries)** and **payers**: renewal ToDo tasks, e-mail invitations with templates and attachments, reminders, archiving, event history and reports. One dashboard (`dashboard.php`), one domain. Primary human language: Polish.

**The app has no personal/private side any more.** Etap 8 (2026-09-18) moved it out to a separate application in `C:\laragon\www\menedzer_subskrypcji` ("Menedżer Subskrypcji") with its own database, accounts and UI. The two share nothing and must not link to each other — if a request sounds like "private subscriptions", "Netflix", "the personal panel", it belongs in that repository, not this one.

Status (2026-09-30): **all stages of the plan (Etapy 0–8) are done, plus Etap 9 — password login with e-mail verification (see below)** — security, data model, records CRUD + RBAC, renewal process, reports, data exchange, quality work (no CDN, OTP limit in the DB, E2E scenario), UX/performance (content skeletons, cached KPI aggregates, cache headers) and the split of the two applications. All 19 functional requirements of the thesis description are covered. What remains is the written thesis (map §9) and the schedule with the promoter (decision D6); keep the code in maintenance mode — small fixes, no new scope without asking.

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
composer test                                    # PHPUnit 11 — 187 tests; the 81 integration tests skip without the flag below
$env:RUN_INTEGRATION_TESTS=1; composer test      # + integration tests on a throwaway DB assistent_subscriptions_test (recreated each run)
composer stan                                    # PHPStan level 5 (see phpstan.neon)
composer cs                                      # PHP-CS-Fixer dry-run (diff only)
php scripts/migrate.php                          # idempotent DB migrations (needs MySQL up)
php scripts/seed-demo-data.php --force           # demo data (replaces previous demo data)
php scripts/cleanup-demo-data.php                # wipes ALL business data + @example.com accounts
php scripts/set-role.php --list                  # list accounts and their roles (emergency tool; ADMIN normally uses the Accounts screen)
php scripts/set-password.php --list              # account state: has a password? address confirmed? last login
php scripts/set-password.php --email=a@b.pl --send-link       # e-mail a one-time "set password" link
php scripts/set-password.php --email=a@b.pl --password='...'  # set a password directly (emergency)
php scripts/oauth2-token.php --client-id=... --client-secret=...   # one-off: OAuth2 refresh token for mail
php scripts/test-mail.php you@example.com        # mail smoke test
php scripts/mailtrap-inbox.php --link            # read the Mailtrap sandbox inbox from the terminal (needs the 'mailtrap' block in config/mail.local.php)
php cron/renewals.php                            # daily: renewal scanner + due invitation reminders (logs/renewals.log)
npm install && npm run css                       # rebuild assets/css/app.css after changing Tailwind classes (the built file is committed)
```
Mail: the author's `config/mail.local.php` (gitignored) is set to driver `sandbox` — everything goes to their Mailtrap inbox "diploma" and never to a real address; read it with `scripts/mailtrap-inbox.php`. The free Mailtrap plan accepts **one message per second** (`550 5.7.0 Too many emails per second`); `App\Mailer` retries such a rejection once after 2 s, and every failed send is logged with its SMTP reason to `logs/mail-errors.log`. Driver `log` writes messages to `logs/mail.log` instead of sending — use it for demos; never let an agent send real invitations while testing (tests use `Tests\Support\RecordingInvitationMailer`).
**Never run `php scripts/migrate.php --fresh` against the user's database** — it drops every table. Take a `mysqldump` of `assistent_subscriptions` before applying a new schema migration.

Quality baseline (2026-09-30, after Etap 9): 187/187 tests pass with integration enabled, PHPStan level 5 reports no errors, `composer cs` is clean (0 of 148 files). The `password_auth` migration is applied to `assistent_subscriptions`, and `information_schema` of the app DB matches the test DB built from `schema.sql` + migrations (147 columns / 85 index entries / 21 foreign keys). The whole auth flow was also exercised over HTTP on Apache with real Mailtrap delivery: register → blocked before confirmation → link taken from the inbox → sign-in → dashboard → logout → login rate limit → CSRF → "forgot password" → new password → old password rejected; 403 on internal paths. `.gitattributes` keeps the working tree on LF, so keep it that way and run `composer cs-fix` in its own commit when it reports something.

## Authentication (Etap 9, 2026-09-30)
Email + password with e-mail verification; mail goes over SMTP with OAuth2 (XOAUTH2). `AuthManager` drives `register` -> `verifyEmail` -> `attemptLogin`, plus `requestPasswordSetLink` / `setPasswordWithToken` (admin-created accounts and "forgot password"). Helpers: `classes/Auth/` (`PasswordPolicy`, `VerificationTokens`, `LoginThrottle`) and `classes/Mail/OAuth2TokenProvider` (implements PHPMailer's `OAuthTokenProvider`, refresh-token grant, token cache in `storage/cache/oauth2`, no new Composer packages). Rules to keep: passwords only as `password_hash()` digests; one message for wrong password and unknown e-mail; registration on a taken address changes nothing and sends nothing; tokens stored as SHA-256, single-use, with an expiry; links built with `AppUrl` (config first, never a bare `Host` header). Public registration is a switch in `config/auth.php` (decision D3). The OTP flow is gone from the code; `login_otps` stays as history.

## Architecture (Etap 2)
- **Services** `classes/Service/*Service.php` hold business rules: validation (`Validator`), permission checks (`Actor::authorize()` → `Rbac::can()`), data scope (`Visibility`, decision D8), history (`EventLogger` → `events`), transactions (`Transaction::run`). They take a `PDO` and an `Actor`, never read the session — so they are testable and reusable from CLI/import.
- **HTTP**: `classes/Http/ApiKernel.php` (method → CSRF for writes → session → handler → JSON with HTTP status mapped from `ServiceException`: 400/403/404/409/422, 500 without details). Controllers in `classes/Api/*Controller.php`; each `api/*.php` is a one-liner `ApiKernel::run(new XController())`. No `exit` in library code.
- **Frontend assets (Etap 6)**: nothing is loaded from a CDN. `assets/css/app.css` is built from `assets/css/app.src.css` with `npm run css` (Tailwind 3, config in `tailwind.config.js`, content = PHP files + `assets/js`), Vue lives in `assets/vendor`, Inter in `assets/fonts`; all three are committed, so the app runs without Node and without internet. Add new component classes to `app.src.css` (not to a `<style type="text/tailwindcss">` block) and rebuild.
- **UI**: `includes/dashboard_app.php` checks the session and injects `window.CERTISUB_BOOT` (i18n, CSRF token, user, permission list, endpoints). Vue 3 without a build step: `assets/js/core.js` (API client, formatting, store, toasts, modal stack), `assets/js/components.js`, views in `assets/js/views/`, mounted by `assets/js/app.js`. Views are addressed by hash (`dashboard.php#/payers`). The permission list only hides buttons — the API enforces access.
- **Renewal process (Etap 3)**: `RenewalScanner` (margin = `renewal_lead_days` or `Settings` default; one open task per certificate; a closed task with `due_date = expiry_date` marks the cycle handled) → `TaskService` (status transitions, assignment, `renew` = new certificate + archive old via `CertificateService::renewFrom`) → `InvitationService` (template render via `TemplateRenderer`, attachments via `AttachmentService`, mail via the `InvitationMailer` interface, reminders by `processDueReminders`). `App\Settings` holds admin-tunable thresholds (defaults in code, overrides in table `settings`); tests call `Settings::useDefaultsOnly()` in `tests/bootstrap.php`.
- **Reports and search (Etap 4)**: `ReportService` builds the beneficiary/payer cards (`#/beneficiaries/N`, `#/payers/N` — routes with an id render the `card` component from `VIEWS` in `app.js`) and the expiry schedule; `SearchService` matches own fields and relations and returns `matched` reasons; `TimelineService::forContext(..., $actor)` hides events of certificates outside the operator scope, `TimelineService::journal` is the ADMIN event log. All three are read-only GET endpoints (`api/reports.php`, `api/search.php`, `api/events.php`). Event labels/details for timelines and the log are shared in `CertiSub.events` (`assets/js/components.js`).
- **Data exchange (Etap 5)**: `App\Exchange` holds the formats (`CsvReader`/`CsvWriter`, `XmlRecordReader`/`XmlExporter`, `EmlParser` — a pure-PHP MIME parser, no `mailparse`), the column definitions with header aliases (`Columns`) and value normalisation (`Normalizer`). `ExportService` writes files from the same service queries the screens use (so the data scope holds); `ImportService` runs the real writes through the record services inside a transaction and rolls it back for a preview (one SAVEPOINT per row), so preview and commit cannot diverge; `EmlImportService` matches a message against the registry. Import is ADMIN (`import.run`), export MANAGER+ (`export.run`).
- **Caching (Etap 7)**: `App\Cache` stores JSON files in `storage/cache` with a 5-minute TTL. Cache keys must contain the role and the owner id (an operator must never read organisation-wide numbers), and `EventLogger::log()` bumps a version file so every write invalidates everything. Tests point the cache at a temp dir in `tests/bootstrap.php`.
- **Tests**: unit tests in `tests/Unit` (SQLite or no DB); integration tests must not touch typed properties in `tearDown` before `parent::setUp()` has run (they are skipped without the flag); integration tests extend `Tests\Support\IntegrationTestCase` and use `MysqlTestDatabase` (fresh `assistent_subscriptions_test` from `schema.sql` + migrations, business tables and settings cleared and default templates restored before each test).

## Data model (details in `docs/MAPA_PROJEKTU.md` §2.2)
- Etap 8 removed `certificates.scope`, the table `manager_subskrypcji` and the personal certificate types (STREAMING, MUSIC, GAMING, FITNESS, CLOUD_STORAGE) — one database, one domain. Before that, no schema changes since Etap 3.
- `certificates` (formerly `subscriptions`; type column `certificate_type`), `beneficiaries`, `payers`, `users` (staff accounts, owners of records via `certificates.user_id`; `deactivated_at`), `renewal_tasks` (todo / in_progress / done / abandoned, at most one open task per certificate), `email_templates` + `attachments` + `email_template_attachments`, `invitations` + `invitation_attachments`, `events` (timeline — no foreign keys on purpose), `settings` (admin overrides of renewal thresholds), `login_otps` (sign-in history from before Etap 9), `users.password_hash` / `email_verified_at` / `last_login_at`, `email_verifications` (single-use EMAIL_VERIFY and PASSWORD_SET tokens, stored as SHA-256 only), `login_attempts` (login rate limit).
- `payers.created_by_user_id` / `beneficiaries.created_by_user_id` record who entered the row (OPERATOR scope, D8).
- Archive, don't delete: `archived_at` on certificates, beneficiaries and payers. Every "current" read query must filter `archived_at IS NULL`; archived records are read-only.
- Schema changes go through `App\MigrationRunner` as idempotent migrations (pattern: `classes/Migrations/*Migration.php` with `SchemaInspector` checks) **and** into `database/schema.sql`, which must stay structurally identical to a migrated database (compare `information_schema` of the app DB and the test DB). `schema.sql` may contain semicolons only at statement ends — the `--fresh` importer splits on them.
- Stored files live in `storage/attachments/` (blocked over HTTP, contents gitignored).

## Code conventions (match existing code)
- PHP: `declare(strict_types=1);`, `final` classes, namespace `App\` (sub-namespaces `App\Service`, `App\Http`, `App\Api`, `App\Migrations`), PDO prepared statements. Native prepares are on — a named parameter may appear only once per statement (`Visibility` generates unique names).
- New endpoint = service method + controller action + permission in `Rbac::PERMISSIONS` + i18n keys + tests. Writes are POST with `{action, id?, data?}` and header `X-CSRF-TOKEN`.
- Authorization: `App\Rbac` defines ADMIN > MANAGER > OPERATOR and the action permission matrix. OPERATOR sees own/assigned certificates and the people/payers linked to them or entered by them (D8); MANAGER+ sees the whole organisation. Hiding things only in Vue is not access control.
- Every business write logs an event (`EventLogger`) with context ids (`certificate_id`, `beneficiary_id`, `payer_id`) so the timelines work.
- CLI-only scripts (`scripts/*.php`, `cron/*.php`) begin with a `PHP_SAPI !== 'cli'` guard *before* `require bootstrap.php`. The project folder is the web docroot, so the root `.htaccess` blocks internal directories — extend it whenever you add a directory that must not be served (`assets/` is public on purpose).
- i18n (decision D4): Polish is the default locale; add new user-facing keys to `lang/pl.php` and `lang/en.php` only (es/de/uk fall back to English). Never hardcode Polish/English in markup or JS — use `t('key')`. Event labels are `event.<entity>.<type>`, field labels `field.<column>`.
- Never commit `config/mail.local.php` or other secrets (already gitignored). `vendor/` is gitignored. `.claude/settings.json` is deliberately untracked.
- **Focused changes only** — do not expand scope beyond the current request. Add/extend PHPUnit tests when you touch auth, CRUD or domain logic.
- Do **not** work on the written thesis chapters unless the user explicitly asks.

## Available tooling in this environment (for the agent)
- 96 cybersecurity/appsec skills are installed globally (auth, OWASP, API security, secrets, email, LLM-injection). Use them when reviewing/hardening auth, CSRF, API, and email code.
- `ui-ux-pro-max` plugin skills (design, ui-styling, design-system) are available for dashboard/UI work.
- 21st MCP is available for UI component generation.

## Git
Main branch `master`; remote `origin` = github.com/llumiiss/diploma — **never push unless the user asks**. Work per stage on a branch `etap-N-…`, one Polish commit "Etap N: …", then fast-forward `master`. Commit only when the user asks, with clear focused messages; keep the test suite green before committing.

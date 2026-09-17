# CertiSub Assistant — README

Engineering thesis project: a LAMP web application for managing certificates (qualified signatures and seals, SSL, code signing, domains, SaaS) together with their users (beneficiaries) and payers — renewal tasks, invitations, reminders and history.

- Project map, requirements, plan and changelog: [`docs/MAPA_PROJEKTU.md`](docs/MAPA_PROJEKTU.md)
- User manual and demo script (Polish): [`docs/INSTRUKCJA_OBSLUGI.md`](docs/INSTRUKCJA_OBSLUGI.md)

## Requirements

- PHP 8.1+ (developed on 8.3)
- MySQL 8 / MariaDB 10.5+
- Apache with `mod_rewrite` and `AllowOverride All` (e.g. Laragon) — the root `.htaccess` blocks internal directories
- Composer (`composer install`)

## Installation

### New database

```powershell
composer install
php scripts\migrate.php --fresh
```

`--fresh` **drops all tables**, imports `database/schema.sql` and runs the migrations (they add the default e-mail templates). Use it only on a new or throwaway database.

Alternatively import `database/schema.sql` in HeidiSQL / phpMyAdmin, then run `php scripts\migrate.php`.

### Existing database

```powershell
php scripts\migrate.php
```

Migrations are idempotent — run them after every update. The `database/migration_*.sql` snippets cover only the first two historical migrations; use `migrate.php` for everything else.

### Demo data

```powershell
php scripts\seed-demo-data.php
```

Fills every panel: certificates in all priorities, beneficiaries, payers, an archive with a renewal chain, ToDo tasks in every status, invitations with an attachment, event history and the personal module. Re-create with `--force`; remove with `php scripts\cleanup-demo-data.php`.

### Accounts and roles

Public registration is disabled — an ADMIN creates staff accounts in **Management → Accounts & roles**. To make the first administrator (e.g. right after installation):

```powershell
php scripts\set-role.php you@example.com ADMIN
```

Hierarchy: ADMIN > MANAGER > OPERATOR. An OPERATOR works on the certificates they own or have a renewal task for, and on the people and payers linked to them or entered by them; MANAGER and ADMIN see the whole organisation; archiving needs MANAGER; accounts need ADMIN.

### Mail (OTP codes)

1. Copy `config/mail.local.php.example` → `config/mail.local.php`
2. **Mailtrap Sandbox** (`driver => sandbox`) — mail appears only at [mailtrap.io](https://mailtrap.io), **not** in Gmail/Outlook
3. **Real delivery** — set `driver => smtp` with a Gmail app password or Mailtrap Email Sending (`live.smtp.mailtrap.io`)

```powershell
php scripts\test-mail.php you@example.com
```

### Tests

```powershell
composer test
$env:RUN_INTEGRATION_TESTS=1; composer test   # + MySQL integration tests
composer stan
```

Integration tests create a throwaway database `assistent_subscriptions_test` (from `database/schema.sql` + migrations) on every run and never touch the application database. The MySQL user from `config/database.php` needs permission to create and drop that database.

## URLs

- Landing: http://localhost/assistent_subscription/
- Corporate dashboard: http://localhost/assistent_subscription/dashboard.php
- Personal dashboard: http://localhost/assistent_subscription/dashboard-personal.php

## Cron (payment reminders)

Run daily at 08:00 (CLI only):

```powershell
php cron\send_reminders.php
```

Windows Task Scheduler: program = full path to `php.exe`, argument = full path to `send_reminders.php`.

## Project structure

```
assistent_subscription/
├── index.php, login.php, register.php (redirect), logout.php
├── dashboard.php, dashboard-personal.php
├── api/                     # JSON endpoints — one line each, logic in classes/Api
├── assets/js/               # corporate dashboard UI (Vue 3 components, no build step)
├── classes/                 # AuthManager, Rbac, CertificateHelper, …
│   ├── Service/             # business rules: validation, permissions, data scope, event history
│   ├── Http/                # ApiKernel (CSRF, auth, JSON errors), Request, Response
│   ├── Api/                 # endpoint controllers
│   └── Migrations/          # idempotent schema migrations
├── includes/                # dashboard shells, shared head, language switcher
├── cron/send_reminders.php
├── database/schema.sql      # fresh install (same structure as a migrated database)
├── scripts/                 # migrate, seed-demo-data, cleanup-demo-data, set-role, test-mail
├── storage/attachments/     # stored files (not served over HTTP)
├── docs/                    # project map, user manual, thesis drafts
└── tests/                   # PHPUnit (unit + integration)
```

## Data model

`certificates`, `beneficiaries`, `payers`, `users`, `renewal_tasks`, `email_templates`, `attachments`, `invitations`, `events` (+ join tables), `login_otps`, `manager_subskrypcji`. Details: `docs/MAPA_PROJEKTU.md` §2.2.

## Features (current)

- Passwordless e-mail OTP login, CSRF protection; accounts created, deactivated and reassigned by an ADMIN
- Role-based permissions enforced by the API (ADMIN > MANAGER > OPERATOR) with a least-privilege data scope for operators
- Records of certificates, certificate users (beneficiaries) and payers: add, edit, archive and restore with validation (calendar dates, Polish NIP checksum, unique serial per issuer)
- Details panels with relations, renewal chain and event history; archive screen
- Corporate dashboard: KPIs, priority renewals, payments, ToDo view, search and filters
- Personal dashboard (frozen extra module) and its payment reminder cron
- i18n: PL (default), EN, ES, DE, UK

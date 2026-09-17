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

### Roles

```powershell
php scripts\set-role.php you@example.com ADMIN
```

Hierarchy: ADMIN > MANAGER > OPERATOR. An OPERATOR sees only the records they own.

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
├── index.php, login.php, register.php, logout.php
├── dashboard.php, dashboard-personal.php
├── api/                     # JSON endpoints (CSRF + auth)
├── classes/                 # AuthManager, Rbac, CertificateManager, CertificateHelper, …
│   └── Migrations/          # idempotent schema migrations
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

- Passwordless e-mail OTP login, CSRF protection, role-based data visibility
- Corporate and personal dashboards: KPIs, priorities, payments, ToDo view, search and filters
- Data model for certificates, beneficiaries, payers, tasks, templates, attachments, invitations and history (screens follow in later stages)
- Payment reminder cron (personal module)
- i18n: PL (default), EN, ES, DE, UK

# CertiSub Assistant — README

Demo application on the LAMP stack for tracking SSL certificates, SaaS subscriptions, domains, and renewal payments.

## Requirements

- PHP 8.x
- MySQL 5.7+ / MariaDB 10+
- Web server (Apache/Nginx) — e.g. Laragon
- Composer (`composer install` for PHPMailer)

## Installation

### 1. Fresh database (new project / empty MySQL)

In Laragon: **Menu → MySQL → HeidiSQL** (or Terminal), then import `database/schema.sql`.

PowerShell (when `mysql` is in PATH):

```powershell
Get-Content database\schema.sql | mysql -u root
```

### 2. Existing database (you already have data — your case)

The app added OTP login and user subscriptions after the first schema. Run **migrations** once:

**Option A — recommended (automatic, idempotent):**

```powershell
C:\laragon\bin\php\php-8.3.XX\php.exe scripts\migrate.php
```

Replace `php-8.3.XX` with your Laragon PHP folder name. Or open **Laragon → Terminal** (PHP is in PATH there):

```powershell
cd C:\laragon\www\assistent_subscription
php scripts\migrate.php
```

**Option B — manual in HeidiSQL / phpMyAdmin:**

1. Open database `assistent_subscriptions`
2. Run SQL from `database/migration_login_otp.sql`
3. Run SQL from `database/migration_manager_subskrypcji.sql`

You only need migrations if tables like `login_otps` or `manager_subskrypcji` are missing.

### Mail (OTP codes)

1. Copy `config/mail.local.php.example` → `config/mail.local.php`
2. **Mailtrap Sandbox** (`driver => sandbox`) — mail appears only at [mailtrap.io](https://mailtrap.io), **not** in Gmail/Outlook
3. **Real delivery** — set `driver => smtp` with Gmail app password or Mailtrap Email Sending (`live.smtp.mailtrap.io`)

Test configuration:

```powershell
php scripts\test-mail.php twoj@email.com
```

### Tests

```powershell
composer install
composer test

# MySQL integration tests (migrations):
$env:RUN_INTEGRATION_TESTS=1; composer test
```

### 4. URLs

- Landing: http://localhost/assistent_subscription/
- Corporate dashboard: http://localhost/assistent_subscription/dashboard.php
- Personal dashboard: http://localhost/assistent_subscription/dashboard-personal.php

## Cron (payment reminders)

Run daily at 08:00:

```powershell
php cron\send_reminders.php
```

Windows Task Scheduler: program = full path to `php.exe`, argument = full path to `send_reminders.php`.

## Project structure

```
assistent_subscription/
├── index.php, login.php, register.php
├── dashboard.php, dashboard-personal.php
├── scripts/migrate.php      # DB migrations (existing installs)
├── api/add_subscription.php
├── cron/send_reminders.php
├── classes/                 # AuthManager, SubscriptionManager, …
├── database/
│   ├── schema.sql           # full fresh install
│   └── migration_*.sql      # manual migration snippets
└── docs/thesis_part1.md
```

## Features

- Email OTP login (passwordless)
- Corporate & personal subscription dashboards
- User-owned subscriptions (`manager_subskrypcji`)
- Payment reminders cron
- i18n: EN, PL, ES, DE, UK

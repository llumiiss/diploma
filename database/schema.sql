-- ============================================================
-- CertiSub Assistant — schemat bazy danych (świeża instalacja)
--
-- Model danych Etapów 1–6, opis w docs/MAPA_PROJEKTU.md (sekcja 2.2).
-- Kształt tabel jest taki sam jak po migracjach z classes/Migrations/ (sprawdzane porównaniem
-- information_schema), dlatego istniejące instalacje aktualizuje się poleceniem: php scripts/migrate.php
--
-- Świeża instalacja: php scripts/migrate.php --fresh
-- Polecenie importuje ten plik i uruchamia migracje, które dodają domyślne szablony wiadomości.
-- Dane demonstracyjne: php scripts/seed-demo-data.php
--
-- Uwaga: --fresh dzieli plik na polecenia po znaku średnika, więc komentarze nie mogą go zawierać.
-- ============================================================

CREATE DATABASE IF NOT EXISTS assistent_subscriptions
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE assistent_subscriptions;

DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS invitation_attachments;
DROP TABLE IF EXISTS invitations;
DROP TABLE IF EXISTS email_template_attachments;
DROP TABLE IF EXISTS attachments;
DROP TABLE IF EXISTS email_templates;
DROP TABLE IF EXISTS renewal_tasks;
DROP TABLE IF EXISTS certificates;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS beneficiaries;
DROP TABLE IF EXISTS login_otps;
DROP TABLE IF EXISTS manager_subskrypcji;
DROP TABLE IF EXISTS payers;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS schema_migrations;

-- Konta systemowe (personel) z hierarchią ról ADMIN > MANAGER > OPERATOR.
-- Konta zakłada ADMIN (decyzja D3). deactivated_at blokuje logowanie bez usuwania historii.
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    role            ENUM('ADMIN', 'MANAGER', 'OPERATOR') NOT NULL DEFAULT 'OPERATOR',
    email           VARCHAR(255) NULL,
    deactivated_at  DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Płatnicy — podmioty opłacające usługi certyfikacyjne.
-- created_by_user_id: kto wprowadził rekord (zakres danych operatora — decyzja D8).
CREATE TABLE payers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name        VARCHAR(255) NOT NULL,
    contact_person      VARCHAR(200) NOT NULL,
    tax_id              VARCHAR(20) NULL,
    email               VARCHAR(255) NULL,
    phone               VARCHAR(50) NULL,
    address_line        VARCHAR(255) NULL,
    postal_code         VARCHAR(16) NULL,
    city                VARCHAR(120) NULL,
    created_by_user_id  INT UNSIGNED NULL,
    archived_at         DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payers_tax_id (tax_id),
    KEY idx_payers_archived (archived_at),
    KEY idx_payers_created_by (created_by_user_id),
    CONSTRAINT fk_payers_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jednorazowe kody logowania (OTP)
CREATE TABLE login_otps (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    email      VARCHAR(255) NOT NULL,
    request_ip VARCHAR(45) NULL,
    code_hash  VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_login_otps_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_login_otps_email_expires (email, expires_at),
    INDEX idx_login_otps_email_created (email, created_at),
    INDEX idx_login_otps_ip_created (request_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Menedżer osobisty (moduł dodatkowy, zamrożony — decyzja D1)
CREATE TABLE manager_subskrypcji (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                   INT UNSIGNED NOT NULL,
    nazwa_uslugi              VARCHAR(255) NOT NULL,
    mail_subskrypcji          VARCHAR(255) NULL,
    username_konta            VARCHAR(255) NULL,
    koszt_pln                 DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    data_nastepnej_platnosci  DATE NOT NULL,
    created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_manager_subskrypcji_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_manager_sub_user (user_id),
    INDEX idx_manager_sub_payment_date (data_nastepnej_platnosci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Użytkownicy certyfikatów (beneficjenci) — dane osobowe, powiązanie z płatnikiem
CREATE TABLE beneficiaries (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name          VARCHAR(100) NOT NULL,
    last_name           VARCHAR(100) NOT NULL,
    email               VARCHAR(255) NULL,
    phone               VARCHAR(50) NULL,
    payer_id            INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_by_user_id  INT UNSIGNED NULL,
    archived_at         DATETIME NULL,
    created_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_beneficiaries_name (last_name, first_name),
    KEY idx_beneficiaries_email (email),
    KEY idx_beneficiaries_payer (payer_id),
    KEY idx_beneficiaries_archived (archived_at),
    KEY idx_beneficiaries_created_by (created_by_user_id),
    CONSTRAINT fk_beneficiaries_payer
        FOREIGN KEY (payer_id) REFERENCES payers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_beneficiaries_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Certyfikaty i usługi (dawniej subscriptions — decyzje D5, D7).
-- user_id to opiekun rekordu (konto personelu), beneficiary_id to użytkownik certyfikatu,
-- previous_certificate_id tworzy łańcuch kolejnych odnowień.
CREATE TABLE certificates (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                     VARCHAR(255) NOT NULL,
    scope                    ENUM('corporate', 'personal') NOT NULL DEFAULT 'corporate',
    certificate_type         ENUM(
        'QUALIFIED_SIGNATURE', 'QUALIFIED_SEAL', 'SSL_CERTIFICATE', 'CODE_SIGNING',
        'DOMAIN', 'SAAS', 'CLOUD_SUPPORT',
        'STREAMING', 'MUSIC', 'GAMING', 'FITNESS', 'CLOUD_STORAGE',
        'OTHER'
    ) NOT NULL DEFAULT 'OTHER',
    serial_number            VARCHAR(128) NULL,
    issuer                   VARCHAR(255) NULL,
    valid_from               DATE NULL,
    expiry_date              DATE NOT NULL,
    renewal_lead_days        SMALLINT UNSIGNED NULL,
    user_id                  INT UNSIGNED NOT NULL,
    beneficiary_id           INT UNSIGNED NULL,
    payer_id                 INT UNSIGNED NOT NULL,
    previous_certificate_id  INT UNSIGNED NULL,
    status                   ENUM('pending', 'active', 'renewal_in_progress', 'expired') NOT NULL DEFAULT 'pending',
    annual_cost              DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    billing_cycle            ENUM('monthly', 'annual', 'multi_year') NOT NULL DEFAULT 'annual',
    currency                 CHAR(3) NOT NULL DEFAULT 'PLN',
    payment_status           ENUM('paid', 'due_soon', 'overdue', 'not_applicable') NOT NULL DEFAULT 'due_soon',
    last_payment_date        DATE NULL,
    auto_renew               TINYINT(1) NOT NULL DEFAULT 1,
    notes                    TEXT NULL,
    archived_at              DATETIME NULL,
    created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_certificates_issuer_serial (issuer, serial_number),
    KEY idx_certificates_user (user_id),
    KEY idx_certificates_beneficiary (beneficiary_id),
    KEY idx_certificates_payer (payer_id),
    KEY idx_certificates_previous (previous_certificate_id),
    KEY idx_scope (scope),
    KEY idx_certificates_type (certificate_type),
    KEY idx_expiry_date (expiry_date),
    KEY idx_status (status),
    KEY idx_payment_status (payment_status),
    KEY idx_certificates_archived (archived_at),
    CONSTRAINT fk_certificates_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_certificates_beneficiary
        FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_certificates_payer
        FOREIGN KEY (payer_id) REFERENCES payers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_certificates_previous
        FOREIGN KEY (previous_certificate_id) REFERENCES certificates(id)
        ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lista ToDo: zadania odnowień ze statusami do statystyk realizacji.
-- open_marker = 1 dla zadań otwartych, NULL dla zamkniętych, a unikalny indeks
-- pozwala na co najwyżej jedno otwarte zadanie na certyfikat.
CREATE TABLE renewal_tasks (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    certificate_id    INT UNSIGNED NOT NULL,
    assigned_user_id  INT UNSIGNED NULL,
    status            ENUM('todo', 'in_progress', 'done', 'abandoned') NOT NULL DEFAULT 'todo',
    priority          ENUM('expired', 'critical', 'warning', 'ok') NOT NULL DEFAULT 'warning',
    due_date          DATE NOT NULL,
    resolution_note   TEXT NULL,
    closed_at         DATETIME NULL,
    created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    open_marker       TINYINT UNSIGNED AS (IF(status IN ('todo', 'in_progress'), 1, NULL)) STORED,
    UNIQUE KEY uq_renewal_tasks_one_open (certificate_id, open_marker),
    KEY idx_renewal_tasks_user (assigned_user_id),
    KEY idx_renewal_tasks_status (status),
    KEY idx_renewal_tasks_due (due_date),
    CONSTRAINT fk_renewal_tasks_certificate
        FOREIGN KEY (certificate_id) REFERENCES certificates(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_renewal_tasks_user
        FOREIGN KEY (assigned_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Szablony wiadomości (zaproszenia i przypomnienia) w wersjach językowych
CREATE TABLE email_templates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(64) NOT NULL,
    locale      CHAR(2) NOT NULL DEFAULT 'pl',
    name        VARCHAR(255) NOT NULL,
    subject     VARCHAR(255) NOT NULL,
    body_html   MEDIUMTEXT NOT NULL,
    body_text   MEDIUMTEXT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_templates_code_locale (code, locale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Załączniki (pliki w storage/attachments, poza zasięgiem przeglądarki)
CREATE TABLE attachments (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name        VARCHAR(255) NOT NULL,
    stored_name          VARCHAR(255) NOT NULL,
    mime_type            VARCHAR(127) NOT NULL,
    size_bytes           INT UNSIGNED NOT NULL,
    sha256               CHAR(64) NOT NULL,
    uploaded_by_user_id  INT UNSIGNED NULL,
    created_at           TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attachments_stored_name (stored_name),
    KEY idx_attachments_user (uploaded_by_user_id),
    CONSTRAINT fk_attachments_user
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_template_attachments (
    template_id    INT UNSIGNED NOT NULL,
    attachment_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (template_id, attachment_id),
    KEY idx_eta_attachment (attachment_id),
    CONSTRAINT fk_eta_template
        FOREIGN KEY (template_id) REFERENCES email_templates(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_eta_attachment
        FOREIGN KEY (attachment_id) REFERENCES attachments(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zaproszenia do odnowienia i przypomnienia — treść zapisana w chwili wysyłki
CREATE TABLE invitations (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    certificate_id    INT UNSIGNED NOT NULL,
    renewal_task_id   INT UNSIGNED NULL,
    template_id       INT UNSIGNED NULL,
    recipient_type    ENUM('beneficiary', 'payer') NOT NULL,
    recipient_email   VARCHAR(255) NOT NULL,
    recipient_name    VARCHAR(255) NULL,
    subject           VARCHAR(255) NOT NULL,
    body_html         MEDIUMTEXT NOT NULL,
    status            ENUM('queued', 'sent', 'failed', 'responded', 'closed') NOT NULL DEFAULT 'queued',
    sent_at           DATETIME NULL,
    last_error        TEXT NULL,
    reminder_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_reminder_at  DATETIME NULL,
    next_reminder_at  DATETIME NULL,
    sent_by_user_id   INT UNSIGNED NULL,
    created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_invitations_certificate (certificate_id),
    KEY idx_invitations_task (renewal_task_id),
    KEY idx_invitations_template (template_id),
    KEY idx_invitations_user (sent_by_user_id),
    KEY idx_invitations_status (status),
    KEY idx_invitations_next_reminder (next_reminder_at),
    CONSTRAINT fk_invitations_certificate
        FOREIGN KEY (certificate_id) REFERENCES certificates(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_invitations_task
        FOREIGN KEY (renewal_task_id) REFERENCES renewal_tasks(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_invitations_template
        FOREIGN KEY (template_id) REFERENCES email_templates(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_invitations_user
        FOREIGN KEY (sent_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invitation_attachments (
    invitation_id  INT UNSIGNED NOT NULL,
    attachment_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (invitation_id, attachment_id),
    KEY idx_ia_attachment (attachment_id),
    CONSTRAINT fk_ia_invitation
        FOREIGN KEY (invitation_id) REFERENCES invitations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ia_attachment
        FOREIGN KEY (attachment_id) REFERENCES attachments(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historia zdarzeń (oś czasu) celowo bez kluczy obcych: ma przetrwać archiwizację
-- i usunięcie rekordów. Kolumny kontekstu pozwalają zbudować oś czasu z perspektywy
-- certyfikatu, użytkownika certyfikatu albo płatnika jednym zapytaniem.
CREATE TABLE events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     ENUM('certificate', 'beneficiary', 'payer', 'renewal_task', 'invitation', 'email_template', 'attachment', 'user', 'system') NOT NULL,
    entity_id       INT UNSIGNED NULL,
    event_type      VARCHAR(64) NOT NULL,
    user_id         INT UNSIGNED NULL,
    certificate_id  INT UNSIGNED NULL,
    beneficiary_id  INT UNSIGNED NULL,
    payer_id        INT UNSIGNED NULL,
    payload         JSON NULL,
    occurred_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_events_entity (entity_type, entity_id, occurred_at),
    KEY idx_events_certificate (certificate_id, occurred_at),
    KEY idx_events_beneficiary (beneficiary_id, occurred_at),
    KEY idx_events_payer (payer_id, occurred_at),
    KEY idx_events_occurred (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ustawienia procesu odnowień zmieniane przez administratora (Etap 3).
-- Wartości domyślne są w kodzie (App\Settings), tabela przechowuje tylko zmiany.
CREATE TABLE settings (
    setting_key         VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value       VARCHAR(255) NOT NULL,
    updated_by_user_id  INT UNSIGNED NULL,
    updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_settings_user (updated_by_user_id),
    CONSTRAINT fk_settings_user
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

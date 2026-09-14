-- ============================================================
-- Certificate & Subscription Management Assistant (MVP)
-- Database schema and demo data
-- ============================================================

CREATE DATABASE IF NOT EXISTS assistent_subscriptions
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE assistent_subscriptions;

DROP TABLE IF EXISTS login_otps;
DROP TABLE IF EXISTS manager_subskrypcji;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS payers;

CREATE TABLE users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name  VARCHAR(100) NOT NULL,
    last_name   VARCHAR(100) NOT NULL,
    role        ENUM('ADMIN', 'MANAGER', 'OPERATOR') NOT NULL DEFAULT 'OPERATOR',
    email       VARCHAR(255) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_otps (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    email      VARCHAR(255) NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_login_otps_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_login_otps_email_expires (email, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE payers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name    VARCHAR(255) NOT NULL,
    contact_person  VARCHAR(200) NOT NULL,
    tax_id          VARCHAR(20) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(255) NOT NULL,
    scope               ENUM('corporate', 'personal') NOT NULL DEFAULT 'corporate',
    subscription_type   ENUM(
        'SSL_CERTIFICATE', 'SAAS', 'DOMAIN', 'CLOUD_SUPPORT', 'CODE_SIGNING',
        'STREAMING', 'MUSIC', 'GAMING', 'FITNESS', 'CLOUD_STORAGE', 'OTHER'
    ) NOT NULL DEFAULT 'OTHER',
    expiry_date         DATE NOT NULL,
    user_id             INT UNSIGNED NOT NULL,
    payer_id            INT UNSIGNED NOT NULL,
    status              ENUM('pending', 'active', 'renewal_in_progress', 'expired') NOT NULL DEFAULT 'pending',
    annual_cost         DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    billing_cycle       ENUM('monthly', 'annual', 'multi_year') NOT NULL DEFAULT 'annual',
    currency            CHAR(3) NOT NULL DEFAULT 'PLN',
    payment_status      ENUM('paid', 'due_soon', 'overdue', 'not_applicable') NOT NULL DEFAULT 'due_soon',
    last_payment_date   DATE NULL,
    auto_renew          TINYINT(1) NOT NULL DEFAULT 1,
    notes               TEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subscriptions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_subscriptions_payer
        FOREIGN KEY (payer_id) REFERENCES payers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_scope (scope),
    INDEX idx_expiry_date (expiry_date),
    INDEX idx_status (status),
    INDEX idx_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fresh installs start empty — add users via registration and subscriptions via the dashboard.

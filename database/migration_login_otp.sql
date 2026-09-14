-- Migration: add email OTP login (run on existing databases)
USE assistent_subscriptions;

ALTER TABLE users ADD UNIQUE KEY uq_users_email (email);

CREATE TABLE IF NOT EXISTS login_otps (
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

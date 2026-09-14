-- Tabela własnych subskrypcji użytkownika (manager)
USE assistent_subscriptions;

CREATE TABLE IF NOT EXISTS manager_subskrypcji (
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

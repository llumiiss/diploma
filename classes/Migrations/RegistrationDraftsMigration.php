<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 10: wnioski o certyfikat przesłane e-mailem.
 *
 * registration_drafts to kolejka robocza operatora: wiadomość z wnioskiem (przesłana plikiem, odebrana ze
 * skrzynki IMAP, przesłana webhookiem albo potokiem z serwera pocztowego) zamieniona na gotowy do sprawdzenia
 * formularz — dane użytkownika, firmy (z Białej Listy po NIP) i certyfikatu. Operator poprawia, co trzeba,
 * i zatwierdza jednym przyciskiem: powstaje firma (jeśli jej nie było), użytkownik certyfikatu i certyfikat.
 *
 *   extracted       — to, co ekstraktor wyczytał z wiadomości (do porównania z formularzem),
 *   form            — bieżący formularz edytowany przez operatora,
 *   company_lookup  — odpowiedź rejestru (status found | not_found | error),
 *   warnings        — wątpliwości do wyjaśnienia (np. NIP niepoprawny, firma w archiwum),
 *   matched_*       — rekordy już istniejące w ewidencji (po NIP-ie i adresie e-mail),
 *   result_*        — rekordy utworzone albo użyte przy zatwierdzeniu.
 * Message-ID i skrót treści (unikalne) chronią przed podwójnym wczytaniem tej samej wiadomości.
 *
 * Migracja jest idempotentna.
 */
final class RegistrationDraftsMigration
{
    public static function up(PDO $db): void
    {
        if (SchemaInspector::tableExists($db, 'registration_drafts')) {
            return;
        }

        $db->exec(
            "CREATE TABLE registration_drafts (
                id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                status                 ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                source                 ENUM('upload', 'imap', 'webhook', 'cli') NOT NULL DEFAULT 'upload',
                message_id             VARCHAR(255) NULL,
                content_hash           CHAR(64) NOT NULL,
                sender_email           VARCHAR(255) NULL,
                sender_name            VARCHAR(255) NULL,
                subject                VARCHAR(255) NULL,
                received_at            DATETIME NULL,
                body_text              MEDIUMTEXT NULL,
                raw_file               VARCHAR(64) NULL,
                extracted              JSON NOT NULL,
                form                   JSON NOT NULL,
                company_lookup         JSON NULL,
                warnings               JSON NULL,
                matched_payer_id       INT UNSIGNED NULL,
                matched_beneficiary_id INT UNSIGNED NULL,
                assigned_user_id       INT UNSIGNED NULL,
                created_by_user_id     INT UNSIGNED NULL,
                reviewed_by_user_id    INT UNSIGNED NULL,
                reviewed_at            DATETIME NULL,
                rejection_reason       VARCHAR(500) NULL,
                result_payer_id        INT UNSIGNED NULL,
                result_beneficiary_id  INT UNSIGNED NULL,
                result_certificate_id  INT UNSIGNED NULL,
                created_at             DATETIME NOT NULL,
                updated_at             TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_registration_drafts_message (message_id),
                UNIQUE KEY uq_registration_drafts_hash (content_hash),
                KEY idx_registration_drafts_status (status, created_at),
                KEY idx_registration_drafts_matched_payer (matched_payer_id),
                KEY idx_registration_drafts_matched_beneficiary (matched_beneficiary_id),
                KEY idx_registration_drafts_assigned (assigned_user_id),
                KEY idx_registration_drafts_created_by (created_by_user_id),
                KEY idx_registration_drafts_reviewed_by (reviewed_by_user_id),
                KEY idx_registration_drafts_result_payer (result_payer_id),
                KEY idx_registration_drafts_result_beneficiary (result_beneficiary_id),
                KEY idx_registration_drafts_result_certificate (result_certificate_id),
                CONSTRAINT fk_registration_drafts_matched_payer
                    FOREIGN KEY (matched_payer_id) REFERENCES payers(id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_registration_drafts_matched_beneficiary
                    FOREIGN KEY (matched_beneficiary_id) REFERENCES beneficiaries(id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_registration_drafts_assigned
                    FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_registration_drafts_created_by
                    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_registration_drafts_reviewed_by
                    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_registration_drafts_result_payer
                    FOREIGN KEY (result_payer_id) REFERENCES payers(id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_registration_drafts_result_beneficiary
                    FOREIGN KEY (result_beneficiary_id) REFERENCES beneficiaries(id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_registration_drafts_result_certificate
                    FOREIGN KEY (result_certificate_id) REFERENCES certificates(id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

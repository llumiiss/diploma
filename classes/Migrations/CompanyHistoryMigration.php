<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 12: historia firm osoby i historia płatników certyfikatu.
 *
 * beneficiary_employments — okresy, w których osoba (użytkownik certyfikatu) była związana z firmą.
 * Osoba może być w kilku firmach naraz; dla jednej pary osoba–firma najwyżej jeden okres jest otwarty
 * (open_marker = 1 przy ended_on IS NULL, unikalny indeks jak w renewal_tasks). beneficiaries.payer_id
 * zostaje jako firma główna, wyświetlana na listach.
 *
 * certificate_payers — kto i kiedy opłacał certyfikat. Płatnik może być inną firmą niż pracodawca
 * i zmieniać się z roku na rok; certificates.payer_id to płatnik bieżący (jedyny otwarty okres).
 *
 * Przy pierwszym utworzeniu tabel migracja odtwarza okresy z istniejących danych: firma główna osoby
 * od dnia dodania rekordu (do dnia archiwizacji), dawne firmy z historii zmian w zdarzeniach, a dla
 * certyfikatu bieżący płatnik od początku ważności. Niczego nie usuwa. Migracja jest idempotentna.
 */
final class CompanyHistoryMigration
{
    public static function up(PDO $db): void
    {
        if (!SchemaInspector::tableExists($db, 'beneficiary_employments')) {
            $db->exec(
                'CREATE TABLE beneficiary_employments (
                    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    beneficiary_id      INT UNSIGNED NOT NULL,
                    payer_id            INT UNSIGNED NOT NULL,
                    started_on          DATE NOT NULL,
                    ended_on            DATE NULL,
                    note                VARCHAR(500) NULL,
                    created_by_user_id  INT UNSIGNED NULL,
                    created_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    open_marker         TINYINT UNSIGNED AS (IF(ended_on IS NULL, 1, NULL)) STORED,
                    UNIQUE KEY uq_beneficiary_employments_open (beneficiary_id, payer_id, open_marker),
                    KEY idx_beneficiary_employments_payer (payer_id),
                    KEY idx_beneficiary_employments_dates (started_on, ended_on),
                    KEY idx_beneficiary_employments_created_by (created_by_user_id),
                    CONSTRAINT fk_beneficiary_employments_beneficiary
                        FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_beneficiary_employments_payer
                        FOREIGN KEY (payer_id) REFERENCES payers(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_beneficiary_employments_created_by
                        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            self::backfillEmployments($db);
        }

        if (!SchemaInspector::tableExists($db, 'certificate_payers')) {
            $db->exec(
                'CREATE TABLE certificate_payers (
                    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    certificate_id      INT UNSIGNED NOT NULL,
                    payer_id            INT UNSIGNED NOT NULL,
                    started_on          DATE NOT NULL,
                    ended_on            DATE NULL,
                    note                VARCHAR(500) NULL,
                    created_by_user_id  INT UNSIGNED NULL,
                    created_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    open_marker         TINYINT UNSIGNED AS (IF(ended_on IS NULL, 1, NULL)) STORED,
                    UNIQUE KEY uq_certificate_payers_open (certificate_id, open_marker),
                    KEY idx_certificate_payers_payer (payer_id),
                    KEY idx_certificate_payers_created_by (created_by_user_id),
                    CONSTRAINT fk_certificate_payers_certificate
                        FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_certificate_payers_payer
                        FOREIGN KEY (payer_id) REFERENCES payers(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_certificate_payers_created_by
                        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            self::backfillCertificatePayers($db);
        }
    }

    private static function backfillEmployments(PDO $db): void
    {
        // Firma główna: od dnia dodania osoby; osoba w archiwum ma okres zamknięty dniem archiwizacji.
        $db->exec(
            'INSERT INTO beneficiary_employments (beneficiary_id, payer_id, started_on, ended_on, note, created_by_user_id)
             SELECT b.id, b.payer_id, DATE(COALESCE(b.created_at, NOW())),
                    IF(b.archived_at IS NULL, NULL, GREATEST(DATE(b.archived_at), DATE(COALESCE(b.created_at, b.archived_at)))),
                    NULL, b.created_by_user_id
             FROM beneficiaries b'
        );

        // Dawne firmy z historii edycji: zmiana pola payer_id w zdarzeniu „beneficiary updated”.
        $stmt = $db->query(
            "SELECT entity_id, occurred_at, payload FROM events
             WHERE entity_type = 'beneficiary' AND event_type = 'updated' AND payload LIKE '%payer_id%'
             ORDER BY occurred_at, id"
        );
        if ($stmt === false) {
            return;
        }
        $insert = $db->prepare(
            'INSERT INTO beneficiary_employments (beneficiary_id, payer_id, started_on, ended_on, note)
             SELECT b.id, p.id, DATE(COALESCE(b.created_at, :occurred_a)), DATE(:occurred_b), :note
             FROM beneficiaries b INNER JOIN payers p ON p.id = :payer_id
             WHERE b.id = :beneficiary_id AND b.payer_id <> p.id
               AND NOT EXISTS (SELECT 1 FROM beneficiary_employments e
                               WHERE e.beneficiary_id = b.id AND e.payer_id = p.id)'
        );
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $from = is_array($payload) ? ($payload['changes']['payer_id']['from'] ?? null) : null;
            if (!is_numeric($from)) {
                continue;
            }
            $insert->execute([
                'occurred_a'     => $row['occurred_at'],
                'occurred_b'     => $row['occurred_at'],
                'note'           => 'migracja: zmiana firmy w historii zdarzeń',
                'payer_id'       => (int) $from,
                'beneficiary_id' => (int) $row['entity_id'],
            ]);
        }
    }

    private static function backfillCertificatePayers(PDO $db): void
    {
        $db->exec(
            'INSERT INTO certificate_payers (certificate_id, payer_id, started_on, ended_on)
             SELECT c.id, c.payer_id,
                    LEAST(COALESCE(c.valid_from, DATE(c.created_at)), DATE(COALESCE(c.created_at, NOW()))),
                    IF(c.archived_at IS NULL, NULL, DATE(c.archived_at))
             FROM certificates c'
        );
    }
}

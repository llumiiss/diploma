<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 8: rozdzielenie aplikacji.
 *
 * Panel prywatny wyprowadziliśmy do własnej aplikacji (katalog menedzer_subskrypcji, własna baza).
 * Ta migracja usuwa z ewidencji firmowej wszystko, co należało do panelu prywatnego, żeby jedna
 * baza opisywała jedną dziedzinę:
 *
 *   - certyfikaty o zakresie „personal” razem z ich historią w events,
 *   - płatników używanych wyłącznie przez takie certyfikaty (np. „Budżet domowy”),
 *   - tabelę manager_subskrypcji,
 *   - kolumnę certificates.scope wraz z indeksem,
 *   - typy certyfikatów, które istniały tylko dla subskrypcji prywatnych.
 *
 * Dane prywatne przenosi do nowej aplikacji skrypt menedzer_subskrypcji/scripts/import-from-certisub.php,
 * który tylko czyta tę bazę. Uruchom go PRZED migracją — po niej rekordów już tutaj nie ma.
 *
 * Migracja jest idempotentna: każdy krok sprawdza, czy jest jeszcze co robić.
 */
final class SplitPersonalAppMigration
{
    /** Typy certyfikatów używane wyłącznie przez panel prywatny. */
    private const PERSONAL_TYPES = ['STREAMING', 'MUSIC', 'GAMING', 'FITNESS', 'CLOUD_STORAGE'];

    /** Typy zostające w ewidencji firmowej. */
    private const CORPORATE_TYPES = [
        'QUALIFIED_SIGNATURE', 'QUALIFIED_SEAL', 'SSL_CERTIFICATE', 'CODE_SIGNING',
        'DOMAIN', 'SAAS', 'CLOUD_SUPPORT', 'OTHER',
    ];

    public static function up(PDO $db): void
    {
        if (SchemaInspector::columnExists($db, 'certificates', 'scope')) {
            self::removePersonalRecords($db);

            if (SchemaInspector::indexExists($db, 'certificates', 'idx_scope')) {
                $db->exec('ALTER TABLE certificates DROP INDEX idx_scope');
            }

            $db->exec('ALTER TABLE certificates DROP COLUMN scope');
        }

        if (SchemaInspector::tableExists($db, 'manager_subskrypcji')) {
            $db->exec('DROP TABLE manager_subskrypcji');
        }

        self::narrowCertificateTypes($db);
    }

    /**
     * Usuwa rekordy panelu prywatnego. Kolejność wynika z kluczy obcych i z tego,
     * że events nie ma kluczy obcych (historia zostałaby po usuniętym certyfikacie).
     */
    private static function removePersonalRecords(PDO $db): void
    {
        // Płatnicy obsługujący wyłącznie subskrypcje prywatne — zapisujemy ich, zanim
        // znikną certyfikaty, bo po usunięciu nie da się już ich rozpoznać.
        $personalOnlyPayers = $db->query(
            "SELECT p.id FROM payers p
             WHERE EXISTS (SELECT 1 FROM certificates c WHERE c.payer_id = p.id AND c.scope = 'personal')
               AND NOT EXISTS (SELECT 1 FROM certificates c WHERE c.payer_id = p.id AND c.scope = 'corporate')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $db->exec(
            "DELETE FROM events
             WHERE certificate_id IN (SELECT id FROM certificates WHERE scope = 'personal')"
        );
        $db->exec("DELETE FROM certificates WHERE scope = 'personal'");

        foreach ($personalOnlyPayers as $payerId) {
            $payerId = (int) $payerId;

            $stillUsed = $db->prepare('SELECT 1 FROM certificates WHERE payer_id = :id LIMIT 1');
            $stillUsed->execute(['id' => $payerId]);
            if ($stillUsed->fetchColumn() !== false) {
                continue;
            }

            $db->prepare('DELETE FROM events WHERE payer_id = :id')->execute(['id' => $payerId]);
            $db->prepare('UPDATE beneficiaries SET payer_id = NULL WHERE payer_id = :id')->execute(['id' => $payerId]);
            $db->prepare('DELETE FROM payers WHERE id = :id')->execute(['id' => $payerId]);
        }
    }

    /**
     * Zawęża słownik typów certyfikatów do firmowych. Wykonalne dopiero wtedy,
     * gdy żaden rekord nie używa już typu prywatnego.
     */
    private static function narrowCertificateTypes(PDO $db): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::PERSONAL_TYPES), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM certificates WHERE certificate_type IN ({$placeholders})");
        $stmt->execute(self::PERSONAL_TYPES);

        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $enum = "'" . implode("', '", self::CORPORATE_TYPES) . "'";
        $db->exec(
            "ALTER TABLE certificates
             MODIFY COLUMN certificate_type ENUM({$enum}) NOT NULL DEFAULT 'OTHER'"
        );
    }
}

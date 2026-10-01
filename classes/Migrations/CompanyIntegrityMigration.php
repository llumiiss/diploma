<?php

declare(strict_types=1);

namespace App\Migrations;

use PDO;

/**
 * Etap 10: spójność relacji firma → użytkownik certyfikatu → certyfikat kwalifikowany.
 *
 *   - użytkownik certyfikatu (beneficiaries) nie istnieje bez firmy: payer_id staje się NOT NULL
 *     (firmą jest rekord z tabeli payers — płatnik, którego dane są na karcie firmy),
 *   - certyfikat kwalifikowany (podpis i pieczęć) nie istnieje bez użytkownika: ograniczenie CHECK
 *     wymaga beneficiary_id dla typów QUALIFIED_SIGNATURE i QUALIFIED_SEAL.
 *
 * Dane sprzed migracji też muszą spełnić regułę, więc migracja je uzupełnia:
 *   - osoba bez firmy dostaje firmę z jej najnowszego certyfikatu, a gdy certyfikatów nie ma —
 *     wspólną firmę „Firma do uzupełnienia”,
 *   - certyfikat kwalifikowany bez osoby dostaje osobę „Użytkownik do uzupełnienia” z firmą certyfikatu.
 * Oba rodzaje rekordów mają w notatce adnotację o uzupełnieniu i trafiają do poprawy przez operatora.
 *
 * Ograniczenie CHECK nie może dotyczyć kolumny z akcją referencyjną CASCADE (błąd MySQL 3823), dlatego
 * klucz certificates.beneficiary_id dostaje ON UPDATE RESTRICT — identyfikatory i tak się nie zmieniają.
 *
 * Migracja jest idempotentna: każdy krok sprawdza, czy jest jeszcze co robić.
 */
final class CompanyIntegrityMigration
{
    public const PLACEHOLDER_COMPANY = 'Firma do uzupełnienia';
    public const PLACEHOLDER_PERSON = 'Użytkownik do uzupełnienia';

    /** Typy certyfikatów, które muszą mieć użytkownika. */
    public const QUALIFIED_TYPES = ['QUALIFIED_SIGNATURE', 'QUALIFIED_SEAL'];

    public static function up(PDO $db): void
    {
        self::assignCompanyToPeople($db);
        self::assignPersonToQualifiedCertificates($db);
        self::guardQualifiedCertificates($db);
    }

    private static function assignCompanyToPeople(PDO $db): void
    {
        $db->exec(
            'UPDATE beneficiaries b
                SET b.payer_id = (
                    SELECT c.payer_id FROM certificates c
                     WHERE c.beneficiary_id = b.id
                     ORDER BY c.archived_at IS NOT NULL, c.id DESC
                     LIMIT 1)
              WHERE b.payer_id IS NULL'
        );

        $orphans = (int) $db->query('SELECT COUNT(*) FROM beneficiaries WHERE payer_id IS NULL')->fetchColumn();
        if ($orphans > 0) {
            $db->prepare(
                'UPDATE beneficiaries SET payer_id = :payer_id,
                        notes = CONCAT_WS(CHAR(10), notes, :note)
                  WHERE payer_id IS NULL'
            )->execute([
                'payer_id' => self::placeholderCompany($db),
                'note'     => 'Firma przypisana automatycznie przy migracji — uzupełnij dane firmy.',
            ]);
        }

        if (SchemaInspector::columnIsNullable($db, 'beneficiaries', 'payer_id')) {
            $db->exec('ALTER TABLE beneficiaries MODIFY COLUMN payer_id INT UNSIGNED NOT NULL');
        }
    }

    private static function assignPersonToQualifiedCertificates(PDO $db): void
    {
        $types = "'" . implode("', '", self::QUALIFIED_TYPES) . "'";
        $stmt = $db->query(
            "SELECT id, payer_id FROM certificates
              WHERE beneficiary_id IS NULL AND certificate_type IN ({$types})"
        );
        $certificates = $stmt !== false ? $stmt->fetchAll() : [];

        $insert = $db->prepare(
            'INSERT INTO beneficiaries (first_name, last_name, payer_id, notes)
             VALUES (:first_name, :last_name, :payer_id, :notes)'
        );
        $link = $db->prepare('UPDATE certificates SET beneficiary_id = :beneficiary_id WHERE id = :id');

        foreach ($certificates as $certificate) {
            $insert->execute([
                'first_name' => 'Użytkownik',
                'last_name'  => 'do uzupełnienia',
                'payer_id'   => (int) $certificate['payer_id'],
                'notes'      => 'Osoba dodana automatycznie przy migracji dla certyfikatu #' . (int) $certificate['id']
                    . ' — wpisz rzeczywiste dane użytkownika certyfikatu.',
            ]);
            $link->execute(['beneficiary_id' => (int) $db->lastInsertId(), 'id' => (int) $certificate['id']]);
        }
    }

    private static function guardQualifiedCertificates(PDO $db): void
    {
        if (SchemaInspector::foreignKeyUpdateRule($db, 'certificates', 'fk_certificates_beneficiary') !== 'RESTRICT') {
            $db->exec('ALTER TABLE certificates DROP FOREIGN KEY fk_certificates_beneficiary');
            $db->exec(
                'ALTER TABLE certificates ADD CONSTRAINT fk_certificates_beneficiary
                    FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries(id)
                    ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        }

        if (!SchemaInspector::checkConstraintExists($db, 'certificates', 'chk_certificates_qualified_user')) {
            $types = "'" . implode("', '", self::QUALIFIED_TYPES) . "'";
            $db->exec(
                "ALTER TABLE certificates ADD CONSTRAINT chk_certificates_qualified_user
                    CHECK (certificate_type NOT IN ({$types}) OR beneficiary_id IS NOT NULL)"
            );
        }
    }

    private static function placeholderCompany(PDO $db): int
    {
        $stmt = $db->prepare('SELECT id FROM payers WHERE company_name = :name AND tax_id IS NULL ORDER BY id LIMIT 1');
        $stmt->execute(['name' => self::PLACEHOLDER_COMPANY]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $db->prepare('INSERT INTO payers (company_name, contact_person) VALUES (:name, :contact)')
            ->execute(['name' => self::PLACEHOLDER_COMPANY, 'contact' => '—']);

        return (int) $db->lastInsertId();
    }
}

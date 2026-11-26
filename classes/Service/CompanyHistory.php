<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Zapis i odczyt okresów z historii firm (Etap 12) — bez sprawdzania uprawnień, które należą do usług
 * wywołujących (BeneficiaryService, CertificateService, PayerService). Wywołujący odpowiada też za transakcję.
 *
 *  - beneficiary_employments: osoba w firmie od–do; kilka firm naraz, najwyżej jeden otwarty okres na parę,
 *  - certificate_payers: płatnik certyfikatu od–do; dokładnie jeden otwarty okres dla bieżącego certyfikatu.
 *
 * Okresy się nie usuwa — kończy się je datą (ended_on). Zmiana w dniu D zamyka stary okres dniem D
 * i otwiera nowy od dnia D.
 */
final class CompanyHistory
{
    public function __construct(private readonly PDO $db)
    {
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    // ---------------------------------------------------------------- osoba ↔ firma

    /**
     * @return list<array<string, mixed>>
     */
    public function employments(int $beneficiaryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.beneficiary_id, e.payer_id, e.started_on, e.ended_on, e.note, e.created_at,
                    p.company_name AS payer_name, p.tax_id AS payer_tax_id, p.archived_at AS payer_archived_at,
                    (b.payer_id = e.payer_id AND e.ended_on IS NULL) AS is_primary,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS created_by_name
             FROM beneficiary_employments e
             INNER JOIN payers p ON p.id = e.payer_id
             INNER JOIN beneficiaries b ON b.id = e.beneficiary_id
             LEFT JOIN users u ON u.id = e.created_by_user_id
             WHERE e.beneficiary_id = :id
             ORDER BY e.ended_on IS NOT NULL, (b.payer_id = e.payer_id) DESC, e.ended_on DESC, e.started_on DESC, e.id DESC'
        );
        $stmt->execute(['id' => $beneficiaryId]);

        return array_map([self::class, 'castEmployment'], $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function employment(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, p.company_name AS payer_name, b.payer_id AS primary_payer_id
             FROM beneficiary_employments e
             INNER JOIN payers p ON p.id = e.payer_id
             INNER JOIN beneficiaries b ON b.id = e.beneficiary_id
             WHERE e.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::castEmployment($row);
    }

    /**
     * Otwarty okres osoby w danej firmie.
     *
     * @return array<string, mixed>|null
     */
    public function openEmployment(int $beneficiaryId, int $payerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM beneficiary_employments
             WHERE beneficiary_id = :beneficiary_id AND payer_id = :payer_id AND ended_on IS NULL LIMIT 1'
        );
        $stmt->execute(['beneficiary_id' => $beneficiaryId, 'payer_id' => $payerId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::castEmployment($row);
    }

    /**
     * @return list<int> firmy, w których osoba ma otwarty okres (najstarszy najpierw)
     */
    public function openPayerIds(int $beneficiaryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT payer_id FROM beneficiary_employments
             WHERE beneficiary_id = :id AND ended_on IS NULL ORDER BY started_on, id'
        );
        $stmt->execute(['id' => $beneficiaryId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function startEmployment(int $beneficiaryId, int $payerId, string $date, ?string $note, ?int $userId): int
    {
        $this->db->prepare(
            'INSERT INTO beneficiary_employments (beneficiary_id, payer_id, started_on, note, created_by_user_id)
             VALUES (:beneficiary_id, :payer_id, :started_on, :note, :user_id)'
        )->execute([
            'beneficiary_id' => $beneficiaryId,
            'payer_id'       => $payerId,
            'started_on'     => $date,
            'note'           => $note,
            'user_id'        => $userId !== null && $userId > 0 ? $userId : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Otwiera okres, jeśli osoba nie ma jeszcze otwartego okresu w tej firmie.
     */
    public function ensureEmployment(int $beneficiaryId, int $payerId, string $date, ?string $note, ?int $userId): void
    {
        if ($this->openEmployment($beneficiaryId, $payerId) === null) {
            $this->startEmployment($beneficiaryId, $payerId, $date, $note, $userId);
        }
    }

    /**
     * Zamyka okres; data końca nie może być wcześniejsza niż początek (wtedy kończy się w dniu rozpoczęcia).
     */
    public function endEmployment(int $employmentId, string $date, ?string $note = null): void
    {
        $this->db->prepare(
            'UPDATE beneficiary_employments
             SET ended_on = GREATEST(started_on, :ended_on), note = COALESCE(:note, note)
             WHERE id = :id AND ended_on IS NULL'
        )->execute(['ended_on' => $date, 'note' => $note, 'id' => $employmentId]);
    }

    public function endAllEmployments(int $beneficiaryId, string $date): void
    {
        $this->db->prepare(
            'UPDATE beneficiary_employments SET ended_on = GREATEST(started_on, :ended_on)
             WHERE beneficiary_id = :id AND ended_on IS NULL'
        )->execute(['ended_on' => $date, 'id' => $beneficiaryId]);
    }

    /**
     * Kończy otwarte okresy wszystkich osób w firmie (archiwizacja firmy).
     *
     * @return int liczba zamkniętych okresów
     */
    public function endEmploymentsAtPayer(int $payerId, string $date): int
    {
        $stmt = $this->db->prepare(
            'UPDATE beneficiary_employments SET ended_on = GREATEST(started_on, :ended_on)
             WHERE payer_id = :id AND ended_on IS NULL'
        );
        $stmt->execute(['ended_on' => $date, 'id' => $payerId]);

        return $stmt->rowCount();
    }

    /**
     * Poprawka pomyłki: firma okresu otwartego zostaje zamieniona (zwykła edycja osoby).
     */
    public function correctOpenEmploymentPayer(int $beneficiaryId, int $fromPayerId, int $toPayerId, ?int $userId): void
    {
        if ($this->openEmployment($beneficiaryId, $toPayerId) !== null) {
            return;
        }

        $open = $this->openEmployment($beneficiaryId, $fromPayerId);
        if ($open === null) {
            $this->startEmployment($beneficiaryId, $toPayerId, self::today(), null, $userId);

            return;
        }

        $this->db->prepare('UPDATE beneficiary_employments SET payer_id = :payer_id WHERE id = :id')
            ->execute(['payer_id' => $toPayerId, 'id' => $open['id']]);
    }

    public function updateEmployment(int $id, string $startedOn, ?string $endedOn, ?string $note): void
    {
        $this->db->prepare(
            'UPDATE beneficiary_employments SET started_on = :started_on, ended_on = :ended_on, note = :note WHERE id = :id'
        )->execute(['started_on' => $startedOn, 'ended_on' => $endedOn, 'note' => $note, 'id' => $id]);
    }

    public function setPrimaryPayer(int $beneficiaryId, int $payerId): void
    {
        $this->db->prepare('UPDATE beneficiaries SET payer_id = :payer_id WHERE id = :id')
            ->execute(['payer_id' => $payerId, 'id' => $beneficiaryId]);
    }

    // ---------------------------------------------------------------- certyfikat ↔ płatnik

    /**
     * @return list<array<string, mixed>>
     */
    public function certificatePayers(int $certificateId): array
    {
        $stmt = $this->db->prepare(
            'SELECT cp.id, cp.payer_id, cp.started_on, cp.ended_on, cp.note, p.company_name AS payer_name, p.tax_id AS payer_tax_id
             FROM certificate_payers cp
             INNER JOIN payers p ON p.id = cp.payer_id
             WHERE cp.certificate_id = :id
             ORDER BY cp.ended_on IS NOT NULL, cp.started_on DESC, cp.id DESC'
        );
        $stmt->execute(['id' => $certificateId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['payer_id'] = (int) $row['payer_id'];

            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * Historia płatników kilku certyfikatów naraz (karty i archiwum).
     *
     * @param list<int> $certificateIds
     * @return array<int, list<array<string, mixed>>> certificate_id => okresy
     */
    public function certificatePayersFor(array $certificateIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $certificateIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':cp' . $i;
            $params['cp' . $i] = $id;
        }
        $stmt = $this->db->prepare(
            'SELECT cp.certificate_id, cp.payer_id, cp.started_on, cp.ended_on, cp.note, p.company_name AS payer_name
             FROM certificate_payers cp
             INNER JOIN payers p ON p.id = cp.payer_id
             WHERE cp.certificate_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY cp.started_on, cp.id'
        );
        $stmt->execute($params);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['certificate_id']][] = [
                'payer_id'   => (int) $row['payer_id'],
                'payer_name' => (string) $row['payer_name'],
                'started_on' => $row['started_on'],
                'ended_on'   => $row['ended_on'],
                'note'       => $row['note'],
            ];
        }

        return $grouped;
    }

    /**
     * Ustawia bieżącego płatnika certyfikatu: zamyka otwarty okres innego płatnika i otwiera nowy.
     * Gdy płatnik się nie zmienił i okres jest otwarty, nic nie robi.
     */
    public function recordCertificatePayer(int $certificateId, int $payerId, string $date, ?string $note, ?int $userId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id, payer_id, started_on FROM certificate_payers WHERE certificate_id = :id AND ended_on IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $certificateId]);
        $open = $stmt->fetch();

        if ($open !== false && (int) $open['payer_id'] === $payerId) {
            return;
        }
        // Zmiana z datą nie późniejszą niż początek bieżącego okresu (np. certyfikat ważny od przyszłego miesiąca):
        // okres od początku należy do nowego płatnika, więc zmienia się go zamiast dopisywać nowy.
        if ($open !== false && $date <= (string) $open['started_on']) {
            $this->db->prepare('UPDATE certificate_payers SET payer_id = :payer_id, note = COALESCE(:note, note) WHERE id = :id')
                ->execute(['payer_id' => $payerId, 'note' => $note, 'id' => (int) $open['id']]);

            return;
        }
        if ($open !== false) {
            $this->db->prepare('UPDATE certificate_payers SET ended_on = GREATEST(started_on, :ended_on) WHERE id = :id')
                ->execute(['ended_on' => $date, 'id' => (int) $open['id']]);
        }

        $this->db->prepare(
            'INSERT INTO certificate_payers (certificate_id, payer_id, started_on, note, created_by_user_id)
             VALUES (:certificate_id, :payer_id, :started_on, :note, :user_id)'
        )->execute([
            'certificate_id' => $certificateId,
            'payer_id'       => $payerId,
            'started_on'     => $date,
            'note'           => $note,
            'user_id'        => $userId !== null && $userId > 0 ? $userId : null,
        ]);
    }

    public function closeCertificatePayer(int $certificateId, string $date): void
    {
        $this->db->prepare(
            'UPDATE certificate_payers SET ended_on = GREATEST(started_on, :ended_on)
             WHERE certificate_id = :id AND ended_on IS NULL'
        )->execute(['ended_on' => $date, 'id' => $certificateId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castEmployment(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['beneficiary_id'] = (int) $row['beneficiary_id'];
        $row['payer_id'] = (int) $row['payer_id'];
        if (array_key_exists('is_primary', $row)) {
            $row['is_primary'] = (bool) $row['is_primary'];
        }
        if (array_key_exists('primary_payer_id', $row)) {
            $row['primary_payer_id'] = (int) $row['primary_payer_id'];
        }
        unset($row['open_marker']);
        $row['is_current'] = $row['ended_on'] === null;

        return $row;
    }
}

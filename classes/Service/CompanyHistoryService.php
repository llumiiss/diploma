<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Archiwum — historia firm (Etap 12): kto, w której firmie i kiedy (od–do), z certyfikatami osoby
 * i ich płatnikami. Wyszukiwanie po osobie, e-mailu, firmie, NIP-ie, numerze seryjnym i nazwie
 * certyfikatu; filtr dat wybiera okresy, które zachodzą na podany przedział.
 *
 * Zakres danych jak przy osobach (D8): konto widzi okresy osób, które widzi. Archiwum pokazuje także
 * osoby zarchiwizowane i okresy zakończone, więc wymaga uprawnienia archive.view.
 */
final class CompanyHistoryService
{
    public const STATES = ['all', 'current', 'past'];
    private const LIMIT = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $filters q, from, to, state (all|current|past), payer_id, beneficiary_id
     * @return array{items: list<array<string, mixed>>, total: int, limit: int}
     */
    public function search(Actor $actor, array $filters = []): array
    {
        $actor->authorize('archive.view');

        $v = new Validator($filters);
        $from = $v->date('from', false);
        $to = $v->date('to', false);
        $state = (string) $v->enum('state', false, self::STATES, 'all');
        $payerId = $v->id('payer_id', false);
        $beneficiaryId = $v->id('beneficiary_id', false);
        if ($from !== null && $to !== null && $to < $from) {
            $v->addError('to', 'validation.date_order');
        }
        $v->throwIfFailed();

        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
        $conditions = [$visibility];

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $digits = preg_replace('/\D+/', '', $query) ?? '';
            $digitsLike = strlen($digits) >= 3 ? '%' . $digits . '%' : $like;
            $conditions[] = "(CONCAT_WS(' ', b.first_name, b.last_name) LIKE :q_name1
                OR CONCAT_WS(' ', b.last_name, b.first_name) LIKE :q_name2
                OR b.email LIKE :q_email OR p.company_name LIKE :q_company OR p.tax_id LIKE :q_tax
                OR EXISTS (SELECT 1 FROM certificates qc WHERE qc.beneficiary_id = b.id
                           AND (qc.serial_number LIKE :q_serial OR qc.name LIKE :q_cert)))";
            $params += [
                'q_name1'   => $like,
                'q_name2'   => $like,
                'q_email'   => $like,
                'q_company' => $like,
                'q_tax'     => $digitsLike,
                'q_serial'  => $like,
                'q_cert'    => $like,
            ];
        }
        if ($from !== null) {
            $conditions[] = '(e.ended_on IS NULL OR e.ended_on >= :from_date)';
            $params['from_date'] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'e.started_on <= :to_date';
            $params['to_date'] = $to;
        }
        if ($state === 'current') {
            $conditions[] = 'e.ended_on IS NULL';
        } elseif ($state === 'past') {
            $conditions[] = 'e.ended_on IS NOT NULL';
        }
        if ($payerId !== null) {
            $conditions[] = 'e.payer_id = :payer_id';
            $params['payer_id'] = $payerId;
        }
        if ($beneficiaryId !== null) {
            $conditions[] = 'e.beneficiary_id = :beneficiary_id';
            $params['beneficiary_id'] = $beneficiaryId;
        }

        $base = 'FROM beneficiary_employments e
                 INNER JOIN beneficiaries b ON b.id = e.beneficiary_id
                 INNER JOIN payers p ON p.id = e.payer_id
                 WHERE ' . implode(' AND ', $conditions);

        $count = $this->db->prepare('SELECT COUNT(*) ' . $base);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT e.id, e.beneficiary_id, e.payer_id, e.started_on, e.ended_on, e.note,
                    b.first_name, b.last_name, b.email, b.archived_at AS beneficiary_archived_at,
                    (b.payer_id = e.payer_id AND e.ended_on IS NULL) AS is_primary,
                    p.company_name, p.tax_id, p.archived_at AS payer_archived_at
             ' . $base . '
             ORDER BY b.last_name, b.first_name, e.started_on DESC, e.id DESC
             LIMIT ' . self::LIMIT
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $certificates = $this->certificatesFor($actor, array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['beneficiary_id'],
            $rows
        ))));

        $items = array_map(static function (array $row) use ($certificates): array {
            $payerId = (int) $row['payer_id'];
            $start = (string) $row['started_on'];
            $end = $row['ended_on'] !== null ? (string) $row['ended_on'] : null;
            $own = array_map(static function (array $certificate) use ($payerId, $start, $end): array {
                // Czy ta firma płaciła za certyfikat w czasie, gdy osoba w niej była.
                $certificate['paid_by_company'] = false;
                foreach ($certificate['payers'] as $period) {
                    $overlaps = $period['payer_id'] === $payerId
                        && ($end === null || (string) $period['started_on'] <= $end)
                        && ($period['ended_on'] === null || (string) $period['ended_on'] >= $start);
                    if ($overlaps) {
                        $certificate['paid_by_company'] = true;
                        break;
                    }
                }

                return $certificate;
            }, $certificates[(int) $row['beneficiary_id']] ?? []);

            return [
                'id'                      => (int) $row['id'],
                'beneficiary_id'          => (int) $row['beneficiary_id'],
                'beneficiary_name'        => trim($row['first_name'] . ' ' . $row['last_name']),
                'email'                   => $row['email'],
                'beneficiary_archived_at' => $row['beneficiary_archived_at'],
                'payer_id'                => $payerId,
                'company_name'            => (string) $row['company_name'],
                'tax_id'                  => $row['tax_id'],
                'payer_archived_at'       => $row['payer_archived_at'],
                'started_on'              => $start,
                'ended_on'                => $end,
                'is_current'              => $end === null,
                'is_primary'              => (bool) $row['is_primary'],
                'note'                    => $row['note'],
                'certificates'            => $own,
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total, 'limit' => self::LIMIT];
    }

    /**
     * Wiersze do eksportu CSV/XML (jedna linia na okres, certyfikaty jako tekst).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function exportRows(Actor $actor, array $filters = []): array
    {
        return array_map(static fn (array $item): array => [
            'beneficiary_name' => $item['beneficiary_name'],
            'email'            => $item['email'],
            'company_name'     => $item['company_name'],
            'tax_id'           => $item['tax_id'],
            'started_on'       => $item['started_on'],
            'ended_on'         => $item['ended_on'],
            'is_primary'       => $item['is_primary'] ? 1 : 0,
            'note'             => $item['note'],
            'certificates'     => implode('; ', array_map(
                static fn (array $c): string => $c['name']
                    . ($c['serial_number'] !== null ? ' [' . $c['serial_number'] . ']' : '')
                    . ' ' . $c['expiry_date']
                    . ' (' . implode(', ', array_map(
                        static fn (array $p): string => $p['payer_name'] . ' ' . $p['started_on'] . '–' . ($p['ended_on'] ?? ''),
                        $c['payers']
                    )) . ')',
                $item['certificates']
            )),
        ], $this->search($actor, $filters)['items']);
    }

    /**
     * Certyfikaty osób (bieżące i archiwalne) widoczne dla konta, z historią płatników.
     *
     * @param list<int> $beneficiaryIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function certificatesFor(Actor $actor, array $beneficiaryIds): array
    {
        if ($beneficiaryIds === []) {
            return [];
        }

        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $names = [];
        foreach ($beneficiaryIds as $i => $id) {
            $names[] = ':hb' . $i;
            $params['hb' . $i] = $id;
        }
        $stmt = $this->db->prepare(
            'SELECT c.id, c.beneficiary_id, c.name, c.certificate_type, c.serial_number, c.valid_from, c.expiry_date,
                    c.archived_at, c.payer_id
             FROM certificates c
             WHERE c.beneficiary_id IN (' . implode(', ', $names) . ") AND {$visibility}
             ORDER BY c.expiry_date DESC, c.id DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $payers = (new CompanyHistory($this->db))->certificatePayersFor(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $grouped = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $grouped[(int) $row['beneficiary_id']][] = [
                'id'               => $id,
                'name'             => (string) $row['name'],
                'certificate_type' => $row['certificate_type'],
                'serial_number'    => $row['serial_number'],
                'valid_from'       => $row['valid_from'],
                'expiry_date'      => $row['expiry_date'],
                'archived_at'      => $row['archived_at'],
                'payer_id'         => (int) $row['payer_id'],
                'payers'           => $payers[$id] ?? [],
            ];
        }

        return $grouped;
    }
}

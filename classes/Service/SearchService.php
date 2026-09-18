<?php

declare(strict_types=1);

namespace App\Service;

use App\CertificateHelper;
use App\Settings;
use DateTimeImmutable;
use PDO;

/**
 * Wyszukiwarka globalna (F16): certyfikaty, użytkownicy certyfikatów i płatnicy wraz z powiązaniami.
 *
 * Rekord trafia do wyników, gdy pasują jego własne pola (np. numer seryjny, e-mail, NIP) albo
 * powiązany rekord — wpisanie nazwy płatnika pokaże też jego certyfikaty i osoby, a nazwisko
 * opiekuna z personelu — certyfikaty, które prowadzi. Każdy wynik ma listę dopasowań (`matched`),
 * więc widać, dlaczego się pojawił. Dopasowania bezpośrednie są wyżej niż te przez powiązania.
 *
 * Zakres danych jak na listach (D8). Rekordy z archiwum wyszukują tylko role z dostępem do archiwum.
 */
final class SearchService
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 100;
    public const DEFAULT_LIMIT = 6;
    public const MAX_LIMIT = 50;

    /** Numer telefonu bez separatorów — porównywany z cyframi zapytania. */
    private const PHONE_DIGITS = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(%s, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{query: string, too_short: bool, groups: array<string, array{items: list<array<string, mixed>>, total: int}>}
     */
    public function search(Actor $actor, string $query, int $limit = self::DEFAULT_LIMIT, ?DateTimeImmutable $today = null): array
    {
        $actor->authorize('search.use');

        $query = self::normalize($query);
        if (mb_strlen($query) < self::MIN_LENGTH) {
            return [
                'query'     => $query,
                'too_short' => true,
                'groups'    => [
                    'certificates'  => ['items' => [], 'total' => 0],
                    'beneficiaries' => ['items' => [], 'total' => 0],
                    'payers'        => ['items' => [], 'total' => 0],
                ],
            ];
        }

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $digits = preg_replace('/\D+/', '', $query) ?? '';
        // Numer (NIP, telefon) porównywany bez separatorów; krótkie ciągi cyfr pasowałyby do wszystkiego.
        $digitsLike = strlen($digits) >= 3 ? '%' . $digits . '%' : $like;
        $today = $today ?? new DateTimeImmutable((string) $this->db->query('SELECT CURDATE()')->fetchColumn());

        return [
            'query'     => $query,
            'too_short' => false,
            'groups'    => [
                'certificates'  => $this->certificates($actor, $like, $digitsLike, $limit, $today),
                'beneficiaries' => $this->beneficiaries($actor, $like, $digitsLike, $limit),
                'payers'        => $this->payers($actor, $like, $digitsLike, $limit),
            ],
        ];
    }

    public static function normalize(string $query): string
    {
        $query = trim((string) preg_replace('/\s+/u', ' ', $query));

        return mb_substr($query, 0, self::MAX_LENGTH);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function certificates(Actor $actor, string $like, string $digitsLike, int $limit, DateTimeImmutable $today): array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $archived = $actor->can('archive.view') ? '' : ' AND c.archived_at IS NULL';

        $sql = "SELECT c.id, c.name, c.certificate_type, c.serial_number, c.issuer, c.expiry_date, c.status, c.archived_at,
                       c.beneficiary_id, b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name,
                       c.payer_id, p.company_name AS payer_name,
                       (c.name LIKE :c_name) AS m_name,
                       (c.serial_number LIKE :c_serial) AS m_serial_number,
                       (c.issuer LIKE :c_issuer) AS m_issuer,
                       (CONCAT_WS(' ', b.first_name, b.last_name) LIKE :c_person1
                           OR CONCAT_WS(' ', b.last_name, b.first_name) LIKE :c_person2) AS m_beneficiary,
                       (p.company_name LIKE :c_payer OR p.tax_id LIKE :c_tax) AS m_payer,
                       (CONCAT_WS(' ', u.first_name, u.last_name) LIKE :c_owner1
                           OR CONCAT_WS(' ', u.last_name, u.first_name) LIKE :c_owner2) AS m_owner,
                       u.first_name AS owner_first_name, u.last_name AS owner_last_name
                FROM certificates c
                INNER JOIN users u ON u.id = c.user_id
                INNER JOIN payers p ON p.id = c.payer_id
                LEFT JOIN beneficiaries b ON b.id = c.beneficiary_id
                WHERE 1 = 1{$archived} AND {$visibility}
                HAVING m_name OR m_serial_number OR m_issuer OR m_beneficiary OR m_payer OR m_owner";
        $params += [
            'c_name'    => $like,
            'c_serial'  => $like,
            'c_issuer'  => $like,
            'c_person1' => $like,
            'c_person2' => $like,
            'c_payer'   => $like,
            'c_tax'     => $digitsLike,
            'c_owner1'  => $like,
            'c_owner2'  => $like,
        ];

        $total = $this->count($sql, $params);
        $stmt = $this->db->prepare(
            $sql . " ORDER BY (m_name OR m_serial_number OR m_issuer) DESC, c.archived_at IS NOT NULL, c.expiry_date, c.id LIMIT {$limit}"
        );
        $stmt->execute($params);
        $settings = Settings::read($this->db);

        $items = array_map(static function (array $row) use ($today, $settings): array {
            $daysLeft = (int) $today->diff(new DateTimeImmutable((string) $row['expiry_date']))->format('%r%a');

            return [
                'id'               => (int) $row['id'],
                'name'             => (string) $row['name'],
                'certificate_type' => (string) $row['certificate_type'],
                'serial_number'    => $row['serial_number'],
                'issuer'           => $row['issuer'],
                'expiry_date'      => (string) $row['expiry_date'],
                'days_left'        => $daysLeft,
                'priority'         => $row['archived_at'] !== null ? null : CertificateHelper::priorityFor(
                    $daysLeft,
                    $settings['renewal.critical_days'],
                    $settings['renewal.warning_days']
                ),
                'status'           => (string) $row['status'],
                'archived_at'      => $row['archived_at'],
                'beneficiary'      => $row['beneficiary_id'] !== null ? [
                    'id'   => (int) $row['beneficiary_id'],
                    'name' => trim($row['beneficiary_first_name'] . ' ' . $row['beneficiary_last_name']),
                ] : null,
                'payer'            => ['id' => (int) $row['payer_id'], 'name' => (string) $row['payer_name']],
                'owner_name'       => trim($row['owner_first_name'] . ' ' . $row['owner_last_name']),
                'matched'          => self::matched($row, ['name', 'serial_number', 'issuer', 'beneficiary', 'payer', 'owner']),
            ];
        }, $stmt->fetchAll());

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function beneficiaries(Actor $actor, string $like, string $digitsLike, int $limit): array
    {
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
        [$matchVisibility, $matchParams] = Visibility::certificates($actor, 'mc');
        [$countVisibility, $countParams] = Visibility::certificates($actor, 'cc');
        [$expiryVisibility, $expiryParams] = Visibility::certificates($actor, 'ec');
        $archived = $actor->can('archive.view') ? '' : ' AND b.archived_at IS NULL';
        $phone = sprintf(self::PHONE_DIGITS, 'b.phone');

        $sql = "SELECT b.id, b.first_name, b.last_name, b.email, b.phone, b.archived_at, b.payer_id,
                       p.company_name AS payer_name,
                       (CONCAT_WS(' ', b.first_name, b.last_name) LIKE :b_name1
                           OR CONCAT_WS(' ', b.last_name, b.first_name) LIKE :b_name2) AS m_name,
                       (b.email LIKE :b_email) AS m_email,
                       ({$phone} LIKE :b_phone) AS m_phone,
                       (p.company_name LIKE :b_payer) AS m_payer,
                       EXISTS (SELECT 1 FROM certificates mc
                               WHERE mc.beneficiary_id = b.id AND mc.archived_at IS NULL
                                 AND (mc.name LIKE :b_cert1 OR mc.serial_number LIKE :b_cert2) AND {$matchVisibility}) AS m_certificate,
                       (SELECT COUNT(*) FROM certificates cc
                        WHERE cc.beneficiary_id = b.id AND cc.archived_at IS NULL AND {$countVisibility}) AS certificate_count,
                       (SELECT MIN(ec.expiry_date) FROM certificates ec
                        WHERE ec.beneficiary_id = b.id AND ec.archived_at IS NULL AND {$expiryVisibility}) AS next_expiry
                FROM beneficiaries b
                LEFT JOIN payers p ON p.id = b.payer_id
                WHERE {$visibility}{$archived}
                HAVING m_name OR m_email OR m_phone OR m_payer OR m_certificate";
        $params += $matchParams + $countParams + $expiryParams + [
            'b_name1' => $like,
            'b_name2' => $like,
            'b_email' => $like,
            'b_phone' => $digitsLike,
            'b_payer' => $like,
            'b_cert1' => $like,
            'b_cert2' => $like,
        ];

        $total = $this->count($sql, $params);
        $stmt = $this->db->prepare(
            $sql . " ORDER BY (m_name OR m_email OR m_phone) DESC, b.archived_at IS NOT NULL, b.last_name, b.first_name LIMIT {$limit}"
        );
        $stmt->execute($params);

        $items = array_map(static fn (array $row): array => [
            'id'                => (int) $row['id'],
            'first_name'        => (string) $row['first_name'],
            'last_name'         => (string) $row['last_name'],
            'email'             => $row['email'],
            'phone'             => $row['phone'],
            'archived_at'       => $row['archived_at'],
            'payer'             => $row['payer_id'] !== null ? ['id' => (int) $row['payer_id'], 'name' => (string) $row['payer_name']] : null,
            'certificate_count' => (int) $row['certificate_count'],
            'next_expiry'       => $row['next_expiry'],
            'matched'           => self::matched($row, ['name', 'email', 'phone', 'payer', 'certificate']),
        ], $stmt->fetchAll());

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function payers(Actor $actor, string $like, string $digitsLike, int $limit): array
    {
        [$visibility, $params] = Visibility::payers($actor, 'p');
        [$personVisibility, $personParams] = Visibility::beneficiaries($actor, 'mb');
        [$certificateVisibility, $certificateParams] = Visibility::certificates($actor, 'mc');
        [$personCountVisibility, $personCountParams] = Visibility::beneficiaries($actor, 'cb');
        [$certificateCountVisibility, $certificateCountParams] = Visibility::certificates($actor, 'cc');
        $archived = $actor->can('archive.view') ? '' : ' AND p.archived_at IS NULL';

        $sql = "SELECT p.id, p.company_name, p.tax_id, p.city, p.email, p.contact_person, p.archived_at,
                       (p.company_name LIKE :p_name) AS m_name,
                       (p.tax_id LIKE :p_tax) AS m_tax_id,
                       (p.email LIKE :p_email OR p.contact_person LIKE :p_contact) AS m_contact,
                       (p.city LIKE :p_city) AS m_city,
                       EXISTS (SELECT 1 FROM beneficiaries mb
                               WHERE mb.payer_id = p.id AND mb.archived_at IS NULL
                                 AND (CONCAT_WS(' ', mb.first_name, mb.last_name) LIKE :p_person1
                                      OR CONCAT_WS(' ', mb.last_name, mb.first_name) LIKE :p_person2)
                                 AND {$personVisibility}) AS m_beneficiary,
                       EXISTS (SELECT 1 FROM certificates mc
                               WHERE mc.payer_id = p.id AND mc.archived_at IS NULL
                                 AND (mc.name LIKE :p_cert1 OR mc.serial_number LIKE :p_cert2) AND {$certificateVisibility}) AS m_certificate,
                       (SELECT COUNT(*) FROM beneficiaries cb
                        WHERE cb.payer_id = p.id AND cb.archived_at IS NULL AND {$personCountVisibility}) AS beneficiary_count,
                       (SELECT COUNT(*) FROM certificates cc
                        WHERE cc.payer_id = p.id AND cc.archived_at IS NULL AND {$certificateCountVisibility}) AS certificate_count
                FROM payers p
                WHERE {$visibility}{$archived}
                HAVING m_name OR m_tax_id OR m_contact OR m_city OR m_beneficiary OR m_certificate";
        $params += $personParams + $certificateParams + $personCountParams + $certificateCountParams + [
            'p_name'    => $like,
            'p_tax'     => $digitsLike,
            'p_email'   => $like,
            'p_contact' => $like,
            'p_city'    => $like,
            'p_person1' => $like,
            'p_person2' => $like,
            'p_cert1'   => $like,
            'p_cert2'   => $like,
        ];

        $total = $this->count($sql, $params);
        $stmt = $this->db->prepare(
            $sql . " ORDER BY (m_name OR m_tax_id OR m_contact OR m_city) DESC, p.archived_at IS NOT NULL, p.company_name LIMIT {$limit}"
        );
        $stmt->execute($params);

        $items = array_map(static fn (array $row): array => [
            'id'                => (int) $row['id'],
            'company_name'      => (string) $row['company_name'],
            'tax_id'            => $row['tax_id'],
            'city'              => $row['city'],
            'email'             => $row['email'],
            'contact_person'    => $row['contact_person'],
            'archived_at'       => $row['archived_at'],
            'beneficiary_count' => (int) $row['beneficiary_count'],
            'certificate_count' => (int) $row['certificate_count'],
            'matched'           => self::matched($row, ['name', 'tax_id', 'contact', 'city', 'beneficiary', 'certificate']),
        ], $stmt->fetchAll());

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string, int|string> $params
     */
    private function count(string $sql, array $params): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM (' . $sql . ') matches');
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $fields
     * @return list<string>
     */
    private static function matched(array $row, array $fields): array
    {
        return array_values(array_filter($fields, static fn (string $field): bool => (int) ($row['m_' . $field] ?? 0) === 1));
    }
}

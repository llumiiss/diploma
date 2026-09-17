<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Odczyt historii zdarzeń (F17): oś czasu certyfikatu, użytkownika certyfikatu, płatnika
 * oraz pełny dziennik dla administratora. Zdarzenia zapisuje EventLogger.
 */
final class TimelineService
{
    private const CONTEXT_COLUMNS = [
        'certificate' => 'certificate_id',
        'beneficiary' => 'beneficiary_id',
        'payer'       => 'payer_id',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Zdarzenia powiązane z rekordem (przez kolumny kontekstu), od najnowszych.
     * Uprawnienie do samego rekordu sprawdza usługa, która go zwraca.
     *
     * @return list<array<string, mixed>>
     */
    public function forContext(string $context, int $id, int $limit = 200): array
    {
        $column = self::CONTEXT_COLUMNS[$context] ?? null;
        if ($column === null) {
            return [];
        }

        $limit = max(1, min($limit, 1000));
        $stmt = $this->db->prepare(
            "SELECT e.id, e.entity_type, e.entity_id, e.event_type, e.user_id, e.certificate_id, e.beneficiary_id,
                    e.payer_id, e.payload, e.occurred_at, u.first_name AS user_first_name, u.last_name AS user_last_name,
                    c.name AS certificate_name
             FROM events e
             LEFT JOIN users u ON u.id = e.user_id
             LEFT JOIN certificates c ON c.id = e.certificate_id
             WHERE e.{$column} = :id
             ORDER BY e.occurred_at DESC, e.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['id' => $id]);

        return array_map([self::class, 'castRow'], $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function castRow(array $row): array
    {
        foreach (['id', 'entity_id', 'user_id', 'certificate_id', 'beneficiary_id', 'payer_id'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
            }
        }

        $payload = $row['payload'] ?? null;
        $row['payload'] = is_string($payload) && $payload !== '' ? (json_decode($payload, true) ?: []) : [];
        $row['user_name'] = trim(($row['user_first_name'] ?? '') . ' ' . ($row['user_last_name'] ?? ''));
        unset($row['user_first_name'], $row['user_last_name']);

        return $row;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache;
use PDO;

/**
 * Zapis historii zdarzeń (tabela events) — z niej powstaje oś czasu certyfikatu,
 * użytkownika certyfikatu i płatnika (F17). Każda zmiana danych biznesowych trafia tutaj.
 */
final class EventLogger
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{certificate_id?: int|null, beneficiary_id?: int|null, payer_id?: int|null} $context
     * @param array<string, mixed> $payload
     */
    public function log(
        string $entityType,
        ?int $entityId,
        string $eventType,
        ?int $userId,
        array $context = [],
        array $payload = [],
        ?string $occurredAt = null,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO events (entity_type, entity_id, event_type, user_id, certificate_id, beneficiary_id, payer_id, payload, occurred_at)
             VALUES (:entity_type, :entity_id, :event_type, :user_id, :certificate_id, :beneficiary_id, :payer_id, :payload, :occurred_at)'
        );
        // Każdy zapis danych unieważnia agregaty pulpitu i statystyki (§2.5).
        Cache::invalidate();

        $stmt->execute([
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'event_type'     => $eventType,
            'user_id'        => $userId !== null && $userId > 0 ? $userId : null,
            'certificate_id' => $context['certificate_id'] ?? null,
            'beneficiary_id' => $context['beneficiary_id'] ?? null,
            'payer_id'       => $context['payer_id'] ?? null,
            'payload'        => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'occurred_at'    => $occurredAt ?? date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Zmienione pola w postaci {pole: {from, to}} — zapisywane w historii przy edycji.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string>         $fields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = self::comparable($before[$field] ?? null);
            $new = self::comparable($after[$field] ?? null);
            if ($old !== $new) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
            }
        }

        return $changes;
    }

    private static function comparable(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
            return number_format((float) $value, 2, '.', '');
        }

        return (string) $value;
    }
}

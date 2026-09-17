<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use PDO;

/**
 * Odczyt historii zdarzeń (F17): oś czasu certyfikatu, użytkownika certyfikatu, płatnika
 * oraz pełny dziennik dla administratora (F8). Zdarzenia zapisuje EventLogger.
 */
final class TimelineService
{
    /** @var list<string> */
    public const ENTITY_TYPES = [
        'certificate', 'beneficiary', 'payer', 'renewal_task', 'invitation', 'email_template', 'attachment', 'user', 'system',
    ];

    public const PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

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
     * Gdy podano konto bez wglądu w całą organizację (D8), pomijane są zdarzenia certyfikatów,
     * których to konto nie widzi — np. w historii osoby, której drugi certyfikat prowadzi ktoś inny.
     *
     * @return list<array<string, mixed>>
     */
    public function forContext(string $context, int $id, int $limit = 200, ?Actor $actor = null): array
    {
        $column = self::CONTEXT_COLUMNS[$context] ?? null;
        if ($column === null) {
            return [];
        }

        $params = ['id' => $id];
        $scope = '';
        if ($actor !== null && !$actor->seesAllRecords()) {
            [$visibility, $visibilityParams] = Visibility::certificates($actor, 'vc');
            $scope = " AND (e.certificate_id IS NULL OR EXISTS (SELECT 1 FROM certificates vc WHERE vc.id = e.certificate_id AND {$visibility}))";
            $params += $visibilityParams;
        }

        $limit = max(1, min($limit, 1000));
        $stmt = $this->db->prepare(
            "SELECT e.id, e.entity_type, e.entity_id, e.event_type, e.user_id, e.certificate_id, e.beneficiary_id,
                    e.payer_id, e.payload, e.occurred_at, u.first_name AS user_first_name, u.last_name AS user_last_name,
                    c.name AS certificate_name, c.archived_at AS certificate_archived_at
             FROM events e
             LEFT JOIN users u ON u.id = e.user_id
             LEFT JOIN certificates c ON c.id = e.certificate_id
             WHERE e.{$column} = :id{$scope}
             ORDER BY e.occurred_at DESC, e.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);

        return array_map([self::class, 'castRow'], $stmt->fetchAll());
    }

    /**
     * Dziennik zdarzeń administratora: wszystkie zdarzenia systemu z filtrami i stronicowaniem.
     *
     * Filtry: entity_type, event_type, user (id konta albo „system”), date_from, date_to (włącznie),
     * certificate_id, beneficiary_id, payer_id oraz q — tekst szukany w nazwach powiązanych
     * rekordów, autorze i treści zdarzenia.
     *
     * @param array<string, mixed> $filters
     * @return array{events: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int, facets: array<string, mixed>}
     */
    public function journal(Actor $actor, array $filters = [], int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $actor->authorize('events.view_all');

        [$conditions, $params] = $this->journalConditions($filters);
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $from = "
            FROM events e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN certificates c ON c.id = e.certificate_id
            LEFT JOIN beneficiaries b ON b.id = e.beneficiary_id
            LEFT JOIN payers p ON p.id = e.payer_id
            LEFT JOIN users eu ON e.entity_type = 'user' AND eu.id = e.entity_id
            LEFT JOIN email_templates et ON e.entity_type = 'email_template' AND et.id = e.entity_id
            LEFT JOIN attachments ea ON e.entity_type = 'attachment' AND ea.id = e.entity_id";

        $count = $this->db->prepare('SELECT COUNT(*)' . $from . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT e.id, e.entity_type, e.entity_id, e.event_type, e.user_id, e.certificate_id, e.beneficiary_id,
                    e.payer_id, e.payload, e.occurred_at, u.first_name AS user_first_name, u.last_name AS user_last_name,
                    c.name AS certificate_name, c.archived_at AS certificate_archived_at, c.scope AS certificate_scope,
                    b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name,
                    p.company_name AS payer_name,
                    CASE e.entity_type
                        WHEN 'user' THEN CONCAT_WS(' ', eu.first_name, eu.last_name)
                        WHEN 'email_template' THEN et.name
                        WHEN 'attachment' THEN ea.original_name
                        ELSE NULL
                    END AS entity_label"
            . $from . $where
            . " ORDER BY e.occurred_at DESC, e.id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $events = array_map(static function (array $row): array {
            $row = self::castRow($row);
            $row['beneficiary_name'] = trim(($row['beneficiary_first_name'] ?? '') . ' ' . ($row['beneficiary_last_name'] ?? ''));
            unset($row['beneficiary_first_name'], $row['beneficiary_last_name']);

            return $row;
        }, $stmt->fetchAll());

        return [
            'events'   => $events,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
            'facets'   => $this->journalFacets(),
        ];
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

    /**
     * @param array<string, mixed> $filters
     * @return array{0: list<string>, 1: array<string, int|string>}
     */
    private function journalConditions(array $filters): array
    {
        $v = new Validator($filters);
        $entityType = $v->enum('entity_type', false, self::ENTITY_TYPES);
        $eventType = $v->string('event_type', false, 64);
        if ($eventType !== null && preg_match('/^[a-z_]+$/', $eventType) !== 1) {
            $v->addError('event_type', 'validation.choice');
        }
        $dateFrom = $v->date('date_from', false);
        $dateTo = $v->date('date_to', false);
        if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
            $v->addError('date_to', 'eventlog.error.date_range');
        }
        $certificateId = $v->id('certificate_id', false);
        $beneficiaryId = $v->id('beneficiary_id', false);
        $payerId = $v->id('payer_id', false);
        $query = $v->string('q', false, 100);

        $user = $filters['user'] ?? null;
        $userId = null;
        $systemOnly = false;
        if ($user === 'system') {
            $systemOnly = true;
        } elseif ($user !== null && $user !== '') {
            $userId = $v->id('user', false);
        }
        $v->throwIfFailed();

        $conditions = [];
        $params = [];
        if ($entityType !== null) {
            $conditions[] = 'e.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        if ($eventType !== null) {
            $conditions[] = 'e.event_type = :event_type';
            $params['event_type'] = $eventType;
        }
        if ($systemOnly) {
            $conditions[] = 'e.user_id IS NULL';
        } elseif ($userId !== null) {
            $conditions[] = 'e.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        if ($dateFrom !== null) {
            $conditions[] = 'e.occurred_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $conditions[] = 'e.occurred_at < :date_to';
            $params['date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        foreach (['certificate_id' => $certificateId, 'beneficiary_id' => $beneficiaryId, 'payer_id' => $payerId] as $column => $value) {
            if ($value !== null) {
                $conditions[] = "e.{$column} = :{$column}";
                $params[$column] = $value;
            }
        }
        if ($query !== null) {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $conditions[] = "(c.name LIKE :q1 OR CONCAT_WS(' ', b.first_name, b.last_name) LIKE :q2 OR p.company_name LIKE :q3
                OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE :q4 OR CAST(e.payload AS CHAR) LIKE :q5 OR e.event_type LIKE :q6)";
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
        }

        return [$conditions, $params];
    }

    /**
     * Wartości do list wyboru w filtrach dziennika: typy zdarzeń z liczbą wystąpień i autorzy.
     *
     * @return array{event_types: list<array{entity_type: string, event_type: string, count: int}>, users: list<array{id: int, name: string}>, has_system: bool}
     */
    private function journalFacets(): array
    {
        $types = $this->db->query(
            'SELECT entity_type, event_type, COUNT(*) AS count FROM events GROUP BY entity_type, event_type ORDER BY entity_type, event_type'
        );
        $users = $this->db->query(
            'SELECT u.id, u.first_name, u.last_name FROM users u
             WHERE EXISTS (SELECT 1 FROM events e WHERE e.user_id = u.id)
             ORDER BY u.last_name, u.first_name'
        );
        $system = $this->db->query('SELECT EXISTS (SELECT 1 FROM events WHERE user_id IS NULL)');

        return [
            'event_types' => array_map(static fn (array $row): array => [
                'entity_type' => (string) $row['entity_type'],
                'event_type'  => (string) $row['event_type'],
                'count'       => (int) $row['count'],
            ], $types !== false ? $types->fetchAll() : []),
            'users' => array_map(static fn (array $row): array => [
                'id'   => (int) $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            ], $users !== false ? $users->fetchAll() : []),
            'has_system' => $system !== false && (bool) $system->fetchColumn(),
        ];
    }
}

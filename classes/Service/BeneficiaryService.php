<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Ewidencja użytkowników certyfikatów — beneficjentów (F2, F4, F5). To osoby, których dotyczy
 * certyfikat, a nie konta logowania. Beneficjent jest powiązany z płatnikiem, ale nim nie jest.
 */
final class BeneficiaryService
{
    /** @var list<string> */
    public const FIELDS = ['first_name', 'last_name', 'email', 'phone', 'payer_id', 'notes'];

    private readonly EventLogger $events;
    private readonly PayerService $payers;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null, ?PayerService $payers = null)
    {
        $this->events = $events ?? new EventLogger($db);
        $this->payers = $payers ?? new PayerService($db, $this->events);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, bool $archived = false): array
    {
        $actor->authorize($archived ? 'archive.view' : 'beneficiaries.view');

        [$beneficiaryVisibility, $params] = Visibility::beneficiaries($actor, 'b');
        [$certificateVisibility, $certificateParams] = Visibility::certificates($actor, 'c');
        $archivedCondition = $archived ? 'b.archived_at IS NOT NULL' : 'b.archived_at IS NULL';

        $sql = "
            SELECT
                b.id, b.first_name, b.last_name, b.email, b.phone, b.payer_id, b.notes,
                b.archived_at, b.created_at, b.updated_at,
                p.company_name AS payer_name,
                p.tax_id AS payer_tax_id,
                p.archived_at AS payer_archived_at,
                COALESCE(cs.certificate_count, 0) AS certificate_count,
                cs.earliest_expiry
            FROM beneficiaries b
            LEFT JOIN payers p ON p.id = b.payer_id
            LEFT JOIN (
                SELECT c.beneficiary_id, COUNT(*) AS certificate_count, MIN(c.expiry_date) AS earliest_expiry
                FROM certificates c
                WHERE c.archived_at IS NULL AND c.scope = 'corporate' AND {$certificateVisibility}
                GROUP BY c.beneficiary_id
            ) cs ON cs.beneficiary_id = b.id
            WHERE {$archivedCondition} AND {$beneficiaryVisibility}
            ORDER BY " . ($archived ? 'b.archived_at DESC' : 'b.last_name, b.first_name') . "
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params + $certificateParams);

        return array_map([self::class, 'castRow'], $stmt->fetchAll());
    }

    /**
     * @return list<array{id: int, first_name: string, last_name: string, email: ?string, payer_id: ?int}>
     */
    public function options(Actor $actor): array
    {
        $actor->authorize('beneficiaries.view');
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');

        $stmt = $this->db->prepare(
            "SELECT b.id, b.first_name, b.last_name, b.email, b.payer_id
             FROM beneficiaries b
             WHERE b.archived_at IS NULL AND {$visibility}
             ORDER BY b.last_name, b.first_name"
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name'  => (string) $row['last_name'],
            'email'      => $row['email'],
            'payer_id'   => $row['payer_id'] !== null ? (int) $row['payer_id'] : null,
        ], $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $id): array
    {
        $actor->authorize('beneficiaries.view');
        $beneficiary = $this->findVisible($actor, $id, true);

        [$certificateVisibility, $certificateParams] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, c.certificate_type, c.serial_number, c.issuer, c.valid_from, c.expiry_date,
                    c.renewal_lead_days, c.status, c.payer_id, p.company_name AS payer_name
             FROM certificates c
             INNER JOIN payers p ON p.id = c.payer_id
             WHERE c.beneficiary_id = :beneficiary_id AND c.archived_at IS NULL AND c.scope = 'corporate' AND {$certificateVisibility}
             ORDER BY c.expiry_date"
        );
        $stmt->execute(['beneficiary_id' => $id] + $certificateParams);
        $beneficiary['certificates'] = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['payer_id'] = (int) $row['payer_id'];

            return $row;
        }, $stmt->fetchAll());

        return $beneficiary;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $actor->authorize('beneficiaries.create');
        $values = $this->validate($actor, $data);

        $id = Transaction::run($this->db, function () use ($actor, $values): int {
            $stmt = $this->db->prepare(
                'INSERT INTO beneficiaries (first_name, last_name, email, phone, payer_id, notes, created_by_user_id)
                 VALUES (:first_name, :last_name, :email, :phone, :payer_id, :notes, :created_by_user_id)'
            );
            $stmt->execute($values + ['created_by_user_id' => $actor->id]);
            $id = (int) $this->db->lastInsertId();

            $this->events->log('beneficiary', $id, 'created', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $values['payer_id'],
            ], ['name' => trim($values['first_name'] . ' ' . $values['last_name'])]);

            return $id;
        });

        return $this->get($actor, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('beneficiaries.update');
        $before = $this->findVisible($actor, $id, false);
        $values = $this->validate($actor, $data, $before);

        $changes = EventLogger::diff($before, $values, self::FIELDS);
        if ($changes === []) {
            return $this->get($actor, $id);
        }

        Transaction::run($this->db, function () use ($actor, $id, $values, $changes): void {
            $stmt = $this->db->prepare(
                'UPDATE beneficiaries SET first_name = :first_name, last_name = :last_name, email = :email,
                        phone = :phone, payer_id = :payer_id, notes = :notes
                 WHERE id = :id'
            );
            $stmt->execute($values + ['id' => $id]);
            $this->events->log('beneficiary', $id, 'updated', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $values['payer_id'],
            ], ['changes' => $changes]);
        });

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(Actor $actor, int $id): array
    {
        $actor->authorize('beneficiaries.archive');
        $beneficiary = $this->findVisible($actor, $id, false);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM certificates WHERE beneficiary_id = :id AND archived_at IS NULL');
        $stmt->execute(['id' => $id]);
        $certificates = (int) $stmt->fetchColumn();
        if ($certificates > 0) {
            throw ServiceException::conflict(
                \__('beneficiary.error.archive_blocked', ['certificates' => (string) $certificates]),
                ['certificates' => $certificates]
            );
        }

        Transaction::run($this->db, function () use ($actor, $id, $beneficiary): void {
            $this->db->prepare('UPDATE beneficiaries SET archived_at = NOW() WHERE id = :id')->execute(['id' => $id]);
            $this->events->log('beneficiary', $id, 'archived', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $beneficiary['payer_id'],
            ], ['name' => trim($beneficiary['first_name'] . ' ' . $beneficiary['last_name'])]);
        });

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(Actor $actor, int $id): array
    {
        $actor->authorize('beneficiaries.archive');
        $beneficiary = $this->findVisible($actor, $id, true);
        if ($beneficiary['archived_at'] === null) {
            throw ServiceException::conflict(\__('common.error.not_archived'));
        }

        if ($beneficiary['payer_id'] !== null && $beneficiary['payer_archived_at'] !== null) {
            throw ServiceException::conflict(\__('beneficiary.error.restore_payer_archived', [
                'payer' => (string) $beneficiary['payer_name'],
            ]));
        }

        Transaction::run($this->db, function () use ($actor, $id, $beneficiary): void {
            $this->db->prepare('UPDATE beneficiaries SET archived_at = NULL WHERE id = :id')->execute(['id' => $id]);
            $this->events->log('beneficiary', $id, 'restored', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $beneficiary['payer_id'],
            ], ['name' => trim($beneficiary['first_name'] . ' ' . $beneficiary['last_name'])]);
        });

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveVisible(Actor $actor, int $id): ?array
    {
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
        $stmt = $this->db->prepare(
            "SELECT b.* FROM beneficiaries b WHERE b.id = :id AND b.archived_at IS NULL AND {$visibility} LIMIT 1"
        );
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        return $row === false ? null : self::castRow($row);
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $before bieżący stan przy edycji
     * @return array{first_name: string, last_name: string, email: ?string, phone: ?string, payer_id: ?int, notes: ?string}
     */
    public function validate(Actor $actor, array $data, ?array $before = null): array
    {
        $v = new Validator($data);
        $values = [
            'first_name' => (string) $v->string('first_name', true, 100),
            'last_name'  => (string) $v->string('last_name', true, 100),
            'email'      => $v->email('email', false),
            'phone'      => $v->phone('phone'),
            'payer_id'   => $v->id('payer_id', false),
            'notes'      => $v->string('notes', false, 5000),
        ];

        // Powiązanie z płatnikiem, którego konto nie widzi, jest dozwolone tylko wtedy,
        // gdy istniało już wcześniej (edycja innych pól nie może go zerwać).
        $payerId = $values['payer_id'];
        $unchanged = $before !== null && $payerId !== null && $payerId === $before['payer_id'];
        if ($payerId !== null && !$unchanged && $this->payers->findActiveVisible($actor, $payerId) === null) {
            $v->addError('payer_id', 'beneficiary.error.payer_unavailable');
        }

        $v->throwIfFailed();

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function findVisible(Actor $actor, int $id, bool $includeArchived): array
    {
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
        $stmt = $this->db->prepare(
            "SELECT b.*, p.company_name AS payer_name, p.archived_at AS payer_archived_at
             FROM beneficiaries b
             LEFT JOIN payers p ON p.id = b.payer_id
             WHERE b.id = :id AND {$visibility} LIMIT 1"
        );
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ServiceException::notFound(\__('beneficiary.error.not_found'));
        }

        if ($row['archived_at'] !== null && (!$includeArchived || !$actor->can('archive.view'))) {
            throw $includeArchived
                ? ServiceException::notFound(\__('beneficiary.error.not_found'))
                : ServiceException::conflict(\__('common.error.archived_readonly'));
        }

        return self::castRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['payer_id'] = isset($row['payer_id']) ? (int) $row['payer_id'] : null;
        if (array_key_exists('certificate_count', $row)) {
            $row['certificate_count'] = (int) $row['certificate_count'];
        }
        if (array_key_exists('created_by_user_id', $row)) {
            $row['created_by_user_id'] = $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null;
        }

        return $row;
    }
}

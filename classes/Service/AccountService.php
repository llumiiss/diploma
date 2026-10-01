<?php

declare(strict_types=1);

namespace App\Service;

use App\Auth\VerificationTokens;
use App\Rbac;
use PDO;

/**
 * Konta personelu zarządzane przez administratora (F15, F19, decyzja D3): zakładanie kont,
 * zmiana ról, wyłączanie i ponowne włączanie oraz przekazanie certyfikatów innej osobie.
 *
 * Konta się nie usuwa — wyłączone konto nie może się zalogować, ale jego historia zostaje.
 * System zawsze zachowuje co najmniej jednego aktywnego administratora.
 */
final class AccountService
{
    /** @var list<string> */
    public const FIELDS = ['first_name', 'last_name', 'email', 'role', 'beneficiary_id'];

    private readonly EventLogger $events;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null)
    {
        $this->events = $events ?? new EventLogger($db);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor): array
    {
        $actor->authorize('accounts.manage');

        $stmt = $this->db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.beneficiary_id, u.created_at, u.deactivated_at,
                    CONCAT_WS(' ', ub.first_name, ub.last_name) AS beneficiary_name,
                    (SELECT COUNT(*) FROM certificates c
                      WHERE c.user_id = u.id AND c.archived_at IS NULL) AS certificate_count,
                    (SELECT COUNT(*) FROM renewal_tasks t
                      WHERE t.assigned_user_id = u.id AND t.status IN ('todo', 'in_progress')) AS open_task_count,
                    u.last_login_at
             FROM users u
             LEFT JOIN beneficiaries ub ON ub.id = u.beneficiary_id
             ORDER BY u.deactivated_at IS NOT NULL, u.last_name, u.first_name"
        );

        return array_map([self::class, 'castRow'], $stmt !== false ? $stmt->fetchAll() : []);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $actor->authorize('accounts.manage');
        $values = $this->validate($data, null);

        $id = Transaction::run($this->db, function () use ($actor, $values): int {
            $stmt = $this->db->prepare(
                'INSERT INTO users (first_name, last_name, email, role, beneficiary_id)
                 VALUES (:first_name, :last_name, :email, :role, :beneficiary_id)'
            );
            $stmt->execute($values);
            $id = (int) $this->db->lastInsertId();

            $this->events->log('user', $id, 'account_created', $actor->id, [], [
                'name'  => trim($values['first_name'] . ' ' . $values['last_name']),
                'email' => $values['email'],
                'role'  => $values['role'],
                'beneficiary_id' => $values['beneficiary_id'],
            ]);

            return $id;
        });

        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('accounts.manage');
        $before = $this->get($id);
        $values = $this->validate($data, $id);

        $losesAdmin = $before['role'] === Rbac::ADMIN && $values['role'] !== Rbac::ADMIN && $before['deactivated_at'] === null;
        if ($losesAdmin && $this->activeAdminCount($id) === 0) {
            throw ServiceException::conflict(\__('account.error.last_admin'), [], ['role' => \__('account.error.last_admin')]);
        }

        $changes = EventLogger::diff($before, $values, self::FIELDS);
        if ($changes === []) {
            return $before;
        }

        Transaction::run($this->db, function () use ($actor, $id, $values, $changes): void {
            $stmt = $this->db->prepare(
                'UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, role = :role,
                        beneficiary_id = :beneficiary_id
                 WHERE id = :id'
            );
            $stmt->execute($values + ['id' => $id]);
            $this->events->log('user', $id, isset($changes['role']) ? 'role_changed' : 'account_updated', $actor->id, [], [
                'changes' => $changes,
            ]);
        });

        return $this->get($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(Actor $actor, int $id): array
    {
        $actor->authorize('accounts.manage');
        $account = $this->get($id);

        if ($id === $actor->id) {
            throw ServiceException::conflict(\__('account.error.self_deactivate'));
        }
        if ($account['deactivated_at'] !== null) {
            throw ServiceException::conflict(\__('account.error.already_inactive'));
        }
        if ($account['role'] === Rbac::ADMIN && $this->activeAdminCount($id) === 0) {
            throw ServiceException::conflict(\__('account.error.last_admin'));
        }

        Transaction::run($this->db, function () use ($actor, $id, $account): void {
            $this->db->prepare('UPDATE users SET deactivated_at = NOW() WHERE id = :id')->execute(['id' => $id]);
            // Niewykorzystane linki z wiadomości (potwierdzenie adresu, ustawienie hasła)
            // przestają działać od razu — inaczej wyłączone konto dałoby się jeszcze przejąć.
            (new VerificationTokens($this->db))->invalidateForUser($id);
            $this->events->log('user', $id, 'account_deactivated', $actor->id, [], [
                'name'         => $account['name'],
                'certificates' => $account['certificate_count'],
                'open_tasks'   => $account['open_task_count'],
            ]);
        });

        return $this->get($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivate(Actor $actor, int $id): array
    {
        $actor->authorize('accounts.manage');
        $account = $this->get($id);
        if ($account['deactivated_at'] === null) {
            throw ServiceException::conflict(\__('account.error.already_active'));
        }

        Transaction::run($this->db, function () use ($actor, $id, $account): void {
            $this->db->prepare('UPDATE users SET deactivated_at = NULL WHERE id = :id')->execute(['id' => $id]);
            $this->events->log('user', $id, 'account_reactivated', $actor->id, [], ['name' => $account['name']]);
        });

        return $this->get($id);
    }

    /**
     * Przekazuje certyfikaty firmowe i otwarte zadania odnowień z jednego konta na drugie —
     * typowo przed wyłączeniem konta osoby, która odchodzi.
     *
     * @return array{certificates: int, tasks: int}
     */
    public function transferCertificates(Actor $actor, int $fromId, int $toId): array
    {
        $actor->authorize('accounts.manage');
        $from = $this->get($fromId);
        $to = $this->get($toId);

        if ($fromId === $toId) {
            throw ServiceException::validation(['to_user_id' => \__('account.error.transfer_same')]);
        }
        if ($to['deactivated_at'] !== null) {
            throw ServiceException::validation(['to_user_id' => \__('account.error.transfer_inactive')]);
        }

        return Transaction::run($this->db, function () use ($actor, $from, $to, $fromId, $toId): array {
            $stmt = $this->db->prepare(
                'SELECT id, beneficiary_id, payer_id FROM certificates WHERE user_id = :from'
            );
            $stmt->execute(['from' => $fromId]);
            $certificates = $stmt->fetchAll();

            $this->db->prepare('UPDATE certificates SET user_id = :to WHERE user_id = :from')
                ->execute(['to' => $toId, 'from' => $fromId]);

            foreach ($certificates as $certificate) {
                $certificateId = (int) $certificate['id'];
                $this->events->log('certificate', $certificateId, 'owner_changed', $actor->id, [
                    'certificate_id' => $certificateId,
                    'beneficiary_id' => $certificate['beneficiary_id'] !== null ? (int) $certificate['beneficiary_id'] : null,
                    'payer_id'       => (int) $certificate['payer_id'],
                ], [
                    'from_user_id' => $fromId,
                    'from_name'    => $from['name'],
                    'to_user_id'   => $toId,
                    'to_name'      => $to['name'],
                ]);
            }

            $tasks = $this->db->prepare(
                "UPDATE renewal_tasks SET assigned_user_id = :to
                 WHERE assigned_user_id = :from AND status IN ('todo', 'in_progress')"
            );
            $tasks->execute(['to' => $toId, 'from' => $fromId]);
            $taskCount = $tasks->rowCount();

            $this->events->log('user', $fromId, 'certificates_transferred', $actor->id, [], [
                'to_user_id'   => $toId,
                'to_name'      => $to['name'],
                'certificates' => count($certificates),
                'tasks'        => $taskCount,
            ]);

            return ['certificates' => count($certificates), 'tasks' => $taskCount];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.beneficiary_id, u.created_at, u.deactivated_at,
                    CONCAT_WS(' ', ub.first_name, ub.last_name) AS beneficiary_name,
                    (SELECT COUNT(*) FROM certificates c
                      WHERE c.user_id = u.id AND c.archived_at IS NULL) AS certificate_count,
                    (SELECT COUNT(*) FROM renewal_tasks t
                      WHERE t.assigned_user_id = u.id AND t.status IN ('todo', 'in_progress')) AS open_task_count,
                    u.last_login_at
             FROM users u
             LEFT JOIN beneficiaries ub ON ub.id = u.beneficiary_id
             WHERE u.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ServiceException::notFound(\__('account.error.not_found'));
        }

        return self::castRow($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{first_name: string, last_name: string, email: string, role: string, beneficiary_id: ?int}
     */
    private function validate(array $data, ?int $exceptId): array
    {
        $v = new Validator($data);
        $values = [
            'first_name'     => (string) $v->string('first_name', true, 100),
            'last_name'      => (string) $v->string('last_name', true, 100),
            'email'          => (string) $v->email('email', true),
            'role'           => (string) $v->enum('role', true, Rbac::roles()),
            'beneficiary_id' => $v->id('beneficiary_id', false),
        ];

        // Powiązanie z użytkownikiem certyfikatu ma sens tylko dla pracownika (zakres personal).
        if ($values['role'] !== Rbac::EMPLOYEE) {
            $values['beneficiary_id'] = null;
        } elseif ($values['beneficiary_id'] !== null && !$this->isActiveBeneficiary($values['beneficiary_id'])) {
            $v->addError('beneficiary_id', 'account.error.beneficiary_unavailable');
        }

        if ($values['email'] !== '' && !isset($v->errors()['email'])) {
            $stmt = $this->db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) AND id <> :id LIMIT 1');
            $stmt->execute(['email' => $values['email'], 'id' => $exceptId ?? 0]);
            if ($stmt->fetchColumn() !== false) {
                $v->addError('email', 'account.error.email_taken');
            }
        }

        $v->throwIfFailed();

        return $values;
    }

    private function isActiveBeneficiary(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM beneficiaries WHERE id = :id AND archived_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    private function activeAdminCount(int $exceptId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND deactivated_at IS NULL AND id <> :id"
        );
        $stmt->execute(['id' => $exceptId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['beneficiary_id'] = $row['beneficiary_id'] !== null ? (int) $row['beneficiary_id'] : null;
        $row['beneficiary_name'] = $row['beneficiary_id'] !== null ? (string) $row['beneficiary_name'] : null;
        $row['certificate_count'] = (int) $row['certificate_count'];
        $row['open_task_count'] = (int) $row['open_task_count'];
        $row['name'] = trim($row['first_name'] . ' ' . $row['last_name']);
        $row['active'] = $row['deactivated_at'] === null;

        return $row;
    }
}

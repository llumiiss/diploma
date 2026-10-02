<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache;
use App\Settings;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Lista ToDo odnowień (F12, F18): zadania z priorytetami, zmiana statusu, przydział,
 * odnowienie certyfikatu i statystyki realizacji wg statusów.
 *
 * Statusy: todo → in_progress → done | abandoned. Zamknięte zadanie może ponownie otworzyć
 * MANAGER. Certyfikat ma najwyżej jedno otwarte zadanie (unikalny indeks w bazie).
 */
final class TaskService
{
    public const STATUSES = ['todo', 'in_progress', 'done', 'abandoned'];
    public const OPEN_STATUSES = ['todo', 'in_progress'];
    public const PRIORITIES = ['expired', 'critical', 'warning', 'ok'];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'todo'        => ['in_progress', 'done', 'abandoned'],
        'in_progress' => ['todo', 'done', 'abandoned'],
        'done'        => ['todo'],
        'abandoned'   => ['todo'],
    ];

    private readonly EventLogger $events;
    private readonly CertificateService $certificates;
    private readonly TimelineService $timeline;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null, ?CertificateService $certificates = null)
    {
        $this->events = $events ?? new EventLogger($db);
        $this->certificates = $certificates ?? new CertificateService($db, $this->events);
        $this->timeline = new TimelineService($db);
    }

    /**
     * @param array{status?: string, assignee?: string, priority?: string, q?: string, certificate_id?: int|null} $filters
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, array $filters = []): array
    {
        $actor->authorize('tasks.view');
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        [$company, $companyParams] = Visibility::companyFilter($actor, 'c.payer_id');
        $conditions = [$visibility, $company];
        $params += $companyParams;

        $status = (string) ($filters['status'] ?? 'open');
        if ($status === 'open') {
            $conditions[] = "t.status IN ('todo', 'in_progress')";
        } elseif ($status === 'closed') {
            $conditions[] = "t.status IN ('done', 'abandoned')";
        } elseif (in_array($status, self::STATUSES, true)) {
            $conditions[] = 't.status = :status';
            $params['status'] = $status;
        }

        $assignee = (string) ($filters['assignee'] ?? '');
        if ($assignee === 'me') {
            $conditions[] = 't.assigned_user_id = :assignee';
            $params['assignee'] = $actor->id;
        } elseif ($assignee === 'unassigned') {
            $conditions[] = 't.assigned_user_id IS NULL';
        } elseif (ctype_digit($assignee)) {
            $conditions[] = 't.assigned_user_id = :assignee';
            $params['assignee'] = (int) $assignee;
        }

        $priority = (string) ($filters['priority'] ?? '');
        if (in_array($priority, self::PRIORITIES, true)) {
            $conditions[] = 't.priority = :priority';
            $params['priority'] = $priority;
        }

        if (!empty($filters['certificate_id'])) {
            $conditions[] = 't.certificate_id = :certificate_id';
            $params['certificate_id'] = (int) $filters['certificate_id'];
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $conditions[] = "(c.name LIKE :q1 OR c.serial_number LIKE :q2 OR p.company_name LIKE :q3
                OR CONCAT(COALESCE(b.first_name, ''), ' ', COALESCE(b.last_name, '')) LIKE :q4)";
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }

        $sql = self::selectSql() . ' WHERE ' . implode(' AND ', $conditions)
            . " ORDER BY FIELD(t.status, 'in_progress', 'todo', 'done', 'abandoned'),
                        FIELD(t.priority, 'expired', 'critical', 'warning', 'ok'), t.due_date, t.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    /**
     * Szczegóły zadania z zaproszeniami i historią.
     *
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $id): array
    {
        $actor->authorize('tasks.view');
        $task = $this->findVisible($actor, $id);

        $stmt = $this->db->prepare(
            'SELECT i.id, i.recipient_type, i.recipient_email, i.recipient_name, i.subject, i.status, i.sent_at,
                    i.reminder_count, i.last_reminder_at, i.next_reminder_at, i.last_error, i.created_at
             FROM invitations i WHERE i.renewal_task_id = :id ORDER BY i.id DESC'
        );
        $stmt->execute(['id' => $id]);
        $task['invitations'] = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['reminder_count'] = (int) $row['reminder_count'];

            return $row;
        }, $stmt->fetchAll());

        $invitationIds = array_column($task['invitations'], 'id');
        $events = $this->timeline->forContext('certificate', (int) $task['certificate_id']);
        $task['events'] = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['entity_type'] === 'renewal_task' && $event['entity_id'] === $id)
                || ($event['entity_type'] === 'invitation' && in_array($event['entity_id'], $invitationIds, true))
        ));

        return $task;
    }

    /**
     * Statystyki realizacji zadań wg statusów (F18) w zakresie danych konta.
     *
     * @return array<string, mixed>
     */
    public function stats(Actor $actor): array
    {
        $actor->authorize('tasks.view');

        // Jak na pulpicie: klucz z rolą i kontem, bo zakres zadań zależy od przydziałów (D8).
        return Cache::remember(
            'task-stats:' . $actor->role . ':' . $actor->id . ':' . ($actor->companyIds === null ? 'all' : md5(implode(',', $actor->companyIds))),
            Cache::DEFAULT_TTL,
            fn (): array => $this->computeStats($actor)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeStats(Actor $actor): array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        [$company, $companyParams] = Visibility::companyFilter($actor, 'c.payer_id');
        $visibility .= ' AND ' . $company;
        $params += $companyParams;
        $from = "FROM renewal_tasks t INNER JOIN certificates c ON c.id = t.certificate_id
                 WHERE {$visibility}";

        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach ($this->pairs("SELECT t.status, COUNT(*) {$from} GROUP BY t.status", $params) as $key => $count) {
            $byStatus[$key] = $count;
        }

        $openByPriority = array_fill_keys(self::PRIORITIES, 0);
        foreach ($this->pairs("SELECT t.priority, COUNT(*) {$from} AND t.status IN ('todo', 'in_progress') GROUP BY t.priority", $params) as $key => $count) {
            $openByPriority[$key] = $count;
        }

        $closedLast30 = ['done' => 0, 'abandoned' => 0];
        foreach ($this->pairs(
            "SELECT t.status, COUNT(*) {$from} AND t.status IN ('done', 'abandoned')
               AND t.closed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY t.status",
            $params
        ) as $key => $count) {
            $closedLast30[$key] = $count;
        }

        $overdue = $this->scalar("SELECT COUNT(*) {$from} AND t.status IN ('todo', 'in_progress') AND t.due_date < CURDATE()", $params);
        $mine = $this->scalar(
            "SELECT COUNT(*) {$from} AND t.status IN ('todo', 'in_progress') AND t.assigned_user_id = :me",
            $params + ['me' => $actor->id]
        );
        $unassigned = $this->scalar("SELECT COUNT(*) {$from} AND t.status IN ('todo', 'in_progress') AND t.assigned_user_id IS NULL", $params);
        $avgDays = $this->db->prepare(
            "SELECT AVG(DATEDIFF(t.closed_at, t.created_at)) {$from} AND t.status = 'done' AND t.closed_at IS NOT NULL"
        );
        $avgDays->execute($params);
        $average = $avgDays->fetchColumn();

        $closed = $byStatus['done'] + $byStatus['abandoned'];
        $result = [
            'by_status'            => $byStatus,
            'open_by_priority'     => $openByPriority,
            'open'                 => $byStatus['todo'] + $byStatus['in_progress'],
            'overdue'              => $overdue,
            'mine_open'            => $mine,
            'unassigned_open'      => $unassigned,
            'closed_last_30_days'  => $closedLast30,
            'completion_rate'      => $closed > 0 ? round($byStatus['done'] / $closed * 100, 1) : null,
            'average_days_to_done' => $average !== null && $average !== false ? round((float) $average, 1) : null,
            'by_assignee'          => [],
        ];

        if ($actor->can('tasks.stats')) {
            // LEFT JOIN, bo zadanie może nie mieć przydziału.
            $stmt = $this->db->prepare(
                "SELECT t.assigned_user_id, u.first_name, u.last_name, t.status, COUNT(*) AS total
                 FROM renewal_tasks t
                 INNER JOIN certificates c ON c.id = t.certificate_id
                 LEFT JOIN users u ON u.id = t.assigned_user_id
                 WHERE {$visibility}
                 GROUP BY t.assigned_user_id, u.first_name, u.last_name, t.status
                 ORDER BY u.last_name, u.first_name"
            );
            $stmt->execute($params);

            $assignees = [];
            foreach ($stmt->fetchAll() as $row) {
                $key = $row['assigned_user_id'] === null ? 'unassigned' : (string) $row['assigned_user_id'];
                if (!isset($assignees[$key])) {
                    $assignees[$key] = [
                        'user_id' => $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null,
                        'name'    => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                        'counts'  => array_fill_keys(self::STATUSES, 0),
                    ];
                }
                $assignees[$key]['counts'][$row['status']] = (int) $row['total'];
            }
            $result['by_assignee'] = array_values($assignees);
        }

        return $result;
    }

    /**
     * Ręczne założenie zadania (np. wcześniejsze odnowienie) dla certyfikatu bez otwartego zadania.
     *
     * @param array<string, mixed> $data certificate_id, assigned_user_id?, note?
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $actor->authorize('tasks.update');
        $v = new Validator($data);
        $certificateId = $v->id('certificate_id', true);
        $assignee = $v->id('assigned_user_id', false);
        $note = $v->string('note', false, 2000);
        $v->throwIfFailed();

        $taskId = $this->openTaskFor($actor, (int) $certificateId, 'manual', $assignee, $note);

        return $this->get($actor, $taskId);
    }

    /**
     * Otwiera zadanie dla certyfikatu (ręcznie albo przy wysyłce zaproszenia).
     * Gdy certyfikat ma już otwarte zadanie, zgłasza konflikt ze szczegółami.
     */
    public function openTaskFor(Actor $actor, int $certificateId, string $source, ?int $assignee = null, ?string $note = null): int
    {
        $certificate = $this->certificates->findActive($actor, $certificateId);

        $existing = $this->openTaskId($certificateId);
        if ($existing !== null) {
            throw ServiceException::conflict(\__('task.error.open_exists'), ['existing' => ['id' => $existing]]);
        }

        if (!$actor->can('tasks.assign') || $assignee === null) {
            $assignee = $actor->can('tasks.assign') ? $this->activeOwner($certificate) : ($actor->isSystem() ? null : $actor->id);
        } elseif (!$this->isActiveAccount($assignee)) {
            throw ServiceException::validation(['assigned_user_id' => \__('task.error.assignee_unavailable')]);
        }

        $today = new DateTimeImmutable('today');
        $daysLeft = (int) $today->diff(new DateTimeImmutable((string) $certificate['expiry_date']))->format('%r%a');
        $priority = RenewalScanner::priorityFor($daysLeft, Settings::read($this->db)['renewal.critical_days']);

        try {
            $taskId = Transaction::run($this->db, function () use ($actor, $certificate, $certificateId, $assignee, $priority, $daysLeft, $source, $note): int {
                $this->db->prepare(
                    "INSERT INTO renewal_tasks (certificate_id, assigned_user_id, status, priority, due_date, resolution_note)
                     VALUES (:certificate_id, :assigned_user_id, 'todo', :priority, :due_date, NULL)"
                )->execute([
                    'certificate_id'   => $certificateId,
                    'assigned_user_id' => $assignee,
                    'priority'         => $priority,
                    'due_date'         => $certificate['expiry_date'],
                ]);
                $taskId = (int) $this->db->lastInsertId();

                $this->events->log('renewal_task', $taskId, 'task_opened', $actor->isSystem() ? null : $actor->id, self::context($certificate), array_filter([
                    'priority'         => $priority,
                    'days_left'        => $daysLeft,
                    'source'           => $source,
                    'assigned_user_id' => $assignee,
                    'note'             => $note,
                ], static fn (mixed $value): bool => $value !== null));

                return $taskId;
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ServiceException::conflict(\__('task.error.open_exists'));
            }
            throw $e;
        }

        $this->notifyAssignee($assignee, $actor, $certificate, $priority);

        return $taskId;
    }

    /**
     * Komunikat dla osoby, której przydzielono zadanie (nie dla tej, która sama je sobie przydzieliła).
     *
     * @param array<string, mixed> $certificate
     */
    private function notifyAssignee(?int $assignee, Actor $actor, array $certificate, string $priority): void
    {
        if ($assignee === null || $assignee === $actor->id) {
            return;
        }

        (new NotificationService($this->db))->notify(
            [$assignee],
            \__('notification.system.task_assigned_subject', ['name' => (string) $certificate['name']]),
            \__('notification.system.task_assigned_body', [
                'name'     => (string) $certificate['name'],
                'date'     => (string) $certificate['expiry_date'],
                'priority' => \__('priority.label.' . $priority),
            ]),
            'certificate',
            (int) $certificate['id']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function changeStatus(Actor $actor, int $id, string $status, ?string $note = null): array
    {
        $actor->authorize('tasks.update');
        $task = $this->findVisible($actor, $id);
        $from = (string) $task['status'];

        $v = new Validator(['status' => $status, 'note' => $note]);
        $status = (string) $v->enum('status', true, self::STATUSES);
        $note = $v->string('note', false, 2000);
        $v->throwIfFailed();

        if ($status === $from) {
            return $this->get($actor, $id);
        }

        if (!in_array($status, self::TRANSITIONS[$from], true)) {
            throw ServiceException::conflict(\__('task.error.transition', [
                'from' => \__('task.status.' . $from),
                'to'   => \__('task.status.' . $status),
            ]));
        }

        $closing = in_array($status, ['done', 'abandoned'], true);
        $reopening = in_array($from, ['done', 'abandoned'], true);

        if ($reopening) {
            $actor->authorize('tasks.assign');
            if ($task['certificate_archived_at'] !== null) {
                throw ServiceException::conflict(\__('common.error.archived_readonly'));
            }
            if ($this->openTaskId((int) $task['certificate_id']) !== null) {
                throw ServiceException::conflict(\__('task.error.open_exists'));
            }
        }

        if ($status === 'abandoned' && $note === null) {
            throw ServiceException::validation(['note' => \__('task.error.note_required')]);
        }

        Transaction::run($this->db, function () use ($actor, $task, $id, $from, $status, $note, $closing): void {
            $this->db->prepare(
                'UPDATE renewal_tasks
                 SET status = :status,
                     closed_at = ' . ($closing ? 'NOW()' : 'NULL') . ',
                     resolution_note = COALESCE(:note, resolution_note)
                 WHERE id = :id'
            )->execute(['status' => $status, 'note' => $note, 'id' => $id]);

            $payload = array_filter(['from' => $from, 'status' => $status, 'note' => $note], static fn (mixed $v): bool => $v !== null);
            $this->events->log('renewal_task', $id, $closing ? 'task_closed' : 'task_status_changed', $actor->id, self::context($task), $payload);

            if ($closing) {
                $this->closeOpenInvitations($actor, $task, $status === 'done' ? 'task_done' : 'task_abandoned');
            }

            $this->certificates->syncStatusWithTask($actor, (int) $task['certificate_id'], $status);
        });

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function assign(Actor $actor, int $id, ?int $userId): array
    {
        $actor->authorize('tasks.assign');
        $task = $this->findVisible($actor, $id);

        if (!in_array($task['status'], self::OPEN_STATUSES, true)) {
            throw ServiceException::conflict(\__('task.error.closed'));
        }

        $current = $task['assigned_user_id'];
        if ($current === $userId) {
            return $this->get($actor, $id);
        }

        $name = null;
        if ($userId !== null) {
            $stmt = $this->db->prepare('SELECT first_name, last_name FROM users WHERE id = :id AND deactivated_at IS NULL');
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();
            if ($user === false) {
                throw ServiceException::validation(['assigned_user_id' => \__('task.error.assignee_unavailable')]);
            }
            $name = trim($user['first_name'] . ' ' . $user['last_name']);
        }

        Transaction::run($this->db, function () use ($actor, $task, $id, $current, $userId, $name): void {
            $this->db->prepare('UPDATE renewal_tasks SET assigned_user_id = :user_id WHERE id = :id')
                ->execute(['user_id' => $userId, 'id' => $id]);
            $this->events->log('renewal_task', $id, 'task_assigned', $actor->id, self::context($task), [
                'from_user_id' => $current,
                'to_user_id'   => $userId,
                'to_name'      => $name,
                'from_name'    => $task['assignee_name'] !== '' ? $task['assignee_name'] : null,
            ]);
        });

        $this->notifyAssignee($userId, $actor, ['id' => $task['certificate_id'], 'name' => $task['certificate_name'], 'expiry_date' => $task['due_date']], (string) $task['priority']);

        return $this->get($actor, $id);
    }

    /**
     * Zakończenie zadania odnowieniem: nowy certyfikat zastępuje stary (archiwum), zadanie → zrobione.
     *
     * @param array<string, mixed> $data dane nowego certyfikatu (patrz CertificateService::renewFrom) + note
     * @return array{task: array<string, mixed>, certificate: array<string, mixed>}
     */
    public function renew(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('tasks.update');
        $task = $this->findVisible($actor, $id);

        if (!in_array($task['status'], self::OPEN_STATUSES, true)) {
            throw ServiceException::conflict(\__('task.error.closed'));
        }

        $note = isset($data['note']) && is_string($data['note']) && trim($data['note']) !== '' ? mb_substr(trim($data['note']), 0, 2000) : null;

        $newCertificateId = Transaction::run($this->db, function () use ($actor, $task, $id, $data, $note): int {
            $newId = $this->certificates->renewFrom($actor, (int) $task['certificate_id'], $data);

            $this->db->prepare(
                "UPDATE renewal_tasks SET status = 'done', closed_at = NOW(), resolution_note = COALESCE(:note, resolution_note) WHERE id = :id"
            )->execute(['note' => $note, 'id' => $id]);

            $this->events->log('renewal_task', $id, 'task_closed', $actor->id, self::context($task), array_filter([
                'from'               => $task['status'],
                'status'             => 'done',
                'new_certificate_id' => $newId,
                'note'               => $note,
            ], static fn (mixed $v): bool => $v !== null));

            $this->closeOpenInvitations($actor, $task, 'renewed');

            return $newId;
        });

        return [
            'task'        => $this->get($actor, $id),
            'certificate' => $this->certificates->get($actor, $newCertificateId),
        ];
    }

    /**
     * Otwarte zadanie certyfikatu po wysłaniu zaproszenia przechodzi do pracy „w toku”.
     */
    public function markInProgress(Actor $actor, int $taskId): void
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.status, t.certificate_id, c.beneficiary_id, c.payer_id
             FROM renewal_tasks t INNER JOIN certificates c ON c.id = t.certificate_id WHERE t.id = :id'
        );
        $stmt->execute(['id' => $taskId]);
        $task = $stmt->fetch();
        if ($task === false || $task['status'] !== 'todo') {
            return;
        }

        $this->db->prepare("UPDATE renewal_tasks SET status = 'in_progress' WHERE id = :id")->execute(['id' => $taskId]);
        $this->events->log('renewal_task', $taskId, 'task_status_changed', $actor->isSystem() ? null : $actor->id, self::context($task), [
            'from'   => 'todo',
            'status' => 'in_progress',
            'reason' => 'invitation_sent',
        ]);
        $this->certificates->syncStatusWithTask($actor, (int) $task['certificate_id'], 'in_progress');
    }

    public function openTaskId(int $certificateId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM renewal_tasks WHERE certificate_id = :id AND status IN ('todo', 'in_progress') LIMIT 1"
        );
        $stmt->execute(['id' => $certificateId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Zamknięcie zadania kończy wysyłanie przypomnień po jego zaproszeniach.
     *
     * @param array<string, mixed> $task
     */
    private function closeOpenInvitations(Actor $actor, array $task, string $reason): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM invitations WHERE renewal_task_id = :id AND status IN ('queued', 'sent')"
        );
        $stmt->execute(['id' => (int) $task['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $invitationId) {
            $this->db->prepare("UPDATE invitations SET status = 'closed', next_reminder_at = NULL WHERE id = :id")
                ->execute(['id' => (int) $invitationId]);
            $this->events->log('invitation', (int) $invitationId, 'invitation_closed', $actor->isSystem() ? null : $actor->id, self::context($task), [
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findVisible(Actor $actor, int $id): array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(self::selectSql() . " WHERE t.id = :id AND {$visibility} LIMIT 1");
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ServiceException::notFound(\__('task.error.not_found'));
        }

        return self::present($row);
    }

    /**
     * @param array<string, mixed> $certificate
     */
    private function activeOwner(array $certificate): ?int
    {
        return $this->isActiveAccount((int) $certificate['user_id']) ? (int) $certificate['user_id'] : null;
    }

    private function isActiveAccount(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE id = :id AND deactivated_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int>
     */
    private function pairs(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function scalar(string $sql, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private static function selectSql(): string
    {
        return 'SELECT
                t.id, t.certificate_id, t.assigned_user_id, t.status, t.priority, t.due_date, t.resolution_note,
                t.closed_at, t.created_at, t.updated_at,
                c.name AS certificate_name, c.certificate_type, c.serial_number, c.issuer, c.expiry_date,
                c.status AS certificate_status, c.user_id AS owner_id, c.beneficiary_id, c.payer_id,
                c.archived_at AS certificate_archived_at,
                b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name, b.email AS beneficiary_email,
                p.company_name AS payer_name, p.email AS payer_email,
                au.first_name AS assignee_first_name, au.last_name AS assignee_last_name,
                ou.first_name AS owner_first_name, ou.last_name AS owner_last_name,
                (SELECT COUNT(*) FROM invitations i WHERE i.renewal_task_id = t.id) AS invitation_count,
                (SELECT i2.status FROM invitations i2 WHERE i2.renewal_task_id = t.id ORDER BY i2.id DESC LIMIT 1) AS last_invitation_status,
                (SELECT i3.sent_at FROM invitations i3 WHERE i3.renewal_task_id = t.id ORDER BY i3.id DESC LIMIT 1) AS last_invitation_sent_at
            FROM renewal_tasks t
            INNER JOIN certificates c ON c.id = t.certificate_id
            INNER JOIN payers p ON p.id = c.payer_id
            INNER JOIN users ou ON ou.id = c.user_id
            LEFT JOIN beneficiaries b ON b.id = c.beneficiary_id
            LEFT JOIN users au ON au.id = t.assigned_user_id';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        foreach (['id', 'certificate_id', 'owner_id', 'payer_id', 'invitation_count'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['assigned_user_id', 'beneficiary_id'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }

        $today = new DateTimeImmutable('today');
        $row['days_left'] = (int) $today->diff(new DateTimeImmutable((string) $row['due_date']))->format('%r%a');
        $row['assignee_name'] = trim(($row['assignee_first_name'] ?? '') . ' ' . ($row['assignee_last_name'] ?? ''));
        $row['owner_name'] = trim($row['owner_first_name'] . ' ' . $row['owner_last_name']);
        $row['beneficiary_name'] = trim(($row['beneficiary_first_name'] ?? '') . ' ' . ($row['beneficiary_last_name'] ?? ''));
        $row['is_open'] = in_array($row['status'], self::OPEN_STATUSES, true);
        $row['is_overdue'] = $row['is_open'] && $row['days_left'] < 0;
        unset($row['assignee_first_name'], $row['assignee_last_name'], $row['owner_first_name'], $row['owner_last_name']);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{certificate_id: int, beneficiary_id: int|null, payer_id: int|null}
     */
    private static function context(array $row): array
    {
        return [
            'certificate_id' => (int) ($row['certificate_id'] ?? $row['id']),
            'beneficiary_id' => isset($row['beneficiary_id']) ? (int) $row['beneficiary_id'] : null,
            'payer_id'       => isset($row['payer_id']) ? (int) $row['payer_id'] : null,
        ];
    }
}

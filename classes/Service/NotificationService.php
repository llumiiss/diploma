<?php

declare(strict_types=1);

namespace App\Service;

use App\Rbac;
use PDO;

/**
 * Powiadomienia wewnętrzne między kontami (Etap 10): prośby o uzupełnienie informacji, zgłoszenia błędów,
 * zwykłe wiadomości i komunikaty systemowe. Przeznaczone głównie do komunikacji administratora z operatorami,
 * ale dostępne dla każdej roli (uprawnienie notifications.use).
 *
 * Model: jeden wiersz to jedna wiadomość dla jednego odbiorcy. Wiadomość do kilku osób to kilka wierszy
 * o wspólnym batch_key, odpowiedzi tworzą wątek (thread_id wskazuje wiadomość początkową). Odbiorca może
 * oznaczyć wiadomość jako przeczytaną, zarchiwizować ją, a prośbę lub zgłoszenie — jako załatwione
 * (nadawca dostaje wtedy odpowiedź).
 */
final class NotificationService
{
    /** Typy, które może wysłać użytkownik; „system” tworzy tylko aplikacja. */
    public const USER_TYPES = ['message', 'request', 'error'];
    public const TYPES = ['message', 'request', 'error', 'system'];
    public const RELATED_TYPES = ['certificate', 'beneficiary', 'payer', 'registration'];
    public const GROUP_ADMINS = 'admins';
    public const GROUP_ALL = 'all';
    public const MAX_RECIPIENTS = 200;

    public function __construct(private readonly PDO $db)
    {
    }

    // ------------------------------------------------------------------ odczyt

    /**
     * Wiadomości odebrane przez konto (od najnowszych).
     *
     * @param array{status?: string, type?: string, q?: string} $filters status: unread | open | all
     * @return list<array<string, mixed>>
     */
    public function inbox(Actor $actor, array $filters = []): array
    {
        $actor->authorize('notifications.use');

        $conditions = ['n.recipient_user_id = :me', 'n.archived_at IS NULL'];
        $params = ['me' => $actor->id];

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'unread') {
            $conditions[] = 'n.read_at IS NULL';
        } elseif ($status === 'open') {
            $conditions[] = "n.type IN ('request', 'error') AND n.resolved_at IS NULL";
        }

        $type = (string) ($filters['type'] ?? '');
        if (in_array($type, self::TYPES, true)) {
            $conditions[] = 'n.type = :type';
            $params['type'] = $type;
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $conditions[] = '(n.subject LIKE :q1 OR n.body LIKE :q2)';
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $params += ['q1' => $like, 'q2' => $like];
        }

        $stmt = $this->db->prepare(
            self::selectSql() . ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY n.created_at DESC, n.id DESC LIMIT 300'
        );
        $stmt->execute($params);

        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    /**
     * Wiadomości wysłane przez konto, zebrane po wysyłce (jedna pozycja na wysyłkę do wielu osób).
     *
     * @return list<array<string, mixed>>
     */
    public function sent(Actor $actor): array
    {
        $actor->authorize('notifications.use');

        $stmt = $this->db->prepare(
            "SELECT MIN(n.id) AS id, n.batch_key, MIN(n.thread_id) AS thread_id, n.type, n.subject, n.body,
                    n.related_type, n.related_id, MIN(n.created_at) AS created_at, COUNT(*) AS recipient_count,
                    SUM(CASE WHEN n.read_at IS NULL THEN 0 ELSE 1 END) AS read_count,
                    GROUP_CONCAT(CONCAT_WS(' ', r.first_name, r.last_name) ORDER BY r.last_name SEPARATOR ', ') AS recipients
             FROM notifications n
             INNER JOIN users r ON r.id = n.recipient_user_id
             WHERE n.sender_user_id = :me
             GROUP BY n.batch_key, n.type, n.subject, n.body, n.related_type, n.related_id
             ORDER BY created_at DESC, id DESC
             LIMIT 300"
        );
        $stmt->execute(['me' => $actor->id]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['thread_id'] = $row['thread_id'] !== null ? (int) $row['thread_id'] : (int) $row['id'];
            $row['recipient_count'] = (int) $row['recipient_count'];
            $row['read_count'] = (int) $row['read_count'];
            $row['related_id'] = $row['related_id'] !== null ? (int) $row['related_id'] : null;
            unset($row['batch_key']);

            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * Liczba nieprzeczytanych i nierozwiązanych próśb — do znaczka w menu.
     *
     * @return array{unread: int, open_requests: int}
     */
    public function counters(Actor $actor): array
    {
        $actor->authorize('notifications.use');

        $stmt = $this->db->prepare(
            "SELECT SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread,
                    SUM(CASE WHEN type IN ('request', 'error') AND resolved_at IS NULL THEN 1 ELSE 0 END) AS open_requests
             FROM notifications WHERE recipient_user_id = :me AND archived_at IS NULL"
        );
        $stmt->execute(['me' => $actor->id]);
        $row = $stmt->fetch();

        return ['unread' => (int) ($row['unread'] ?? 0), 'open_requests' => (int) ($row['open_requests'] ?? 0)];
    }

    /**
     * Wątek, do którego należy wiadomość (widoczne tylko wiadomości, które konto wysłało albo odebrało).
     * Otwarcie wątku oznacza odebrane w nim wiadomości jako przeczytane.
     *
     * @return array{thread_id: int, subject: string, messages: list<array<string, mixed>>}
     */
    public function thread(Actor $actor, int $id): array
    {
        $actor->authorize('notifications.use');
        $row = $this->findOwn($actor, $id);
        $threadId = (int) ($row['thread_id'] ?? $row['id']);

        $stmt = $this->db->prepare(
            self::selectSql() . ' WHERE (n.thread_id = :thread OR n.id = :thread_root)
                AND (n.recipient_user_id = :me OR n.sender_user_id = :me2)
             ORDER BY n.created_at, n.id'
        );
        $stmt->execute(['thread' => $threadId, 'thread_root' => $threadId, 'me' => $actor->id, 'me2' => $actor->id]);
        $messages = array_map(fn (array $message): array => self::present($message) + ['mine' => (int) $message['sender_user_id'] === $actor->id], $stmt->fetchAll());

        $this->db->prepare(
            'UPDATE notifications SET read_at = NOW() WHERE recipient_user_id = :me AND read_at IS NULL
                AND (thread_id = :thread OR id = :thread_root)'
        )->execute(['me' => $actor->id, 'thread' => $threadId, 'thread_root' => $threadId]);

        return [
            'thread_id' => $threadId,
            'subject'   => (string) ($messages[0]['subject'] ?? $row['subject']),
            'messages'  => $messages,
        ];
    }

    /**
     * Osoby i grupy, do których konto może pisać. Administrator pisze do wszystkich, pracownik tylko do
     * administratorów i menedżerów, pozostałe role do całego personelu poza pracownikami.
     *
     * @return array{users: list<array{id: int, name: string, role: string}>, groups: list<string>}
     */
    public function recipients(Actor $actor): array
    {
        $actor->authorize('notifications.use');

        $stmt = $this->db->prepare(
            'SELECT id, first_name, last_name, role FROM users
             WHERE deactivated_at IS NULL AND email_verified_at IS NOT NULL AND id <> :me
             ORDER BY last_name, first_name'
        );
        $stmt->execute(['me' => $actor->id]);

        $users = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!self::mayMessage($actor, (string) $row['role'])) {
                continue;
            }
            $users[] = ['id' => (int) $row['id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name']), 'role' => (string) $row['role']];
        }

        $groups = [self::GROUP_ADMINS];
        if ($actor->can('notifications.broadcast')) {
            $groups[] = self::GROUP_ALL;
        }

        return ['users' => $users, 'groups' => $groups];
    }

    // ----------------------------------------------------------------- zapis

    /**
     * Wysyła wiadomość do osób i grup. Odbiorcy spoza dozwolonego zakresu są pomijani w komunikacie
     * o błędzie, a nie po cichu — nadawca ma wiedzieć, że wiadomość do kogoś nie dotrze.
     *
     * @param array<string, mixed> $data to (lista identyfikatorów kont), groups (admins | all), type, subject,
     *                                   body, related_type, related_id
     * @return array{recipients: int, thread_id: int}
     */
    public function send(Actor $actor, array $data): array
    {
        $actor->authorize('notifications.use');
        if ($actor->isSystem()) {
            throw ServiceException::badRequest(\__('api.error.unknown_action'));
        }

        $v = new Validator($data);
        $type = (string) $v->enum('type', false, self::USER_TYPES, 'message');
        $subject = (string) $v->string('subject', true, 255);
        $body = (string) $v->string('body', true, 5000);
        [$relatedType, $relatedId] = $this->related($v, $actor);
        $recipientIds = $this->resolveRecipients($actor, $data, $v);
        $v->throwIfFailed();

        $batch = bin2hex(random_bytes(8));
        $firstThread = 0;
        Transaction::run($this->db, function () use ($actor, $recipientIds, $type, $subject, $body, $relatedType, $relatedId, $batch, &$firstThread): void {
            foreach ($recipientIds as $recipientId) {
                $id = $this->insert($batch, null, null, $actor->id, $recipientId, $type, $subject, $body, $relatedType, $relatedId);
                $firstThread = $firstThread === 0 ? $id : $firstThread;
            }
        });

        return ['recipients' => count($recipientIds), 'thread_id' => $firstThread];
    }

    /**
     * Odpowiedź w wątku: trafia do drugiej strony rozmowy (nadawcy odebranej wiadomości albo odbiorcy własnej).
     *
     * @return array{thread_id: int, id: int}
     */
    public function reply(Actor $actor, int $id, string $body): array
    {
        $actor->authorize('notifications.use');
        $row = $this->findOwn($actor, $id);

        $v = new Validator(['body' => $body]);
        $text = (string) $v->string('body', true, 5000);
        $v->throwIfFailed();

        $other = (int) $row['sender_user_id'] === $actor->id ? (int) $row['recipient_user_id'] : (int) ($row['sender_user_id'] ?? 0);
        if ($other === 0) {
            throw ServiceException::conflict(\__('notification.error.no_reply'));
        }
        $this->assertActiveRecipient($other);

        $threadId = (int) ($row['thread_id'] ?? $row['id']);
        $subject = (string) $row['subject'];
        if (!str_starts_with($subject, 'Re: ')) {
            $subject = mb_substr('Re: ' . $subject, 0, 255);
        }

        $newId = $this->insert(
            bin2hex(random_bytes(8)),
            $threadId,
            (int) $row['id'],
            $actor->id,
            $other,
            'message',
            $subject,
            $text,
            $row['related_type'],
            $row['related_id'] !== null ? (int) $row['related_id'] : null
        );

        return ['thread_id' => $threadId, 'id' => $newId];
    }

    /**
     * @param list<int> $ids pusta lista = wszystkie nieprzeczytane
     */
    public function markRead(Actor $actor, array $ids = []): int
    {
        $actor->authorize('notifications.use');

        if ($ids === []) {
            $stmt = $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE recipient_user_id = :me AND read_at IS NULL');
            $stmt->execute(['me' => $actor->id]);

            return $stmt->rowCount();
        }

        $count = 0;
        $stmt = $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE id = :id AND recipient_user_id = :me AND read_at IS NULL');
        foreach ($ids as $id) {
            $stmt->execute(['id' => (int) $id, 'me' => $actor->id]);
            $count += $stmt->rowCount();
        }

        return $count;
    }

    public function markUnread(Actor $actor, int $id): void
    {
        $actor->authorize('notifications.use');
        $row = $this->findOwn($actor, $id);
        if ((int) $row['recipient_user_id'] !== $actor->id) {
            throw ServiceException::notFound(\__('notification.error.not_found'));
        }

        $this->db->prepare('UPDATE notifications SET read_at = NULL WHERE id = :id')->execute(['id' => $id]);
    }

    public function archive(Actor $actor, int $id): void
    {
        $actor->authorize('notifications.use');
        $row = $this->findOwn($actor, $id);
        if ((int) $row['recipient_user_id'] !== $actor->id) {
            throw ServiceException::notFound(\__('notification.error.not_found'));
        }

        $this->db->prepare('UPDATE notifications SET archived_at = NOW(), read_at = COALESCE(read_at, NOW()) WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Prośba albo zgłoszenie załatwione. Nadawca dostaje o tym wiadomość.
     */
    public function resolve(Actor $actor, int $id): void
    {
        $actor->authorize('notifications.use');
        $row = $this->findOwn($actor, $id);
        if ((int) $row['recipient_user_id'] !== $actor->id || !in_array($row['type'], ['request', 'error'], true)) {
            throw ServiceException::notFound(\__('notification.error.not_found'));
        }
        if ($row['resolved_at'] !== null) {
            throw ServiceException::conflict(\__('notification.error.already_resolved'));
        }

        Transaction::run($this->db, function () use ($actor, $row, $id): void {
            $this->db->prepare('UPDATE notifications SET resolved_at = NOW(), read_at = COALESCE(read_at, NOW()) WHERE id = :id')
                ->execute(['id' => $id]);

            if ($row['sender_user_id'] !== null) {
                $this->insert(
                    bin2hex(random_bytes(8)),
                    (int) ($row['thread_id'] ?? $row['id']),
                    $id,
                    $actor->id,
                    (int) $row['sender_user_id'],
                    'message',
                    mb_substr(\__('notification.resolved_subject', ['subject' => (string) $row['subject']]), 0, 255),
                    \__('notification.resolved_body', ['name' => $actor->fullName()]),
                    $row['related_type'],
                    $row['related_id'] !== null ? (int) $row['related_id'] : null
                );
            }
        });
    }

    // ------------------------------------------------- komunikaty z aplikacji

    /**
     * Komunikat systemowy dla wskazanych kont — wywoływany z usług (nowy wniosek, przydział zadania…).
     * Bez nadawcy i bez kontroli uprawnień; konta wyłączone są pomijane.
     *
     * @param list<int> $userIds
     */
    public function notify(array $userIds, string $subject, string $body, ?string $relatedType = null, ?int $relatedId = null, string $type = 'system'): int
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return 0;
        }

        $batch = bin2hex(random_bytes(8));
        $count = 0;
        foreach ($userIds as $userId) {
            if (!$this->isActiveUser($userId)) {
                continue;
            }
            $this->insert($batch, null, null, null, $userId, $type, mb_substr($subject, 0, 255), mb_substr($body, 0, 5000), $relatedType, $relatedId);
            ++$count;
        }

        return $count;
    }

    /**
     * Aktywne konta, które mają dane uprawnienie — adresaci komunikatów systemowych.
     *
     * @return list<int>
     */
    public function usersWithPermission(string $permission): array
    {
        $stmt = $this->db->query('SELECT id, role FROM users WHERE deactivated_at IS NULL AND email_verified_at IS NOT NULL');
        $ids = [];
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $row) {
            if (Rbac::can((string) $row['role'], $permission)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    // ----------------------------------------------------------------- szczegóły

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function related(Validator $v, Actor $actor): array
    {
        $type = $v->enum('related_type', false, self::RELATED_TYPES);
        $id = $v->id('related_id', false);
        if ($type === null || $id === null) {
            return [null, null];
        }

        $table = match ($type) {
            'certificate' => 'certificates',
            'beneficiary' => 'beneficiaries',
            'payer'       => 'payers',
            default       => null,
        };
        if ($table !== null) {
            // Wiadomość „o rekordzie” może dotyczyć tylko rekordu, który nadawca widzi.
            [$visibility, $params] = match ($type) {
                'certificate' => Visibility::certificates($actor, 'r'),
                'beneficiary' => Visibility::beneficiaries($actor, 'r'),
                default       => Visibility::payers($actor, 'r'),
            };
            $stmt = $this->db->prepare("SELECT 1 FROM {$table} r WHERE r.id = :id AND {$visibility} LIMIT 1");
            $stmt->execute(['id' => $id] + $params);
            if ($stmt->fetchColumn() === false) {
                $v->addError('related_id', 'notification.error.related_unavailable');

                return [null, null];
            }
        }

        return [$type, $id];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<int>
     */
    private function resolveRecipients(Actor $actor, array $data, Validator $v): array
    {
        $allowed = $this->recipients($actor);
        $allowedIds = array_column($allowed['users'], 'id');
        $ids = [];

        $requested = is_array($data['to'] ?? null) ? $data['to'] : [];
        foreach ($requested as $candidate) {
            if (!(is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) || (int) $candidate <= 0) {
                continue;
            }
            if (!in_array((int) $candidate, $allowedIds, true)) {
                $v->addError('to', 'notification.error.recipient_unavailable');

                return [];
            }
            $ids[(int) $candidate] = (int) $candidate;
        }

        $groups = is_array($data['groups'] ?? null) ? $data['groups'] : [];
        foreach ($groups as $group) {
            if (!in_array($group, $allowed['groups'], true)) {
                $v->addError('to', 'notification.error.recipient_unavailable');

                return [];
            }
            $stmt = $this->db->prepare(
                'SELECT id, role FROM users WHERE deactivated_at IS NULL AND email_verified_at IS NOT NULL AND id <> :me'
            );
            $stmt->execute(['me' => $actor->id]);
            foreach ($stmt->fetchAll() as $row) {
                if ($group === self::GROUP_ALL || ($group === self::GROUP_ADMINS && $row['role'] === Rbac::ADMIN)) {
                    $ids[(int) $row['id']] = (int) $row['id'];
                }
            }
        }

        $ids = array_values($ids);
        if ($ids === []) {
            $v->addError('to', 'notification.error.no_recipients');
        } elseif (count($ids) > self::MAX_RECIPIENTS) {
            $v->addError('to', 'notification.error.too_many');
        }

        return $ids;
    }

    private static function mayMessage(Actor $actor, string $role): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if ($actor->role === Rbac::EMPLOYEE) {
            return in_array($role, [Rbac::ADMIN, Rbac::MANAGER], true);
        }

        return $role !== Rbac::EMPLOYEE;
    }

    private function assertActiveRecipient(int $userId): void
    {
        if (!$this->isActiveUser($userId)) {
            throw ServiceException::conflict(\__('notification.error.recipient_inactive'));
        }
    }

    private function isActiveUser(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE id = :id AND deactivated_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    private function insert(
        string $batch,
        ?int $threadId,
        ?int $parentId,
        ?int $senderId,
        int $recipientId,
        string $type,
        string $subject,
        string $body,
        ?string $relatedType,
        ?int $relatedId,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO notifications (batch_key, thread_id, parent_id, sender_user_id, recipient_user_id, type, subject, body,
                related_type, related_id, created_at)
             VALUES (:batch, :thread, :parent, :sender, :recipient, :type, :subject, :body, :related_type, :related_id, NOW())'
        );
        $stmt->execute([
            'batch'        => $batch,
            'thread'       => $threadId,
            'parent'       => $parentId,
            'sender'       => $senderId !== null && $senderId > 0 ? $senderId : null,
            'recipient'    => $recipientId,
            'type'         => $type,
            'subject'      => $subject,
            'body'         => $body,
            'related_type' => $relatedType,
            'related_id'   => $relatedId,
        ]);
        $id = (int) $this->db->lastInsertId();

        if ($threadId === null) {
            $this->db->prepare('UPDATE notifications SET thread_id = id WHERE id = :id')->execute(['id' => $id]);
        }

        return $id;
    }

    /**
     * Wiadomość, którą konto wysłało albo odebrało — cudzych wiadomości nie da się odczytać.
     *
     * @return array<string, mixed>
     */
    private function findOwn(Actor $actor, int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM notifications WHERE id = :id AND (recipient_user_id = :me OR sender_user_id = :me2) LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'me' => $actor->id, 'me2' => $actor->id]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ServiceException::notFound(\__('notification.error.not_found'));
        }

        return $row;
    }

    private static function selectSql(): string
    {
        return "SELECT n.id, n.thread_id, n.parent_id, n.sender_user_id, n.recipient_user_id, n.type, n.subject, n.body,
                    n.related_type, n.related_id, n.read_at, n.resolved_at, n.archived_at, n.created_at,
                    CONCAT_WS(' ', s.first_name, s.last_name) AS sender_name, s.role AS sender_role,
                    CONCAT_WS(' ', r.first_name, r.last_name) AS recipient_name
                FROM notifications n
                LEFT JOIN users s ON s.id = n.sender_user_id
                INNER JOIN users r ON r.id = n.recipient_user_id";
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        foreach (['id', 'recipient_user_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['thread_id', 'parent_id', 'sender_user_id', 'related_id'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        $row['thread_id'] ??= $row['id'];
        $row['sender_name'] = $row['sender_user_id'] !== null ? (string) $row['sender_name'] : null;
        $row['unread'] = $row['read_at'] === null;
        $row['open'] = in_array($row['type'], ['request', 'error'], true) && $row['resolved_at'] === null;

        return $row;
    }
}

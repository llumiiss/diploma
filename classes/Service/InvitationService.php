<?php

declare(strict_types=1);

namespace App\Service;

use App\Settings;
use PDO;
use Throwable;

/**
 * Zaproszenia do odnowienia i przypomnienia (F13, F14).
 *
 * Wysyłka: szablon wypełniony danymi certyfikatu → e-mail z załącznikami do użytkownika certyfikatu
 * albo płatnika. Każda wysyłka trafia do rejestru (tabela invitations) z treścią z chwili wysyłki,
 * a zadanie odnowienia przechodzi do pracy „w toku”. Przypomnienia wysyła cron według ustawień
 * (odstęp i limit) albo osoba prowadząca sprawę ręcznie.
 */
final class InvitationService
{
    public const STATUSES = ['queued', 'sent', 'failed', 'responded', 'closed'];
    public const RECIPIENT_TYPES = ['beneficiary', 'payer'];

    private readonly EventLogger $events;
    private readonly CertificateService $certificates;
    private readonly TaskService $tasks;
    private readonly TemplateService $templates;
    private readonly AttachmentService $attachments;
    private readonly InvitationMailer $mailer;
    private readonly TimelineService $timeline;

    public function __construct(
        private readonly PDO $db,
        ?InvitationMailer $mailer = null,
        ?AttachmentService $attachments = null,
        ?EventLogger $events = null,
    ) {
        $this->events = $events ?? new EventLogger($db);
        $this->certificates = new CertificateService($db, $this->events);
        $this->tasks = new TaskService($db, $this->events, $this->certificates);
        $this->templates = new TemplateService($db, $this->events);
        $this->attachments = $attachments ?? new AttachmentService($db, null, $this->events);
        $this->mailer = $mailer ?? new MailerInvitationMailer();
        $this->timeline = new TimelineService($db);
    }

    /**
     * Rejestr zaproszeń w zakresie danych konta.
     *
     * @param array{status?: string, certificate_id?: int|null, task_id?: int|null, due?: bool} $filters
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, array $filters = []): array
    {
        $actor->authorize('invitations.view');
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $conditions = [$visibility];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $conditions[] = 'i.status = :status';
            $params['status'] = $status;
        } elseif ($status === 'active') {
            $conditions[] = "i.status IN ('queued', 'sent', 'failed')";
        }

        if (!empty($filters['due'])) {
            $conditions[] = "i.status = 'sent' AND i.next_reminder_at IS NOT NULL AND i.next_reminder_at <= NOW()";
        }
        if (!empty($filters['certificate_id'])) {
            $conditions[] = 'i.certificate_id = :certificate_id';
            $params['certificate_id'] = (int) $filters['certificate_id'];
        }
        if (!empty($filters['task_id'])) {
            $conditions[] = 'i.renewal_task_id = :task_id';
            $params['task_id'] = (int) $filters['task_id'];
        }

        $stmt = $this->db->prepare(
            self::selectSql() . ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY COALESCE(i.sent_at, i.created_at) DESC, i.id DESC'
        );
        $stmt->execute($params);

        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $id): array
    {
        $actor->authorize('invitations.view');
        $invitation = $this->findVisible($actor, $id);

        $stmt = $this->db->prepare(
            'SELECT a.id, a.original_name, a.mime_type, a.size_bytes
             FROM invitation_attachments ia INNER JOIN attachments a ON a.id = ia.attachment_id
             WHERE ia.invitation_id = :id ORDER BY a.original_name'
        );
        $stmt->execute(['id' => $id]);
        $invitation['attachments'] = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['size_bytes'] = (int) $row['size_bytes'];

            return $row;
        }, $stmt->fetchAll());

        $invitation['body_text'] = TemplateRenderer::htmlToText((string) $invitation['body_html']);
        $invitation['events'] = array_values(array_filter(
            $this->timeline->forContext('certificate', (int) $invitation['certificate_id']),
            static fn (array $event): bool => $event['entity_type'] === 'invitation' && $event['entity_id'] === $id
        ));

        return $invitation;
    }

    /**
     * Dane do okna wysyłki: odbiorcy z adresami, szablony, biblioteka załączników, otwarte zadanie.
     *
     * @return array<string, mixed>
     */
    public function composeOptions(Actor $actor, int $certificateId): array
    {
        $actor->authorize('invitations.send');
        $certificate = $this->certificates->findActive($actor, $certificateId);

        $recipients = [];
        foreach (self::RECIPIENT_TYPES as $type) {
            $recipient = $this->recipientOrNull($certificate, $type);
            $recipients[] = [
                'type'  => $type,
                'name'  => $recipient['name'] ?? ($type === 'payer' ? (string) $certificate['company_name'] : (string) $certificate['beneficiary_name']),
                'email' => $recipient['email'] ?? null,
            ];
        }

        $templates = $this->templates->options($actor);
        $defaultTemplate = null;
        foreach ($templates as $template) {
            if ($template['code'] === TemplateService::CODE_INVITATION && ($defaultTemplate === null || $template['locale'] === 'pl')) {
                $defaultTemplate = $template['id'];
            }
        }

        $taskId = $this->tasks->openTaskId($certificateId);

        return [
            'certificate'         => [
                'id'          => (int) $certificate['id'],
                'name'        => $certificate['name'],
                'expiry_date' => $certificate['expiry_date'],
                'days_left'   => $certificate['days_left'],
            ],
            'recipients'          => $recipients,
            'templates'           => $templates,
            'default_template_id' => $defaultTemplate,
            'attachments'         => $this->attachments->list($actor),
            'open_task_id'        => $taskId,
        ];
    }

    /**
     * Podgląd wiadomości wypełnionej danymi certyfikatu i odbiorcy.
     *
     * @param array<string, mixed> $data certificate_id, template_id, recipient_type
     * @return array<string, mixed>
     */
    public function preview(Actor $actor, array $data): array
    {
        $actor->authorize('invitations.send');

        $v = new Validator($data);
        $certificateId = $v->id('certificate_id', true);
        $templateId = $v->id('template_id', true);
        $recipientType = $v->enum('recipient_type', true, self::RECIPIENT_TYPES);
        $v->throwIfFailed();

        $certificate = $this->certificates->findActive($actor, (int) $certificateId);
        $template = $this->templates->find((int) $templateId);
        if (!$template['is_active']) {
            throw ServiceException::validation(['template_id' => \__('invitation.error.template_inactive')]);
        }

        $recipient = $this->recipientOrNull($certificate, (string) $recipientType);
        if ($recipient === null) {
            throw ServiceException::validation(['recipient_type' => \__('invitation.error.no_email')]);
        }

        $rendered = TemplateRenderer::render(
            (string) $template['subject'],
            (string) $template['body_html'],
            $template['body_text'] !== null ? (string) $template['body_text'] : null,
            TemplateRenderer::values($certificate, $recipient, (string) $template['locale'])
        );

        return $rendered + [
            'recipient'      => $recipient + ['type' => $recipientType],
            'template'       => ['id' => $template['id'], 'name' => $template['name'], 'locale' => $template['locale']],
            'attachment_ids' => $this->templates->attachmentIds((int) $template['id']),
            'certificate'    => $certificate,
        ];
    }

    /**
     * @param array<string, mixed> $data certificate_id, template_id, recipient_type, attachment_ids[]
     * @return array<string, mixed>
     */
    public function send(Actor $actor, array $data): array
    {
        $preview = $this->preview($actor, $data);
        $certificate = $preview['certificate'];
        $attachmentIds = self::ids($data['attachment_ids'] ?? $preview['attachment_ids']);
        $files = $this->attachments->filesFor($attachmentIds);

        [$invitationId, $taskId] = Transaction::run($this->db, function () use ($actor, $preview, $certificate, $attachmentIds): array {
            $taskId = $this->tasks->openTaskId((int) $certificate['id'])
                ?? $this->tasks->openTaskFor($actor, (int) $certificate['id'], 'invitation');

            $this->db->prepare(
                "INSERT INTO invitations (certificate_id, renewal_task_id, template_id, recipient_type, recipient_email, recipient_name,
                    subject, body_html, status, sent_by_user_id)
                 VALUES (:certificate_id, :task_id, :template_id, :recipient_type, :recipient_email, :recipient_name,
                    :subject, :body_html, 'queued', :user_id)"
            )->execute([
                'certificate_id'  => (int) $certificate['id'],
                'task_id'         => $taskId,
                'template_id'     => $preview['template']['id'],
                'recipient_type'  => $preview['recipient']['type'],
                'recipient_email' => $preview['recipient']['email'],
                'recipient_name'  => $preview['recipient']['name'],
                'subject'         => $preview['subject'],
                'body_html'       => $preview['body_html'],
                'user_id'         => $actor->isSystem() ? null : $actor->id,
            ]);
            $invitationId = (int) $this->db->lastInsertId();

            $insert = $this->db->prepare('INSERT INTO invitation_attachments (invitation_id, attachment_id) VALUES (:invitation_id, :attachment_id)');
            foreach ($attachmentIds as $attachmentId) {
                $insert->execute(['invitation_id' => $invitationId, 'attachment_id' => $attachmentId]);
            }

            return [$invitationId, $taskId];
        });

        // Wysyłka poza transakcją — nieudana próba zostaje w rejestrze ze statusem i treścią błędu.
        $delivered = $this->deliver($actor, $invitationId, $certificate, $preview['recipient'], $preview['subject'], $preview['body_html'], $preview['body_text'], $files, [
            'template_id' => $preview['template']['id'],
            'attachments' => count($files),
        ]);

        if ($delivered) {
            $this->tasks->markInProgress($actor, $taskId);
        }

        return $this->get($actor, $invitationId);
    }

    /**
     * Ręczne przypomnienie (według szablonu przypomnienia w języku zaproszenia).
     *
     * @return array<string, mixed>
     */
    public function remind(Actor $actor, int $id): array
    {
        $actor->authorize('invitations.send');
        $invitation = $this->findVisible($actor, $id);

        if ($invitation['status'] !== 'sent') {
            throw ServiceException::conflict(\__('invitation.error.not_sent'));
        }

        $this->sendReminder($actor, $invitation);

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(Actor $actor, int $id): array
    {
        $actor->authorize('invitations.send');
        $invitation = $this->findVisible($actor, $id);

        if ($invitation['status'] !== 'failed') {
            throw ServiceException::conflict(\__('invitation.error.not_failed'));
        }

        $certificate = $this->certificates->findActive($actor, (int) $invitation['certificate_id']);
        $files = $this->attachments->filesFor($this->invitationAttachmentIds($id));
        $recipient = ['email' => (string) $invitation['recipient_email'], 'name' => (string) $invitation['recipient_name'], 'type' => $invitation['recipient_type']];

        $delivered = $this->deliver(
            $actor,
            $id,
            $certificate,
            $recipient,
            (string) $invitation['subject'],
            (string) $invitation['body_html'],
            TemplateRenderer::htmlToText((string) $invitation['body_html']),
            $files,
            ['retry' => true]
        );

        if ($delivered && $invitation['renewal_task_id'] !== null) {
            $this->tasks->markInProgress($actor, (int) $invitation['renewal_task_id']);
        }

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function markResponded(Actor $actor, int $id): array
    {
        return $this->changeStatus($actor, $id, 'responded', ['sent', 'failed'], 'invitation_responded');
    }

    /**
     * @return array<string, mixed>
     */
    public function close(Actor $actor, int $id): array
    {
        return $this->changeStatus($actor, $id, 'closed', ['queued', 'sent', 'failed', 'responded'], 'invitation_closed');
    }

    /**
     * Przypomnienia, których termin minął (cron). Pomija zaproszenia zarchiwizowanych certyfikatów
     * i zamkniętych zadań.
     *
     * @return array{due: int, sent: int, failed: int}
     */
    public function processDueReminders(): array
    {
        $actor = Actor::system();
        $stmt = $this->db->query(
            "SELECT i.id
             FROM invitations i
             INNER JOIN certificates c ON c.id = i.certificate_id
             LEFT JOIN renewal_tasks t ON t.id = i.renewal_task_id
             WHERE i.status = 'sent'
               AND i.next_reminder_at IS NOT NULL
               AND i.next_reminder_at <= NOW()
               AND c.archived_at IS NULL
               AND (t.id IS NULL OR t.status IN ('todo', 'in_progress'))
             ORDER BY i.next_reminder_at"
        );
        $ids = $stmt !== false ? array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) : [];

        $sent = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $invitation = $this->findVisible($actor, $id);
                $this->sendReminder($actor, $invitation) ? ++$sent : ++$failed;
            } catch (ServiceException) {
                ++$failed;
            }
        }

        return ['due' => count($ids), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * @param array<string, mixed> $invitation
     */
    private function sendReminder(Actor $actor, array $invitation): bool
    {
        $id = (int) $invitation['id'];
        $certificate = $this->certificates->findActive($actor, (int) $invitation['certificate_id']);
        $locale = (string) ($invitation['template_locale'] ?? 'pl');
        $template = $this->templates->findActiveByCode(TemplateService::CODE_REMINDER, $locale);
        if ($template === null) {
            throw ServiceException::conflict(\__('invitation.error.no_reminder_template'));
        }

        $recipient = $this->recipientOrNull($certificate, (string) $invitation['recipient_type'])
            ?? ['email' => (string) $invitation['recipient_email'], 'name' => (string) $invitation['recipient_name']]
                + TemplateRenderer::splitName((string) $invitation['recipient_name']);

        $rendered = TemplateRenderer::render(
            (string) $template['subject'],
            (string) $template['body_html'],
            $template['body_text'] !== null ? (string) $template['body_text'] : null,
            TemplateRenderer::values($certificate, $recipient, (string) $template['locale'])
        );
        $files = $this->attachments->filesFor($this->invitationAttachmentIds($id));
        $settings = Settings::read($this->db);
        $newCount = (int) $invitation['reminder_count'] + 1;
        $context = self::context($certificate);
        $userId = $actor->isSystem() ? null : $actor->id;

        try {
            $this->mailer->send((string) $recipient['email'], (string) $recipient['name'], $rendered['subject'], $rendered['body_html'], $rendered['body_text'], $files);
        } catch (Throwable $e) {
            $error = self::errorMessage($e);
            // Nieudane przypomnienie: kolejna próba następnego dnia, zaproszenie zostaje „wysłane”.
            $this->db->prepare(
                'UPDATE invitations SET last_error = :error, next_reminder_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = :id'
            )->execute(['error' => $error, 'id' => $id]);
            $this->events->log('invitation', $id, 'reminder_failed', $userId, $context, ['reminder' => $newCount, 'error' => $error]);

            return false;
        }

        $this->db->prepare(
            'UPDATE invitations
             SET reminder_count = :count, last_reminder_at = NOW(), last_error = NULL,
                 next_reminder_at = CASE WHEN :count_check < :max THEN DATE_ADD(NOW(), INTERVAL :interval DAY) ELSE NULL END
             WHERE id = :id'
        )->execute([
            'count'       => $newCount,
            'count_check' => $newCount,
            'max'         => $settings['reminders.max_count'],
            'interval'    => $settings['reminders.interval_days'],
            'id'          => $id,
        ]);
        $this->events->log('invitation', $id, 'reminder_sent', $userId, $context, [
            'reminder'        => $newCount,
            'recipient_email' => $recipient['email'],
            'template_id'     => $template['id'],
        ]);

        return true;
    }

    /**
     * @param array<string, mixed>                 $certificate
     * @param array<string, mixed>                 $recipient email, name
     * @param list<array{id: int, path: string, name: string}> $files
     * @param array<string, mixed>                 $payload
     */
    private function deliver(Actor $actor, int $invitationId, array $certificate, array $recipient, string $subject, string $html, string $text, array $files, array $payload): bool
    {
        $context = self::context($certificate);
        $userId = $actor->isSystem() ? null : $actor->id;

        try {
            $this->mailer->send((string) $recipient['email'], (string) $recipient['name'], $subject, $html, $text, $files);
        } catch (Throwable $e) {
            $error = self::errorMessage($e);
            $this->db->prepare(
                "UPDATE invitations SET status = 'failed', last_error = :error, next_reminder_at = NULL WHERE id = :id"
            )->execute(['error' => $error, 'id' => $invitationId]);
            $this->events->log('invitation', $invitationId, 'invitation_failed', $userId, $context, ['error' => $error] + $payload);

            return false;
        }

        $settings = Settings::read($this->db);
        $this->db->prepare(
            "UPDATE invitations
             SET status = 'sent', sent_at = NOW(), last_error = NULL,
                 next_reminder_at = CASE WHEN :max > 0 THEN DATE_ADD(NOW(), INTERVAL :interval DAY) ELSE NULL END
             WHERE id = :id"
        )->execute([
            'max'      => $settings['reminders.max_count'],
            'interval' => $settings['reminders.interval_days'],
            'id'       => $invitationId,
        ]);
        $this->events->log('invitation', $invitationId, 'invitation_sent', $userId, $context, [
            'recipient_type'  => $recipient['type'] ?? null,
            'recipient_email' => $recipient['email'],
        ] + $payload);

        return true;
    }

    /**
     * @param list<string> $allowedFrom
     * @return array<string, mixed>
     */
    private function changeStatus(Actor $actor, int $id, string $status, array $allowedFrom, string $eventType): array
    {
        $actor->authorize('invitations.send');
        $invitation = $this->findVisible($actor, $id);

        if ($invitation['status'] === $status) {
            return $this->get($actor, $id);
        }
        if (!in_array($invitation['status'], $allowedFrom, true)) {
            throw ServiceException::conflict(\__('invitation.error.status_change', [
                'from' => \__('invitation.status.' . $invitation['status']),
                'to'   => \__('invitation.status.' . $status),
            ]));
        }

        Transaction::run($this->db, function () use ($actor, $invitation, $id, $status, $eventType): void {
            $this->db->prepare('UPDATE invitations SET status = :status, next_reminder_at = NULL WHERE id = :id')
                ->execute(['status' => $status, 'id' => $id]);
            $this->events->log('invitation', $id, $eventType, $actor->id, self::context($invitation), ['from' => $invitation['status']]);
        });

        return $this->get($actor, $id);
    }

    /**
     * @param array<string, mixed> $certificate
     * @return array{email: string, name: string, first_name: string, last_name: string}|null
     */
    private function recipientOrNull(array $certificate, string $type): ?array
    {
        if ($type === 'beneficiary') {
            $email = (string) ($certificate['beneficiary_email'] ?? '');
            if ($certificate['beneficiary_id'] === null || $email === '') {
                return null;
            }
            $first = (string) $certificate['beneficiary_first_name'];
            $last = (string) $certificate['beneficiary_last_name'];

            return ['email' => $email, 'name' => trim($first . ' ' . $last), 'first_name' => $first, 'last_name' => $last];
        }

        $email = (string) ($certificate['payer_email'] ?? '');
        if ($email === '') {
            return null;
        }
        $contact = TemplateRenderer::splitName((string) $certificate['contact_person']);

        return ['email' => $email, 'name' => (string) $certificate['contact_person'], 'first_name' => $contact['first_name'], 'last_name' => $contact['last_name']];
    }

    /**
     * @return list<int>
     */
    private function invitationAttachmentIds(int $invitationId): array
    {
        $stmt = $this->db->prepare('SELECT attachment_id FROM invitation_attachments WHERE invitation_id = :id');
        $stmt->execute(['id' => $invitationId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, mixed>
     */
    private function findVisible(Actor $actor, int $id): array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(self::selectSql() . " WHERE i.id = :id AND {$visibility} LIMIT 1");
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ServiceException::notFound(\__('invitation.error.not_found'));
        }

        return self::present($row);
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $item) {
            if ((is_int($item) || (is_string($item) && ctype_digit($item))) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function errorMessage(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return mb_substr($message !== '' ? $message : $e::class, 0, 1000);
    }

    private static function selectSql(): string
    {
        return 'SELECT
                i.id, i.certificate_id, i.renewal_task_id, i.template_id, i.recipient_type, i.recipient_email, i.recipient_name,
                i.subject, i.body_html, i.status, i.sent_at, i.last_error, i.reminder_count, i.last_reminder_at,
                i.next_reminder_at, i.created_at, i.updated_at,
                c.name AS certificate_name, c.serial_number, c.expiry_date, c.beneficiary_id, c.payer_id,
                c.archived_at AS certificate_archived_at,
                et.name AS template_name, et.locale AS template_locale,
                su.first_name AS sent_by_first_name, su.last_name AS sent_by_last_name,
                t.status AS task_status,
                (SELECT COUNT(*) FROM invitation_attachments ia WHERE ia.invitation_id = i.id) AS attachment_count
            FROM invitations i
            INNER JOIN certificates c ON c.id = i.certificate_id
            LEFT JOIN email_templates et ON et.id = i.template_id
            LEFT JOIN users su ON su.id = i.sent_by_user_id
            LEFT JOIN renewal_tasks t ON t.id = i.renewal_task_id';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        foreach (['id', 'certificate_id', 'reminder_count', 'attachment_count', 'payer_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['renewal_task_id', 'template_id', 'beneficiary_id'] as $key) {
            $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
        }
        $row['sent_by_name'] = trim(($row['sent_by_first_name'] ?? '') . ' ' . ($row['sent_by_last_name'] ?? ''));
        $row['is_due'] = $row['status'] === 'sent' && $row['next_reminder_at'] !== null && strtotime((string) $row['next_reminder_at']) <= time();
        unset($row['sent_by_first_name'], $row['sent_by_last_name']);

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

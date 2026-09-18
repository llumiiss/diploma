<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use PDOException;

/**
 * Szablony wiadomości (F13): zaproszenia i przypomnienia w wersjach językowych, z załącznikami.
 * Zarządza nimi ADMIN; osoby wysyłające zaproszenia widzą aktywne szablony.
 */
final class TemplateService
{
    public const LOCALES = ['pl', 'en'];
    public const CODE_INVITATION = 'renewal_invitation';
    public const CODE_REMINDER = 'renewal_reminder';

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
        $actor->authorize('templates.manage');

        $stmt = $this->db->query(
            'SELECT t.id, t.code, t.locale, t.name, t.subject, t.is_active, t.created_at, t.updated_at,
                    (SELECT COUNT(*) FROM email_template_attachments eta WHERE eta.template_id = t.id) AS attachment_count,
                    (SELECT COUNT(*) FROM invitations i WHERE i.template_id = t.id) AS invitation_count
             FROM email_templates t
             ORDER BY t.code, t.locale, t.name'
        );

        return array_map([self::class, 'castRow'], $stmt !== false ? $stmt->fetchAll() : []);
    }

    /**
     * Aktywne szablony do wyboru przy wysyłce zaproszenia.
     *
     * @return list<array{id: int, code: string, locale: string, name: string, attachment_ids: list<int>}>
     */
    public function options(Actor $actor): array
    {
        $actor->authorize('invitations.send');

        $stmt = $this->db->query(
            'SELECT id, code, locale, name FROM email_templates WHERE is_active = 1 ORDER BY code, locale, name'
        );
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        return array_map(fn (array $row): array => [
            'id'             => (int) $row['id'],
            'code'           => (string) $row['code'],
            'locale'         => (string) $row['locale'],
            'name'           => (string) $row['name'],
            'attachment_ids' => $this->attachmentIds((int) $row['id']),
        ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $id): array
    {
        $actor->authorize('templates.manage');
        $template = $this->find($id);
        $template['attachment_ids'] = $this->attachmentIds($id);

        return $template;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $actor->authorize('templates.manage');
        [$values, $attachmentIds] = $this->validate($data, null);

        try {
            $id = Transaction::run($this->db, function () use ($actor, $values, $attachmentIds): int {
                $this->db->prepare(
                    'INSERT INTO email_templates (code, locale, name, subject, body_html, body_text, is_active)
                     VALUES (:code, :locale, :name, :subject, :body_html, :body_text, :is_active)'
                )->execute($values);
                $id = (int) $this->db->lastInsertId();
                $this->syncAttachments($id, $attachmentIds);
                $this->events->log('email_template', $id, 'template_created', $actor->id, [], [
                    'name' => $values['name'], 'code' => $values['code'], 'locale' => $values['locale'],
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            throw $this->translateDuplicate($e);
        }

        return $this->get($actor, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('templates.manage');
        $before = $this->find($id);
        $before['attachment_ids'] = $this->attachmentIds($id);
        [$values, $attachmentIds] = $this->validate($data, $id);

        $changes = EventLogger::diff($before, $values, ['code', 'locale', 'name', 'subject', 'body_html', 'body_text', 'is_active']);
        foreach (['body_html', 'body_text'] as $long) {
            // Pełnej treści nie powielamy w historii — wystarczy informacja, że się zmieniła.
            if (isset($changes[$long])) {
                $changes[$long] = ['from' => '…', 'to' => '…'];
            }
        }
        $sortedBefore = $before['attachment_ids'];
        $sortedAfter = $attachmentIds;
        sort($sortedBefore);
        sort($sortedAfter);
        if ($sortedBefore !== $sortedAfter) {
            $changes['attachments'] = ['from' => count($sortedBefore), 'to' => count($sortedAfter)];
        }

        if ($changes === []) {
            return $this->get($actor, $id);
        }

        try {
            Transaction::run($this->db, function () use ($actor, $id, $values, $attachmentIds, $changes): void {
                $this->db->prepare(
                    'UPDATE email_templates SET code = :code, locale = :locale, name = :name, subject = :subject,
                        body_html = :body_html, body_text = :body_text, is_active = :is_active
                     WHERE id = :id'
                )->execute($values + ['id' => $id]);
                $this->syncAttachments($id, $attachmentIds);
                $this->events->log('email_template', $id, 'template_updated', $actor->id, [], ['changes' => $changes]);
            });
        } catch (PDOException $e) {
            throw $this->translateDuplicate($e);
        }

        return $this->get($actor, $id);
    }

    /**
     * Podgląd szablonu (także niezapisanego) na danych wskazanego lub przykładowego certyfikatu.
     *
     * @param array<string, mixed> $data subject, body_html, body_text, locale, certificate_id?
     * @return array<string, mixed>
     */
    public function previewDraft(Actor $actor, array $data, CertificateService $certificates): array
    {
        $actor->authorize('templates.manage');

        $v = new Validator($data);
        $subject = (string) $v->string('subject', true, 255);
        $bodyHtml = (string) $v->string('body_html', true, 200000);
        $bodyText = $v->string('body_text', false, 200000);
        $locale = (string) $v->enum('locale', false, self::LOCALES, 'pl');
        $certificateId = $v->id('certificate_id', false);
        $v->throwIfFailed();

        if ($certificateId === null) {
            $sample = $this->db->query(
                'SELECT id FROM certificates WHERE archived_at IS NULL
                 ORDER BY beneficiary_id IS NULL, expiry_date LIMIT 1'
            );
            $certificateId = $sample !== false ? (int) $sample->fetchColumn() : 0;
        }

        if ($certificateId > 0) {
            $certificate = $certificates->findActive($actor, $certificateId);
            $recipient = $certificate['beneficiary_id'] !== null
                ? ['first_name' => (string) $certificate['beneficiary_first_name'], 'last_name' => (string) $certificate['beneficiary_last_name']]
                : TemplateRenderer::splitName((string) $certificate['contact_person']);
            $values = TemplateRenderer::values($certificate, $recipient, $locale);
            $sampleName = (string) $certificate['name'];
        } else {
            $values = array_combine(TemplateRenderer::PLACEHOLDERS, array_map(static fn (string $name): string => '[' . $name . ']', TemplateRenderer::PLACEHOLDERS));
            $sampleName = null;
        }

        return TemplateRenderer::render($subject, $bodyHtml, $bodyText, $values) + ['sample_certificate' => $sampleName];
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM email_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ServiceException::notFound(\__('template.error.not_found'));
        }

        return self::castRow($row);
    }

    /**
     * Aktywny szablon o danym kodzie w danym języku (z polskim jako zapasowym).
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByCode(string $code, string $locale): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM email_templates WHERE code = :code AND is_active = 1
             ORDER BY locale = :locale DESC, locale = 'pl' DESC, id LIMIT 1"
        );
        $stmt->execute(['code' => $code, 'locale' => $locale]);
        $row = $stmt->fetch();

        return $row === false ? null : self::castRow($row);
    }

    /**
     * @return list<int>
     */
    public function attachmentIds(int $templateId): array
    {
        $stmt = $this->db->prepare('SELECT attachment_id FROM email_template_attachments WHERE template_id = :id ORDER BY attachment_id');
        $stmt->execute(['id' => $templateId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    private function validate(array $data, ?int $exceptId): array
    {
        $v = new Validator($data);
        $values = [
            'code'      => (string) $v->string('code', true, 64),
            'locale'    => (string) $v->enum('locale', true, self::LOCALES),
            'name'      => (string) $v->string('name', true, 255),
            'subject'   => (string) $v->string('subject', true, 255),
            'body_html' => (string) $v->string('body_html', true, 200000),
            'body_text' => $v->string('body_text', false, 200000),
            'is_active' => $v->bool('is_active', true) ? 1 : 0,
        ];

        if ($values['code'] !== '' && preg_match('/^[a-z0-9_]{3,64}$/', $values['code']) !== 1) {
            $v->addError('code', 'template.error.code_format');
        }

        $attachmentIds = [];
        $raw = $data['attachment_ids'] ?? [];
        if (!is_array($raw)) {
            $v->addError('attachment_ids', 'validation.choice');
        } else {
            foreach ($raw as $item) {
                if ((is_int($item) || (is_string($item) && ctype_digit($item))) && (int) $item > 0) {
                    $attachmentIds[] = (int) $item;
                }
            }
            $attachmentIds = array_values(array_unique($attachmentIds));
            if ($attachmentIds !== []) {
                $placeholders = implode(',', array_fill(0, count($attachmentIds), '?'));
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM attachments WHERE id IN ({$placeholders})");
                $stmt->execute($attachmentIds);
                if ((int) $stmt->fetchColumn() !== count($attachmentIds)) {
                    $v->addError('attachment_ids', 'template.error.attachment_missing');
                }
            }
        }

        if (!$v->fails()) {
            $stmt = $this->db->prepare('SELECT id FROM email_templates WHERE code = :code AND locale = :locale AND id <> :id LIMIT 1');
            $stmt->execute(['code' => $values['code'], 'locale' => $values['locale'], 'id' => $exceptId ?? 0]);
            if ($stmt->fetchColumn() !== false) {
                $v->addError('code', 'template.error.duplicate');
            }
        }

        $v->throwIfFailed();

        return [$values, $attachmentIds];
    }

    /**
     * @param list<int> $attachmentIds
     */
    private function syncAttachments(int $templateId, array $attachmentIds): void
    {
        $this->db->prepare('DELETE FROM email_template_attachments WHERE template_id = :id')->execute(['id' => $templateId]);
        $insert = $this->db->prepare('INSERT INTO email_template_attachments (template_id, attachment_id) VALUES (:template_id, :attachment_id)');
        foreach ($attachmentIds as $attachmentId) {
            $insert->execute(['template_id' => $templateId, 'attachment_id' => $attachmentId]);
        }
    }

    private function translateDuplicate(PDOException $e): \Throwable
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return ServiceException::validation(['code' => \__('template.error.duplicate')]);
        }

        return $e;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];
        foreach (['attachment_count', 'invitation_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Exchange\EmlMessage;
use App\Exchange\Normalizer;
use App\Intake\RegistrationExtractor;
use PDO;
use PDOException;

/**
 * Szablony odczytu wniosków (Etap 11): własne etykiety, po których czytnik wiadomości rozpoznaje pola.
 *
 * Reguła mówi „w wiadomościach od nadawcy X (i o temacie Y) po etykiecie Z stoi pole P”. Warunki są
 * zwykłymi fragmentami tekstu — bez wyrażeń regularnych, więc plik z importu nie może spowolnić odbioru poczty.
 * Zarządza nimi administrator (to samo uprawnienie co szablony wiadomości), a stosuje je każda droga odbioru.
 */
final class ReadingRuleService
{
    public const FIELDS = ['name', 'field', 'label', 'sender_match', 'subject_match', 'is_active'];

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
        $stmt = $this->db->query('SELECT * FROM reading_rules ORDER BY name, field, label, id');

        return array_map([self::class, 'castRow'], $stmt !== false ? $stmt->fetchAll() : []);
    }

    /**
     * Pola, które reguła może wskazać, z nazwami w języku interfejsu.
     *
     * @return list<array{field: string, label: string}>
     */
    public static function fieldOptions(): array
    {
        return array_map(
            static fn (string $field): array => ['field' => $field, 'label' => \__(self::fieldLabelKey($field))],
            RegistrationExtractor::readableFields()
        );
    }

    public static function fieldLabelKey(string $field): string
    {
        return 'reading.field.' . str_replace('.', '_', $field);
    }

    /**
     * Etykiety obowiązujące dla wiadomości (aktywne reguły, których warunki pasują).
     *
     * @return list<array{field: string, label: string}>
     */
    public function matching(EmlMessage $message): array
    {
        $stmt = $this->db->query('SELECT field, label, sender_match, subject_match FROM reading_rules WHERE is_active = 1 ORDER BY id');
        $sender = mb_strtolower((string) ($message->from['email'] ?? ''));
        $subject = mb_strtolower((string) $message->subject);

        $rules = [];
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $row) {
            if ($row['sender_match'] !== null && !str_contains($sender, mb_strtolower((string) $row['sender_match']))) {
                continue;
            }
            if ($row['subject_match'] !== null && !str_contains($subject, mb_strtolower((string) $row['subject_match']))) {
                continue;
            }
            $rules[] = ['field' => (string) $row['field'], 'label' => (string) $row['label']];
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $actor->authorize('templates.manage');
        $values = $this->validate($data, null);

        try {
            $id = Transaction::run($this->db, function () use ($actor, $values): int {
                $this->db->prepare(
                    'INSERT INTO reading_rules (name, field, label, sender_match, subject_match, is_active, created_by_user_id)
                     VALUES (:name, :field, :label, :sender_match, :subject_match, :is_active, :created_by)'
                )->execute($values + ['created_by' => $actor->id > 0 ? $actor->id : null]);
                $id = (int) $this->db->lastInsertId();
                $this->events->log('system', null, 'reading_rule_created', $actor->id, [], [
                    'name' => $values['name'], 'field' => $values['field'], 'label' => $values['label'],
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            throw $this->translateDuplicate($e);
        }

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('templates.manage');
        $before = $this->find($id);
        $values = $this->validate($data + $before, $id);

        $changes = EventLogger::diff($before, $values, self::FIELDS);
        if ($changes === []) {
            return $before;
        }

        try {
            Transaction::run($this->db, function () use ($actor, $id, $values, $changes): void {
                $this->db->prepare(
                    'UPDATE reading_rules SET name = :name, field = :field, label = :label, sender_match = :sender_match,
                        subject_match = :subject_match, is_active = :is_active
                     WHERE id = :id'
                )->execute($values + ['id' => $id]);
                $this->events->log('system', null, 'reading_rule_updated', $actor->id, [], [
                    'name' => $values['name'], 'label' => $values['label'], 'changes' => $changes,
                ]);
            });
        } catch (PDOException $e) {
            throw $this->translateDuplicate($e);
        }

        return $this->find($id);
    }

    public function delete(Actor $actor, int $id): void
    {
        $actor->authorize('templates.manage');
        $rule = $this->find($id);

        Transaction::run($this->db, function () use ($actor, $id, $rule): void {
            $this->db->prepare('DELETE FROM reading_rules WHERE id = :id')->execute(['id' => $id]);
            $this->events->log('system', null, 'reading_rule_deleted', $actor->id, [], [
                'name' => $rule['name'], 'field' => $rule['field'], 'label' => $rule['label'],
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM reading_rules WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ServiceException::notFound(\__('reading.error.not_found'));
        }

        return self::castRow($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{name: string, field: string, label: string, sender_match: ?string, subject_match: ?string, is_active: int}
     */
    public function validate(array $data, ?int $exceptId): array
    {
        $v = new Validator($data);
        $values = [
            'name'          => (string) $v->string('name', true, 120),
            'field'         => (string) $v->enum('field', true, RegistrationExtractor::readableFields()),
            'label'         => (string) $v->string('label', true, 120),
            'sender_match'  => $v->string('sender_match', false, 255),
            'subject_match' => $v->string('subject_match', false, 255),
            'is_active'     => $v->bool('is_active', true) ? 1 : 0,
        ];

        // Dwukropek na końcu to część zapisu w wiadomości, nie nazwy etykiety.
        $values['label'] = rtrim($values['label'], ": \t");
        if ($values['label'] !== '' && Normalizer::key($values['label']) === '') {
            $v->addError('label', 'reading.error.label');
        }

        if (!$v->fails()) {
            $stmt = $this->db->prepare(
                'SELECT id FROM reading_rules WHERE name = :name AND field = :field AND label = :label AND id <> :id LIMIT 1'
            );
            $stmt->execute(['name' => $values['name'], 'field' => $values['field'], 'label' => $values['label'], 'id' => $exceptId ?? 0]);
            if ($stmt->fetchColumn() !== false) {
                $v->addError('label', 'reading.error.duplicate');
            }
        }

        $v->throwIfFailed();

        return $values;
    }

    private function translateDuplicate(PDOException $e): \Throwable
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return ServiceException::validation(['label' => \__('reading.error.duplicate')]);
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
        $row['created_by_user_id'] = $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null;

        return $row;
    }
}

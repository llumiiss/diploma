<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Załączniki wiadomości (F13) przechowywane w storage/attachments pod losową nazwą,
 * niedostępne bezpośrednio przez HTTP. Pobieranie przechodzi przez API z kontrolą uprawnień.
 */
final class AttachmentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Rozszerzenie → dopuszczalne typy MIME rozpoznane z zawartości pliku (finfo).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    private readonly string $storageDir;
    private readonly EventLogger $events;

    public function __construct(private readonly PDO $db, ?string $storageDir = null, ?EventLogger $events = null)
    {
        $this->storageDir = rtrim($storageDir ?? dirname(__DIR__, 2) . '/storage/attachments', '/\\');
        $this->events = $events ?? new EventLogger($db);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor): array
    {
        $actor->authorize('invitations.send');

        $stmt = $this->db->query(
            'SELECT a.id, a.original_name, a.mime_type, a.size_bytes, a.sha256, a.created_at,
                    u.first_name AS uploaded_by_first_name, u.last_name AS uploaded_by_last_name,
                    (SELECT COUNT(*) FROM email_template_attachments eta WHERE eta.attachment_id = a.id) AS template_count,
                    (SELECT COUNT(*) FROM invitation_attachments ia WHERE ia.attachment_id = a.id) AS invitation_count
             FROM attachments a
             LEFT JOIN users u ON u.id = a.uploaded_by_user_id
             ORDER BY a.original_name'
        );

        return array_map([self::class, 'present'], $stmt !== false ? $stmt->fetchAll() : []);
    }

    /**
     * Plik przesłany formularzem (wpis z $_FILES).
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function upload(Actor $actor, array $file): array
    {
        $actor->authorize('attachments.manage');

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw ServiceException::validation(['file' => \__(
                in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'attachment.error.too_large' : 'attachment.error.upload_failed',
                ['size' => self::formatMegabytes(self::MAX_BYTES)]
            )]);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw ServiceException::validation(['file' => \__('attachment.error.upload_failed')]);
        }

        return $this->store($actor, $tmp, (string) ($file['name'] ?? 'plik'), true);
    }

    /**
     * Zapis pliku z dysku (testy, import). $move = true przenosi plik tymczasowy przesłany przez PHP.
     *
     * @return array<string, mixed>
     */
    public function store(Actor $actor, string $sourcePath, string $originalName, bool $move = false): array
    {
        $actor->authorize('attachments.manage');

        $name = self::sanitizeName($originalName);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;

        if (!isset(self::ALLOWED[$extension])) {
            throw ServiceException::validation(['file' => \__('attachment.error.type', ['types' => implode(', ', array_keys(self::ALLOWED))])]);
        }
        if ($size <= 0) {
            throw ServiceException::validation(['file' => \__('attachment.error.empty')]);
        }
        if ($size > self::MAX_BYTES) {
            throw ServiceException::validation(['file' => \__('attachment.error.too_large', ['size' => self::formatMegabytes(self::MAX_BYTES)])]);
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        if (!in_array($mime, self::ALLOWED[$extension], true)) {
            throw ServiceException::validation(['file' => \__('attachment.error.content_mismatch', ['type' => $mime])]);
        }

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException('Cannot create attachment storage directory');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $this->storageDir . DIRECTORY_SEPARATOR . $storedName;
        $saved = $move ? move_uploaded_file($sourcePath, $target) : copy($sourcePath, $target);
        if (!$saved) {
            throw new \RuntimeException('Cannot store attachment file');
        }

        $hash = (string) hash_file('sha256', $target);

        try {
            $id = Transaction::run($this->db, function () use ($actor, $name, $storedName, $mime, $size, $hash): int {
                $this->db->prepare(
                    'INSERT INTO attachments (original_name, stored_name, mime_type, size_bytes, sha256, uploaded_by_user_id)
                     VALUES (:original_name, :stored_name, :mime_type, :size_bytes, :sha256, :user_id)'
                )->execute([
                    'original_name' => $name,
                    'stored_name'   => $storedName,
                    'mime_type'     => $mime,
                    'size_bytes'    => $size,
                    'sha256'        => $hash,
                    'user_id'       => $actor->isSystem() ? null : $actor->id,
                ]);
                $id = (int) $this->db->lastInsertId();
                $this->events->log('attachment', $id, 'attachment_uploaded', $actor->isSystem() ? null : $actor->id, [], [
                    'name' => $name, 'size' => $size, 'mime' => $mime,
                ]);

                return $id;
            });
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }

        return $this->get($id);
    }

    public function delete(Actor $actor, int $id): void
    {
        $actor->authorize('attachments.manage');
        $attachment = $this->findRow($id);

        if ($attachment['template_count'] > 0 || $attachment['invitation_count'] > 0) {
            throw ServiceException::conflict(\__('attachment.error.in_use', [
                'templates'   => (string) $attachment['template_count'],
                'invitations' => (string) $attachment['invitation_count'],
            ]));
        }

        Transaction::run($this->db, function () use ($actor, $id, $attachment): void {
            $this->db->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
            $this->events->log('attachment', $id, 'attachment_deleted', $actor->id, [], ['name' => $attachment['original_name']]);
        });

        $path = $this->path($attachment);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Dane do pobrania pliku: ścieżka, nazwa i typ.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function download(Actor $actor, int $id): array
    {
        $actor->authorize('invitations.send');
        $attachment = $this->findRow($id);
        $path = $this->path($attachment);

        if (!is_file($path)) {
            throw ServiceException::notFound(\__('attachment.error.file_missing'));
        }

        return ['path' => $path, 'name' => (string) $attachment['original_name'], 'mime' => (string) $attachment['mime_type']];
    }

    /**
     * Pliki do dołączenia do wiadomości.
     *
     * @param list<int> $ids
     * @return list<array{id: int, path: string, name: string}>
     */
    public function filesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, original_name, stored_name FROM attachments WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($ids));

        $files = [];
        foreach ($stmt->fetchAll() as $row) {
            $path = $this->path($row);
            if (!is_file($path)) {
                throw ServiceException::conflict(\__('attachment.error.file_missing_named', ['name' => (string) $row['original_name']]));
            }
            $files[] = ['id' => (int) $row['id'], 'path' => $path, 'name' => (string) $row['original_name']];
        }

        if (count($files) !== count($ids)) {
            throw ServiceException::validation(['attachment_ids' => \__('template.error.attachment_missing')]);
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        return self::present($this->findRow($id));
    }

    /**
     * Wiersz z nazwą pliku na dysku — tylko do użytku wewnętrznego (nie trafia do API).
     *
     * @return array<string, mixed>
     */
    private function findRow(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, u.first_name AS uploaded_by_first_name, u.last_name AS uploaded_by_last_name,
                    (SELECT COUNT(*) FROM email_template_attachments eta WHERE eta.attachment_id = a.id) AS template_count,
                    (SELECT COUNT(*) FROM invitation_attachments ia WHERE ia.attachment_id = a.id) AS invitation_count
             FROM attachments a
             LEFT JOIN users u ON u.id = a.uploaded_by_user_id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ServiceException::notFound(\__('attachment.error.not_found'));
        }

        $row['template_count'] = (int) $row['template_count'];
        $row['invitation_count'] = (int) $row['invitation_count'];

        return $row;
    }

    /**
     * @param array<string, mixed> $attachment
     */
    private function path(array $attachment): string
    {
        // stored_name powstaje w aplikacji (hex + rozszerzenie), basename chroni przed ścieżką z bazy.
        return $this->storageDir . DIRECTORY_SEPARATOR . basename((string) $attachment['stored_name']);
    }

    private static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>:|?*]/u', '', $name);
        $name = trim($name, ' .');

        if ($name === '') {
            $name = 'plik';
        }

        return mb_substr($name, -255);
    }

    private static function formatMegabytes(int $bytes): string
    {
        return (string) round($bytes / 1024 / 1024);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['size_bytes'] = (int) $row['size_bytes'];
        $row['template_count'] = (int) ($row['template_count'] ?? 0);
        $row['invitation_count'] = (int) ($row['invitation_count'] ?? 0);
        $row['uploaded_by'] = trim(($row['uploaded_by_first_name'] ?? '') . ' ' . ($row['uploaded_by_last_name'] ?? ''));
        unset($row['uploaded_by_first_name'], $row['uploaded_by_last_name'], $row['stored_name']);

        return $row;
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\ServiceException;

/**
 * Plik przesłany formularzem (multipart) — nazwa i treść po kontroli błędów i rozmiaru.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $content,
    ) {
    }

    /**
     * @param array<string, mixed> $files tablica $_FILES z żądania
     */
    public static function fromRequest(array $files, string $field, int $maxBytes): self
    {
        $file = $files[$field] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE || !is_array($file)) {
            throw ServiceException::validation([$field => \__('exchange.error.no_file')]);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || $size > $maxBytes) {
            throw ServiceException::validation([$field => \__('exchange.error.file_too_large', [
                'size' => (string) round($maxBytes / 1_000_000) . ' MB',
            ])]);
        }

        $path = (string) ($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($path)) {
            throw ServiceException::validation([$field => \__('exchange.error.upload_failed')]);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw ServiceException::validation([$field => \__('exchange.error.upload_failed')]);
        }

        return new self(basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file'))), $content);
    }
}

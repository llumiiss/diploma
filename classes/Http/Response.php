<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $payload,
        public readonly array $headers = [],
        public readonly ?string $rawBody = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(int $status, array $payload): self
    {
        return new self($status, $payload);
    }

    /**
     * Odpowiedź z plikiem do pobrania (eksport danych).
     */
    public static function download(string $body, string $contentType, string $filename): self
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'export';

        return new self(200, [], [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
        ], $body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        if ($this->rawBody !== null) {
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            echo $this->rawBody;

            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}

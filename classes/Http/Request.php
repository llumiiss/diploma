<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Żądanie do API w postaci niezależnej od zmiennych globalnych — da się je zbudować w teście.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly ?string $csrfToken = null,
        public readonly bool $invalidJson = false,
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = [];
        $invalidJson = false;

        if ($method !== 'GET') {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            if (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/x-www-form-urlencoded')) {
                $body = $_POST;
            } else {
                $raw = (string) file_get_contents('php://input');
                if (trim($raw) !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    } else {
                        $invalidJson = true;
                    }
                }
            }
        }

        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? null);

        return new self($method, $_GET, $body, is_string($csrf) ? $csrf : null, $invalidJson, $_FILES);
    }

    public function isRead(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Nazwa akcji z treści żądania (POST) albo z adresu (GET).
     */
    public function action(): string
    {
        $action = $this->body['action'] ?? $this->query['action'] ?? $this->query['view'] ?? '';

        return is_string($action) ? $action : '';
    }

    public function queryInt(string $key): ?int
    {
        return self::toInt($this->query[$key] ?? null);
    }

    public function bodyInt(string $key): ?int
    {
        return self::toInt($this->body[$key] ?? null);
    }

    /**
     * Dane formularza przesłane jako obiekt `data` (albo cała treść, gdy go brak).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->body['data'] ?? $this->body;

        return is_array($data) ? $data : [];
    }

    public function queryString(string $key): string
    {
        $value = $this->query[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function queryBool(string $key): bool
    {
        $value = $this->query[$key] ?? null;

        return $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $number = (int) trim($value);

            return $number > 0 ? $number : null;
        }

        return null;
    }
}

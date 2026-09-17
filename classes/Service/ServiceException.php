<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Błąd biznesowy zgłaszany przez usługi. Warstwa HTTP (App\Http\ApiKernel) zamienia
 * rodzaj błędu na kod odpowiedzi, a komunikat jest już przetłumaczony.
 */
final class ServiceException extends RuntimeException
{
    public const VALIDATION = 'validation';
    public const BAD_REQUEST = 'bad_request';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';

    /**
     * @param array<string, string> $errors  błędy pól formularza: pole => komunikat
     * @param array<string, mixed>  $details dodatkowe dane dla interfejsu (np. istniejący rekord)
     */
    private function __construct(
        public readonly string $kind,
        string $message,
        public readonly array $errors = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function validation(array $errors): self
    {
        return new self(self::VALIDATION, \__('api.error.validation'), $errors);
    }

    public static function badRequest(?string $message = null): self
    {
        return new self(self::BAD_REQUEST, $message ?? \__('api.error.bad_request'));
    }

    public static function forbidden(?string $message = null): self
    {
        return new self(self::FORBIDDEN, $message ?? \__('api.error.forbidden'));
    }

    public static function notFound(?string $message = null): self
    {
        return new self(self::NOT_FOUND, $message ?? \__('api.error.not_found'));
    }

    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $errors
     */
    public static function conflict(string $message, array $details = [], array $errors = []): self
    {
        return new self(self::CONFLICT, $message, $errors, $details);
    }

    public function httpStatus(): int
    {
        return match ($this->kind) {
            self::VALIDATION  => 422,
            self::BAD_REQUEST => 400,
            self::FORBIDDEN   => 403,
            self::NOT_FOUND  => 404,
            default          => 409,
        };
    }
}

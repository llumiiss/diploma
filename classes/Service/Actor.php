<?php

declare(strict_types=1);

namespace App\Service;

use App\Rbac;

/**
 * Zalogowane konto personelu wykonujące operację. Usługi dostają je jawnie,
 * zamiast czytać sesję — dzięki temu da się je testować i wywoływać z CLI.
 */
final class Actor
{
    public function __construct(
        public readonly int $id,
        public readonly string $role,
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        public readonly string $email = '',
    ) {
    }

    /**
     * @param array<string, mixed> $user wiersz z tabeli users
     */
    public static function fromUser(array $user): self
    {
        return new self(
            (int) ($user['id'] ?? 0),
            Rbac::normalize((string) ($user['role'] ?? '')),
            (string) ($user['first_name'] ?? ''),
            (string) ($user['last_name'] ?? ''),
            (string) ($user['email'] ?? ''),
        );
    }

    public function can(string $permission): bool
    {
        return Rbac::can($this->role, $permission);
    }

    public function seesAllRecords(): bool
    {
        return Rbac::seesAllRecords($this->role);
    }

    public function isAdmin(): bool
    {
        return Rbac::atLeast($this->role, Rbac::ADMIN);
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * @throws ServiceException gdy rola nie ma uprawnienia
     */
    public function authorize(string $permission): void
    {
        if (!$this->can($permission)) {
            throw ServiceException::forbidden();
        }
    }
}

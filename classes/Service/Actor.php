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
        /** Użytkownik certyfikatu powiązany z kontem (rola EMPLOYEE widzi jego certyfikaty jako własne). */
        public readonly ?int $beneficiaryId = null,
        /**
         * Filtr firm z ustawień konta (Etap 10): null = wszystkie firmy, lista = tylko wybrane.
         * Zawęża listy i wskaźniki (Visibility::companyFilter), nie zmienia uprawnień do rekordów.
         *
         * @var list<int>|null
         */
        public readonly ?array $companyIds = null,
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
            isset($user['beneficiary_id']) ? (int) $user['beneficiary_id'] : null,
        );
    }

    /**
     * To samo konto z innym filtrem firm.
     *
     * @param list<int>|null $companyIds
     */
    public function withCompanyFilter(?array $companyIds): self
    {
        return new self(
            $this->id,
            $this->role,
            $this->firstName,
            $this->lastName,
            $this->email,
            $this->beneficiaryId,
            $companyIds,
        );
    }

    /**
     * Zadania uruchamiane automatycznie (cron): pełny zakres danych, a w historii brak autora.
     */
    public static function system(): self
    {
        return new self(0, Rbac::ADMIN, 'System');
    }

    public function isSystem(): bool
    {
        return $this->id === 0;
    }

    public function can(string $permission): bool
    {
        return Rbac::can($this->role, $permission);
    }

    public function seesAllRecords(): bool
    {
        return Rbac::seesAllRecords($this->role);
    }

    /**
     * Zakres danych roli: all, own, technical albo personal (patrz App\Rbac).
     */
    public function scope(): string
    {
        return Rbac::scope($this->role);
    }

    public function isAdmin(): bool
    {
        return Rbac::isAdmin($this->role);
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

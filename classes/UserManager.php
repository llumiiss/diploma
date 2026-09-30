<?php

declare(strict_types=1);

namespace App;

use PDO;

final class UserManager
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::getInstance()->getConnection();
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getAdminUser(): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT first_name, last_name, role, email FROM users WHERE role = 'ADMIN' LIMIT 1"
        );
        $stmt->execute();

        return $stmt->fetch();
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findByEmail(string $email): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT id, first_name, last_name, role, email, password_hash, email_verified_at,
                    last_login_at, deactivated_at
             FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
        );
        $stmt->execute(['email' => trim($email)]);

        return $stmt->fetch();
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findById(int $id): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT id, first_name, last_name, role, email, password_hash, email_verified_at,
                    last_login_at, deactivated_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    /**
     * Konto wyłączone przez administratora nie może się logować (decyzja D3).
     *
     * @param array<string, mixed> $user
     */
    public static function isActive(array $user): bool
    {
        return empty($user['deactivated_at']);
    }

    /**
     * Adres potwierdzony linkiem z wiadomości — warunek zalogowania (Etap 9).
     *
     * @param array<string, mixed> $user
     */
    public static function isEmailVerified(array $user): bool
    {
        return !empty($user['email_verified_at']);
    }

    /**
     * Konto z ustawionym hasłem. Bez hasła są konta założone przez administratora
     * i konta sprzed Etapu 9 — logują się po ustawieniu hasła linkiem z wiadomości.
     *
     * @param array<string, mixed> $user
     */
    public static function hasPassword(array $user): bool
    {
        return is_string($user['password_hash'] ?? null) && $user['password_hash'] !== '';
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== false;
    }

    /**
     * @return int|false New user id
     */
    public function create(string $firstName, string $lastName, string $email, string $role = Rbac::OPERATOR): int|false
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (first_name, last_name, role, email)
             VALUES (:first_name, :last_name, :role, :email)'
        );
        $ok = $stmt->execute([
            'first_name' => trim($firstName),
            'last_name'  => trim($lastName),
            'role'       => Rbac::isRole($role) ? Rbac::normalize($role) : Rbac::OPERATOR,
            'email'      => trim(strtolower($email)),
        ]);

        if (!$ok) {
            return false;
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Konto z rejestracji publicznej: hasło od razu, adres jeszcze niepotwierdzony.
     *
     * @return int|false Identyfikator nowego konta.
     */
    public function createWithPassword(
        string $firstName,
        string $lastName,
        string $email,
        string $passwordHash,
        string $role = Rbac::OPERATOR
    ): int|false {
        $stmt = $this->db->prepare(
            'INSERT INTO users (first_name, last_name, role, email, password_hash)
             VALUES (:first_name, :last_name, :role, :email, :password_hash)'
        );
        $ok = $stmt->execute([
            'first_name'    => trim($firstName),
            'last_name'     => trim($lastName),
            'role'          => Rbac::isRole($role) ? Rbac::normalize($role) : Rbac::OPERATOR,
            'email'         => trim(strtolower($email)),
            'password_hash' => $passwordHash,
        ]);

        if (!$ok) {
            return false;
        }

        return (int) $this->db->lastInsertId();
    }

    public function markEmailVerified(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET email_verified_at = :now WHERE id = :id AND email_verified_at IS NULL'
        );

        return $stmt->execute(['now' => self::now(), 'id' => $id]);
    }

    /**
     * Zapisuje nowe hasło. Ustawienie hasła linkiem z wiadomości potwierdza zarazem adres,
     * bo dostęp do skrzynki jest właśnie dowodem posiadania adresu.
     */
    public function setPasswordHash(int $id, string $passwordHash): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users
                SET password_hash = :password_hash,
                    email_verified_at = COALESCE(email_verified_at, :now)
              WHERE id = :id'
        );

        return $stmt->execute(['password_hash' => $passwordHash, 'now' => self::now(), 'id' => $id]);
    }

    public function recordLogin(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
        $stmt->execute(['now' => self::now(), 'id' => $id]);
    }

    /**
     * Konto niepotwierdzone i bez hasła, starsze niż podana liczba dni — porzucona rejestracja.
     * Zwraca liczbę usuniętych kont (tokeny znikają razem z nimi, klucz obcy ON DELETE CASCADE).
     */
    public function deleteStaleUnverified(int $olderThanDays): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM users
              WHERE email_verified_at IS NULL
                AND last_login_at IS NULL
                AND created_at < :before'
        );
        $stmt->execute([
            'before' => (new \DateTimeImmutable('-' . max($olderThanDays, 1) . ' days'))->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount();
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Liczba certyfikatów, których ta osoba jest opiekunem — także zarchiwizowanych,
     * bo klucz obcy chroni wszystkie rekordy.
     */
    public function countOwnedCertificates(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM certificates WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function setRoleByEmail(string $email, string $role): bool
    {
        if (!Rbac::isRole($role)) {
            return false;
        }

        $user = $this->findByEmail($email);
        if ($user === false) {
            return false;
        }

        $stmt = $this->db->prepare('UPDATE users SET role = :role WHERE id = :id');

        return $stmt->execute([
            'role' => strtoupper(trim($role)),
            'id'   => (int) $user['id'],
        ]);
    }

    /**
     * Usuwa konto wraz z kodami logowania (FK ON DELETE CASCADE).
     * Certyfikatów NIE usuwa — jeśli osoba jest ich opiekunem, konto zostaje,
     * żeby nie stracić danych ani historii (docs/MAPA_PROJEKTU.md §5).
     */
    public function deleteAccountCompletely(int $userId): bool
    {
        if ($this->countOwnedCertificates($userId) > 0) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');

        return $stmt->execute(['id' => $userId]) && $stmt->rowCount() > 0;
    }
}

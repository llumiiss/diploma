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
     * @return array<int, array<string, mixed>>
     */
    public function getAllUsers(string $scope = 'corporate'): array
    {
        $sql = <<<'SQL'
            SELECT
                u.id,
                u.first_name,
                u.last_name,
                u.role,
                u.email,
                COUNT(s.id) AS subscription_count
            FROM users u
            LEFT JOIN subscriptions s ON s.user_id = u.id AND s.scope = :scope
            GROUP BY u.id
            HAVING subscription_count > 0
            ORDER BY u.last_name, u.first_name
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['scope' => $scope]);

        return $stmt->fetchAll();
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
            'SELECT id, first_name, last_name, role, email FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
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
            'SELECT id, first_name, last_name, role, email FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== false;
    }

    /**
     * @return int|false New user id
     */
    public function create(string $firstName, string $lastName, string $email): int|false
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (first_name, last_name, role, email)
             VALUES (:first_name, :last_name, :role, :email)'
        );
        $ok = $stmt->execute([
            'first_name' => trim($firstName),
            'last_name'  => trim($lastName),
            'role'       => 'OPERATOR',
            'email'      => trim(strtolower($email)),
        ]);

        if (!$ok) {
            return false;
        }

        return (int) $this->db->lastInsertId();
    }

    public function deleteById(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    public function deleteAccountCompletely(int $userId): bool
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('DELETE FROM subscriptions WHERE user_id = :id');
            $stmt->execute(['id' => $userId]);

            $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
            $ok = $stmt->execute(['id' => $userId]);

            if (!$ok || $stmt->rowCount() === 0) {
                $this->db->rollBack();

                return false;
            }

            $this->db->commit();

            return true;
        } catch (\Throwable) {
            $this->db->rollBack();

            return false;
        }
    }
}

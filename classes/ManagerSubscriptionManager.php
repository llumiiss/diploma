<?php

declare(strict_types=1);

namespace App;

use PDO;

final class ManagerSubscriptionManager
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::getInstance()->getConnection();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, nazwa_uslugi, mail_subskrypcji, username_konta, koszt_pln, data_nastepnej_platnosci, created_at
             FROM manager_subskrypcji
             WHERE user_id = :user_id
             ORDER BY data_nastepnej_platnosci ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array{nazwa_uslugi: string, mail_subskrypcji?: string, username_konta?: string, koszt_pln: float|string, data_nastepnej_platnosci: string} $data
     * @return array<string, mixed>
     */
    public function create(int $userId, array $data): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO manager_subskrypcji (user_id, nazwa_uslugi, mail_subskrypcji, username_konta, koszt_pln, data_nastepnej_platnosci)
             VALUES (:user_id, :nazwa_uslugi, :mail_subskrypcji, :username_konta, :koszt_pln, :data_nastepnej_platnosci)'
        );
        $stmt->execute([
            'user_id'                  => $userId,
            'nazwa_uslugi'             => $data['nazwa_uslugi'],
            'mail_subskrypcji'         => $data['mail_subskrypcji'] ?: null,
            'username_konta'           => $data['username_konta'] ?: null,
            'koszt_pln'                => $data['koszt_pln'],
            'data_nastepnej_platnosci' => $data['data_nastepnej_platnosci'],
        ]);

        $id = (int) $this->db->lastInsertId();
        $row = $this->findById($id, $userId);

        return $row ?: [
            'id'                       => $id,
            'user_id'                  => $userId,
            'nazwa_uslugi'             => $data['nazwa_uslugi'],
            'mail_subskrypcji'         => $data['mail_subskrypcji'] ?: null,
            'username_konta'           => $data['username_konta'] ?: null,
            'koszt_pln'                => $data['koszt_pln'],
            'data_nastepnej_platnosci' => $data['data_nastepnej_platnosci'],
        ];
    }

    /**
     * @return array<string, mixed>|false
     */
    public function findById(int $id, int $userId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, nazwa_uslugi, mail_subskrypcji, username_konta, koszt_pln, data_nastepnej_platnosci, created_at
             FROM manager_subskrypcji
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->fetch();
    }
}

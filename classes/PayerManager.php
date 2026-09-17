<?php

declare(strict_types=1);

namespace App;

use PDO;

final class PayerManager
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::getInstance()->getConnection();
    }

    /**
     * Płatnicy z bieżącymi (niezarchiwizowanymi) certyfikatami w danym zakresie.
     * Alias subscription_count zostaje, bo czyta go widok panelu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllPayers(string $scope = 'corporate'): array
    {
        $sql = <<<'SQL'
            SELECT
                p.id,
                p.company_name,
                p.contact_person,
                p.tax_id,
                p.email,
                p.phone,
                p.city,
                COUNT(c.id) AS subscription_count,
                COALESCE(SUM(c.annual_cost), 0) AS total_annual_cost
            FROM payers p
            LEFT JOIN certificates c
                ON c.payer_id = p.id AND c.scope = :scope AND c.archived_at IS NULL
            WHERE p.archived_at IS NULL
            GROUP BY p.id
            HAVING subscription_count > 0
            ORDER BY p.company_name
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['scope' => $scope]);

        return $stmt->fetchAll();
    }
}

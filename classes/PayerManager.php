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
                COUNT(s.id) AS subscription_count,
                COALESCE(SUM(s.annual_cost), 0) AS total_annual_cost
            FROM payers p
            LEFT JOIN subscriptions s ON s.payer_id = p.id AND s.scope = :scope
            GROUP BY p.id
            HAVING subscription_count > 0
            ORDER BY p.company_name
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['scope' => $scope]);

        return $stmt->fetchAll();
    }
}

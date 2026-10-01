<?php

declare(strict_types=1);

namespace App;

use App\Service\Visibility;
use PDO;

/**
 * Wskaźniki pulpitu liczone na tabeli certyfikatów.
 * Rekordy zarchiwizowane (archived_at) są pomijane we wszystkich widokach bieżących.
 */
final class CertificateManager
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::getInstance()->getConnection();
    }

    /**
     * @return array{pending: int, active: int, renewal_in_progress: int, expired: int}
     */
    public function getStatusStats(?int $ownerId = null): array
    {
        [$ownerFilter, $params] = $this->ownerFilter($ownerId, []);

        $sql = <<<SQL
            SELECT status, COUNT(*) AS total
            FROM certificates
            WHERE archived_at IS NULL{$ownerFilter}
            GROUP BY status
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $stats = [
            'pending'              => 0,
            'active'               => 0,
            'renewal_in_progress'  => 0,
            'expired'              => 0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['status']] = (int) $row['total'];
        }

        return $stats;
    }

    /**
     * @return array{due_soon: int, overdue: int, paid: int}
     */
    public function getPaymentSummary(?int $ownerId = null): array
    {
        [$ownerFilter, $params] = $this->ownerFilter($ownerId, []);

        $sql = <<<SQL
            SELECT
                payment_status,
                COUNT(*) AS total
            FROM certificates
            WHERE archived_at IS NULL{$ownerFilter}
              AND payment_status IN ('due_soon', 'overdue')
            GROUP BY payment_status
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $summary = [
            'due_soon' => 0,
            'overdue'  => 0,
            'paid'     => 0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $summary[$row['payment_status']] = (int) $row['total'];
        }

        $paidStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM certificates
             WHERE archived_at IS NULL{$ownerFilter} AND payment_status = 'paid'"
        );
        $paidStmt->execute($params);
        $summary['paid'] = (int) $paidStmt->fetchColumn();

        return $summary;
    }

    /**
     * @return array{expired: int, expiring_critical: int, expiring_warning: int, critical_days: int, warning_days: int, average_discount: float, discounted_count: int}
     */
    public function getRenewalSummary(?int $ownerId = null): array
    {
        $thresholds = CertificateHelper::getThresholds();
        $criticalDays = (int) $thresholds['critical'];
        $warningDays = (int) $thresholds['warning'];

        [$ownerFilter, $params] = $this->ownerFilter($ownerId, [], 'c');

        $sql = "
            SELECT
                SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$criticalDays} DAY) THEN 1 ELSE 0 END) AS expiring_critical,
                SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$warningDays} DAY) THEN 1 ELSE 0 END) AS expiring_warning,
                COALESCE(AVG(discount_percent), 0) AS average_discount,
                COALESCE(SUM(CASE WHEN discount_percent > 0 THEN 1 ELSE 0 END), 0) AS discounted_count
            FROM certificates c
            WHERE archived_at IS NULL{$ownerFilter}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'expired'            => (int) ($row['expired'] ?? 0),
            'expiring_critical'  => (int) ($row['expiring_critical'] ?? 0),
            'expiring_warning'   => (int) ($row['expiring_warning'] ?? 0),
            'critical_days'      => $criticalDays,
            'warning_days'       => $warningDays,
            'average_discount'   => round((float) ($row['average_discount'] ?? 0), 2),
            'discounted_count'   => (int) ($row['discounted_count'] ?? 0),
        ];
    }

    /**
     * Zwraca warunek SQL i parametry ograniczające wynik do rekordów widocznych dla konta:
     * certyfikatów, których jest opiekunem, i tych z przydzielonym mu zadaniem odnowienia (D8).
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function ownerFilter(?int $ownerId, array $params, string $alias = 'certificates'): array
    {
        if ($ownerId === null) {
            return ['', $params];
        }

        [$condition, $visibilityParams] = Visibility::certificatesForUserId($ownerId, $alias);

        return [' AND ' . $condition, $params + $visibilityParams];
    }
}

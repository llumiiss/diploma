<?php

declare(strict_types=1);

namespace App;

use App\Service\Actor;
use App\Service\Visibility;
use PDO;

/**
 * Wskaźniki pulpitu liczone na tabeli certyfikatów.
 * Rekordy zarchiwizowane (archived_at) są pomijane we wszystkich widokach bieżących.
 *
 * Wskaźniki liczone są w zakresie danych konta (Visibility) i — jeśli konto ustawiło filtr firm —
 * tylko dla wybranych firm. Bez konta (null) liczona jest cała ewidencja.
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
    public function getStatusStats(?Actor $actor = null): array
    {
        [$scope, $params] = $this->scope($actor);

        $sql = <<<SQL
            SELECT c.status, COUNT(*) AS total
            FROM certificates c
            WHERE c.archived_at IS NULL{$scope}
            GROUP BY c.status
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
    public function getPaymentSummary(?Actor $actor = null): array
    {
        [$scope, $params] = $this->scope($actor);

        $sql = <<<SQL
            SELECT c.payment_status, COUNT(*) AS total
            FROM certificates c
            WHERE c.archived_at IS NULL{$scope}
              AND c.payment_status IN ('due_soon', 'overdue')
            GROUP BY c.payment_status
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
            "SELECT COUNT(*) FROM certificates c
             WHERE c.archived_at IS NULL{$scope} AND c.payment_status = 'paid'"
        );
        $paidStmt->execute($params);
        $summary['paid'] = (int) $paidStmt->fetchColumn();

        return $summary;
    }

    /**
     * @return array{expired: int, expiring_critical: int, expiring_warning: int, critical_days: int, warning_days: int, average_discount: float, discounted_count: int}
     */
    public function getRenewalSummary(?Actor $actor = null): array
    {
        $thresholds = CertificateHelper::getThresholds();
        $criticalDays = (int) $thresholds['critical'];
        $warningDays = (int) $thresholds['warning'];

        [$scope, $params] = $this->scope($actor);

        $sql = "
            SELECT
                SUM(CASE WHEN c.expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN c.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$criticalDays} DAY) THEN 1 ELSE 0 END) AS expiring_critical,
                SUM(CASE WHEN c.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$warningDays} DAY) THEN 1 ELSE 0 END) AS expiring_warning,
                COALESCE(AVG(c.discount_percent), 0) AS average_discount,
                COALESCE(SUM(CASE WHEN c.discount_percent > 0 THEN 1 ELSE 0 END), 0) AS discounted_count
            FROM certificates c
            WHERE c.archived_at IS NULL{$scope}
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
     * Warunek SQL (z początkowym „ AND ”) i parametry ograniczające wynik do rekordów widocznych dla konta
     * (D8, role stanowisk) i do firm wybranych w filtrze konta. Alias tabeli certyfikatów to „c”.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function scope(?Actor $actor): array
    {
        if ($actor === null) {
            return ['', []];
        }

        [$visibility, $visibilityParams] = Visibility::certificates($actor, 'c');
        [$company, $companyParams] = Visibility::companyFilter($actor, 'c.payer_id');

        return [" AND {$visibility} AND {$company}", $visibilityParams + $companyParams];
    }
}

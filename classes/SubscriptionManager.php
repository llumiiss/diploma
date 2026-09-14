<?php

declare(strict_types=1);

namespace App;

use PDO;

final class SubscriptionManager
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? Database::getInstance()->getConnection();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllSubscriptions(string $scope = 'corporate'): array
    {
        $sql = <<<'SQL'
            SELECT
                s.id,
                s.name,
                s.scope,
                s.subscription_type,
                s.expiry_date,
                s.status,
                s.annual_cost,
                s.billing_cycle,
                s.currency,
                s.payment_status,
                s.last_payment_date,
                s.auto_renew,
                s.notes,
                s.user_id,
                s.payer_id,
                u.first_name   AS user_first_name,
                u.last_name    AS user_last_name,
                u.role         AS user_role,
                u.email        AS user_email,
                p.company_name,
                p.contact_person
            FROM subscriptions s
            INNER JOIN users u ON s.user_id = u.id
            INNER JOIN payers p ON s.payer_id = p.id
            WHERE s.scope = :scope
            ORDER BY s.expiry_date ASC
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['scope' => $scope]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{pending: int, active: int, renewal_in_progress: int, expired: int}
     */
    public function getStatusStats(string $scope = 'corporate'): array
    {
        $sql = <<<'SQL'
            SELECT status, COUNT(*) AS total
            FROM subscriptions
            WHERE scope = :scope
            GROUP BY status
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['scope' => $scope]);

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
     * @return array{due_soon: int, overdue: int, paid: int, total_due_amount: float}
     */
    public function getPaymentSummary(string $scope = 'corporate'): array
    {
        $sql = <<<'SQL'
            SELECT
                payment_status,
                COUNT(*) AS total,
                COALESCE(SUM(annual_cost), 0) AS amount
            FROM subscriptions
            WHERE scope = :scope
              AND payment_status IN ('due_soon', 'overdue')
            GROUP BY payment_status
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['scope' => $scope]);

        $summary = [
            'due_soon'         => 0,
            'overdue'          => 0,
            'paid'             => 0,
            'total_due_amount' => 0.0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $summary[$row['payment_status']] = (int) $row['total'];
            $summary['total_due_amount'] += (float) $row['amount'];
        }

        $paidStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM subscriptions WHERE scope = :scope AND payment_status = 'paid'"
        );
        $paidStmt->execute(['scope' => $scope]);
        $summary['paid'] = (int) $paidStmt->fetchColumn();

        return $summary;
    }

    /**
     * @return array{expired: int, expiring_critical: int, expiring_warning: int, critical_days: int, warning_days: int, annual_commitment: float, monthly_spend: float}
     */
    public function getRenewalSummary(string $scope = 'corporate'): array
    {
        $thresholds = SubscriptionHelper::getThresholds($scope);
        $criticalDays = (int) $thresholds['critical'];
        $warningDays = (int) $thresholds['warning'];

        $sql = "
            SELECT
                SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$criticalDays} DAY) THEN 1 ELSE 0 END) AS expiring_critical,
                SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$warningDays} DAY) THEN 1 ELSE 0 END) AS expiring_warning,
                COALESCE(SUM(annual_cost), 0) AS annual_commitment,
                COALESCE(SUM(
                    CASE
                        WHEN billing_cycle = 'monthly' THEN annual_cost
                        WHEN billing_cycle = 'annual' THEN annual_cost / 12
                        ELSE annual_cost / 12
                    END
                ), 0) AS monthly_spend
            FROM subscriptions
            WHERE scope = :scope
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['scope' => $scope]);
        $row = $stmt->fetch();

        return [
            'expired'            => (int) ($row['expired'] ?? 0),
            'expiring_critical'  => (int) ($row['expiring_critical'] ?? 0),
            'expiring_warning'   => (int) ($row['expiring_warning'] ?? 0),
            'critical_days'      => $criticalDays,
            'warning_days'       => $warningDays,
            'annual_commitment'  => (float) ($row['annual_commitment'] ?? 0),
            'monthly_spend'      => (float) ($row['monthly_spend'] ?? 0),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Logika prezentacji certyfikatów i usług: progi odnowień, priorytety, etykiety.
 */
final class CertificateHelper
{
    /**
     * Reminder thresholds per scope (days until renewal/payment).
     *
     * @return array{critical: int, warning: int, billing: string}
     */
    public static function getThresholds(string $scope): array
    {
        return match ($scope) {
            'personal' => [
                'critical' => 3,   // renewal very soon
                'warning'  => 15,  // monthly payment reminder
                'billing'  => 'monthly',
            ],
            default => [
                'critical' => 7,   // corporate annual — urgent renewal
                'warning'  => 30,  // annual payment / renewal reminder
                'billing'  => 'annual',
            ],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $certificates
     * @return array<int, array<string, mixed>>
     */
    public static function enrich(array $certificates, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');

        foreach ($certificates as &$item) {
            $scope = (string) ($item['scope'] ?? 'corporate');
            $thresholds = self::getThresholds($scope);

            $expiry = new DateTimeImmutable((string) $item['expiry_date']);
            $daysLeft = (int) $today->diff($expiry)->format('%r%a');

            $item['days_left'] = $daysLeft;
            $item['owner_name'] = self::fullName($item['user_first_name'] ?? '', $item['user_last_name'] ?? '');
            $item['beneficiary_name'] = self::fullName($item['beneficiary_first_name'] ?? '', $item['beneficiary_last_name'] ?? '');
            $item['annual_cost'] = (float) $item['annual_cost'];
            $item['thresholds'] = $thresholds;
            $item['priority'] = self::resolvePriority($daysLeft, $scope);
            $item['priority_label'] = self::priorityLabel($item['priority'], $scope);
            $item['reminder_message'] = self::reminderMessage($daysLeft, $scope);
            $item['type_label'] = self::typeLabel((string) ($item['certificate_type'] ?? 'OTHER'));
            $item['status_label'] = self::statusLabel((string) $item['status']);
            $item['display_payment_status'] = self::resolveDisplayPaymentStatus($item, $daysLeft, $scope);
            $item['payment_label'] = self::paymentLabel($item['display_payment_status']);
        }
        unset($item);

        return $certificates;
    }

    public static function resolvePriority(int $daysLeft, string $scope = 'corporate'): string
    {
        $t = self::getThresholds($scope);

        if ($daysLeft < 0) {
            return 'expired';
        }
        if ($daysLeft <= $t['critical']) {
            return 'critical';
        }
        if ($daysLeft <= $t['warning']) {
            return 'warning';
        }

        return 'ok';
    }

    public static function priorityLabel(string $priority, string $scope = 'corporate'): string
    {
        return match ($priority) {
            'expired'  => \__('priority.expired'),
            'critical' => $scope === 'personal' ? \__('priority.renewal_soon') : \__('priority.critical'),
            'warning'  => $scope === 'personal' ? \__('priority.payment_due') : \__('priority.renewal_due'),
            default    => \__('priority.normal'),
        };
    }

    public static function reminderMessage(int $daysLeft, string $scope = 'corporate'): string
    {
        if ($daysLeft < 0) {
            return \__('reminder.expired', ['days' => (string) abs($daysLeft)]);
        }

        $t = self::getThresholds($scope);

        if ($daysLeft <= $t['critical']) {
            return $scope === 'personal'
                ? \__('reminder.renewal_soon', ['days' => (string) $daysLeft])
                : \__('reminder.critical', ['days' => (string) $daysLeft]);
        }

        if ($daysLeft <= $t['warning']) {
            return $scope === 'personal'
                ? \__('reminder.payment_due', ['days' => (string) $daysLeft])
                : \__('reminder.renewal_due', ['days' => (string) $daysLeft]);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function resolveDisplayPaymentStatus(array $item, int $daysLeft, string $scope): string
    {
        $stored = (string) ($item['payment_status'] ?? 'due_soon');

        if ($stored === 'overdue' || $stored === 'not_applicable') {
            return $stored;
        }

        $t = self::getThresholds($scope);

        if ($daysLeft < 0) {
            return 'overdue';
        }

        if ($daysLeft <= $t['warning']) {
            return 'due_soon';
        }

        return $stored;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'QUALIFIED_SIGNATURE' => \__('type.qualified_signature'),
            'QUALIFIED_SEAL'      => \__('type.qualified_seal'),
            'SSL_CERTIFICATE'     => \__('type.ssl'),
            'SAAS'                => \__('type.saas'),
            'DOMAIN'              => \__('type.domain'),
            'CLOUD_SUPPORT'       => \__('type.cloud_support'),
            'CODE_SIGNING'        => \__('type.code_signing'),
            'STREAMING'           => \__('type.streaming'),
            'MUSIC'               => \__('type.music'),
            'GAMING'              => \__('type.gaming'),
            'FITNESS'             => \__('type.fitness'),
            'CLOUD_STORAGE'       => \__('type.cloud_storage'),
            default               => \__('type.other'),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending'             => \__('status.pending'),
            'active'              => \__('status.active'),
            'renewal_in_progress' => \__('status.renewal_in_progress'),
            'expired'             => \__('status.expired'),
            default               => ucfirst($status),
        };
    }

    public static function paymentLabel(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'paid'             => \__('payment.paid'),
            'due_soon'         => \__('payment.due_soon'),
            'overdue'          => \__('payment.overdue'),
            'not_applicable'   => \__('payment.na'),
            default            => ucfirst($paymentStatus),
        };
    }

    public static function formatCurrency(float $amount, string $currency = 'PLN'): string
    {
        return number_format($amount, 2, '.', ' ') . ' ' . $currency;
    }

    private static function fullName(mixed $firstName, mixed $lastName): string
    {
        return trim((string) $firstName . ' ' . (string) $lastName);
    }
}

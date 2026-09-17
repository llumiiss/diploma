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
            // Progi ewidencji firmowej ustawia administrator (App\Settings, domyślnie 7 i 30 dni).
            default => [
                'critical' => Settings::int('renewal.critical_days'),
                'warning'  => Settings::int('renewal.warning_days'),
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

        return self::priorityFor($daysLeft, $t['critical'], $t['warning']);
    }

    /**
     * Priorytet wg progów podanych wprost (raporty czytają progi z bazy w ramach jednego żądania).
     */
    public static function priorityFor(int $daysLeft, int $criticalDays, int $warningDays): string
    {
        if ($daysLeft < 0) {
            return 'expired';
        }
        if ($daysLeft <= $criticalDays) {
            return 'critical';
        }
        if ($daysLeft <= $warningDays) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Koszt w przeliczeniu na rok (§5 pkt 12). Kolumna annual_cost przechowuje kwotę za okres
     * rozliczeniowy: przy cyklu miesięcznym to kwota miesięczna, przy wieloletnim — za cały okres
     * ważności (dzielona przez liczbę lat między datą „ważny od” a wygaśnięciem, co najmniej 1).
     */
    public static function annualizedCost(float $amount, string $billingCycle, ?string $validFrom, string $expiryDate): float
    {
        if ($billingCycle === 'monthly') {
            return round($amount * 12, 2);
        }

        if ($billingCycle === 'multi_year' && $validFrom !== null && $validFrom !== '') {
            $span = (new DateTimeImmutable($validFrom))->diff(new DateTimeImmutable($expiryDate));
            $months = $span->invert === 1 ? 0 : $span->y * 12 + $span->m + ($span->d >= 15 ? 1 : 0);
            $years = max(1, (int) round($months / 12));

            return round($amount / $years, 2);
        }

        return round($amount, 2);
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
        return \__(self::typeKey($type));
    }

    /**
     * Klucz tłumaczenia typu — pozwala podać etykietę w innym języku niż interfejs (szablony wiadomości).
     */
    public static function typeKey(string $type): string
    {
        return match ($type) {
            'QUALIFIED_SIGNATURE' => 'type.qualified_signature',
            'QUALIFIED_SEAL'      => 'type.qualified_seal',
            'SSL_CERTIFICATE'     => 'type.ssl',
            'SAAS'                => 'type.saas',
            'DOMAIN'              => 'type.domain',
            'CLOUD_SUPPORT'       => 'type.cloud_support',
            'CODE_SIGNING'        => 'type.code_signing',
            'STREAMING'           => 'type.streaming',
            'MUSIC'               => 'type.music',
            'GAMING'              => 'type.gaming',
            'FITNESS'             => 'type.fitness',
            'CLOUD_STORAGE'       => 'type.cloud_storage',
            default               => 'type.other',
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

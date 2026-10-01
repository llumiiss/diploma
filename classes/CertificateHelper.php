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
     * Progi przypomnień w dniach — ustawia je administrator (App\Settings, domyślnie 7 i 30 dni).
     *
     * @return array{critical: int, warning: int, billing: string}
     */
    public static function getThresholds(): array
    {
        return [
            'critical' => Settings::int('renewal.critical_days'),
            'warning'  => Settings::int('renewal.warning_days'),
            'billing'  => 'annual',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $certificates
     * @return array<int, array<string, mixed>>
     */
    public static function enrich(array $certificates, ?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');

        $thresholds = self::getThresholds();

        foreach ($certificates as &$item) {
            $expiry = new DateTimeImmutable((string) $item['expiry_date']);
            $daysLeft = (int) $today->diff($expiry)->format('%r%a');

            $item['days_left'] = $daysLeft;
            $item['owner_name'] = self::fullName($item['user_first_name'] ?? '', $item['user_last_name'] ?? '');
            $item['beneficiary_name'] = self::fullName($item['beneficiary_first_name'] ?? '', $item['beneficiary_last_name'] ?? '');
            $item['discount_percent'] = (float) ($item['discount_percent'] ?? 0);
            $item['discount_label'] = self::formatDiscount($item['discount_percent']);
            $item['thresholds'] = $thresholds;
            $item['priority'] = self::priorityFor($daysLeft, $thresholds['critical'], $thresholds['warning']);
            $item['priority_label'] = self::priorityLabel($item['priority']);
            $item['reminder_message'] = self::reminderMessage($daysLeft);
            $item['type_label'] = self::typeLabel((string) ($item['certificate_type'] ?? 'OTHER'));
            $item['status_label'] = self::statusLabel((string) $item['status']);
            $item['display_payment_status'] = self::resolveDisplayPaymentStatus($item, $daysLeft);
            $item['payment_label'] = self::paymentLabel($item['display_payment_status']);
        }
        unset($item);

        return $certificates;
    }

    public static function resolvePriority(int $daysLeft): string
    {
        $t = self::getThresholds();

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
     * Rabat jako tekst z minusem: 5.0 → „-5%”, 2.5 → „-2,5%”, 0 → „0%”.
     * Kolumna certificates.discount_percent przechowuje wielkość rabatu (0–100), nie liczbę ujemną.
     */
    public static function formatDiscount(float $percent): string
    {
        if ($percent <= 0.0) {
            return '0%';
        }

        $text = rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');

        return '-' . $text . '%';
    }

    public static function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'expired'  => \__('priority.expired'),
            'critical' => \__('priority.critical'),
            'warning'  => \__('priority.renewal_due'),
            default    => \__('priority.normal'),
        };
    }

    public static function reminderMessage(int $daysLeft): string
    {
        if ($daysLeft < 0) {
            return \__('reminder.expired', ['days' => (string) abs($daysLeft)]);
        }

        $t = self::getThresholds();

        if ($daysLeft <= $t['critical']) {
            return \__('reminder.critical', ['days' => (string) $daysLeft]);
        }

        if ($daysLeft <= $t['warning']) {
            return \__('reminder.renewal_due', ['days' => (string) $daysLeft]);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function resolveDisplayPaymentStatus(array $item, int $daysLeft): string
    {
        $stored = (string) ($item['payment_status'] ?? 'due_soon');

        if ($stored === 'overdue' || $stored === 'not_applicable') {
            return $stored;
        }

        $t = self::getThresholds();

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

    private static function fullName(mixed $firstName, mixed $lastName): string
    {
        return trim((string) $firstName . ' ' . (string) $lastName);
    }
}

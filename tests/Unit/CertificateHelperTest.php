<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\CertificateHelper;
use App\Session;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CertificateHelperTest extends TestCase
{
    protected function setUp(): void
    {
        Session::ensureStarted();
        $_SESSION = [];
        unset($_GET['lang']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testThresholdsFollowTheAdminSettings(): void
    {
        $this->assertSame('expired', CertificateHelper::resolvePriority(-1));
        $this->assertSame('critical', CertificateHelper::resolvePriority(0));
        $this->assertSame('critical', CertificateHelper::resolvePriority(7));
        $this->assertSame('warning', CertificateHelper::resolvePriority(8));
        $this->assertSame('warning', CertificateHelper::resolvePriority(30));
        $this->assertSame('ok', CertificateHelper::resolvePriority(31));
    }

    public function testPriorityForUsesGivenThresholds(): void
    {
        $this->assertSame('expired', CertificateHelper::priorityFor(-3, 5, 20));
        $this->assertSame('critical', CertificateHelper::priorityFor(0, 5, 20));
        $this->assertSame('critical', CertificateHelper::priorityFor(5, 5, 20));
        $this->assertSame('warning', CertificateHelper::priorityFor(6, 5, 20));
        $this->assertSame('warning', CertificateHelper::priorityFor(20, 5, 20));
        $this->assertSame('ok', CertificateHelper::priorityFor(21, 5, 20));
    }

    public function testDiscountIsShownWithMinusSign(): void
    {
        $this->assertSame('-5%', CertificateHelper::formatDiscount(5.0));
        $this->assertSame('-10%', CertificateHelper::formatDiscount(10.0));
        $this->assertSame('-2,5%', CertificateHelper::formatDiscount(2.5));
        $this->assertSame('0%', CertificateHelper::formatDiscount(0.0));
    }

    public function testQualifiedCertificateTypesHaveLabels(): void
    {
        $this->assertSame('Certyfikat kwalifikowany', CertificateHelper::typeLabel('QUALIFIED_SIGNATURE'));
        $this->assertSame('Pieczęć kwalifikowana', CertificateHelper::typeLabel('QUALIFIED_SEAL'));
        $this->assertSame('Inne', CertificateHelper::typeLabel('SOMETHING_UNKNOWN'));
    }

    public function testEnrichAddsComputedFields(): void
    {
        $rows = CertificateHelper::enrich([[
            'certificate_type'       => 'QUALIFIED_SIGNATURE',
            'expiry_date'            => '2026-09-20',
            'discount_percent'       => '5.00',
            'status'                 => 'active',
            'payment_status'         => 'paid',
            'user_first_name'        => 'Ewa',
            'user_last_name'         => 'Pawlak',
            'beneficiary_first_name' => 'Jan',
            'beneficiary_last_name'  => 'Kowalski',
        ]], new DateTimeImmutable('2026-09-16'));

        $row = $rows[0];

        $this->assertSame(4, $row['days_left']);
        $this->assertSame('critical', $row['priority']);
        $this->assertSame('Ewa Pawlak', $row['owner_name']);
        $this->assertSame('Jan Kowalski', $row['beneficiary_name']);
        $this->assertSame('Certyfikat kwalifikowany', $row['type_label']);
        $this->assertSame(5.0, $row['discount_percent']);
        $this->assertSame('-5%', $row['discount_label']);
        // Opłacona pozycja w progu odnowienia wymaga kolejnej płatności — widok pokazuje „wkrótce”.
        $this->assertSame('due_soon', $row['display_payment_status']);
    }

    public function testEnrichHandlesCertificateWithoutBeneficiary(): void
    {
        $rows = CertificateHelper::enrich([[
            'certificate_type' => 'DOMAIN',
            'expiry_date'    => '2026-12-31',
            'discount_percent' => 3,
            'status'         => 'active',
            'payment_status' => 'paid',
        ]], new DateTimeImmutable('2026-09-16'));

        $this->assertSame('', $rows[0]['beneficiary_name']);
        $this->assertSame('ok', $rows[0]['priority']);
        $this->assertSame('paid', $rows[0]['display_payment_status']);
    }
}

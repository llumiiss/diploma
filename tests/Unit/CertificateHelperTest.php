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

    public function testCorporateThresholds(): void
    {
        $this->assertSame('expired', CertificateHelper::resolvePriority(-1, 'corporate'));
        $this->assertSame('critical', CertificateHelper::resolvePriority(0, 'corporate'));
        $this->assertSame('critical', CertificateHelper::resolvePriority(7, 'corporate'));
        $this->assertSame('warning', CertificateHelper::resolvePriority(8, 'corporate'));
        $this->assertSame('warning', CertificateHelper::resolvePriority(30, 'corporate'));
        $this->assertSame('ok', CertificateHelper::resolvePriority(31, 'corporate'));
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

    public function testAnnualizedCostFollowsBillingCycle(): void
    {
        $this->assertSame(1200.0, CertificateHelper::annualizedCost(100.0, 'monthly', null, '2027-01-01'));
        $this->assertSame(320.0, CertificateHelper::annualizedCost(320.0, 'annual', '2026-01-01', '2027-01-01'));
        // Certyfikat trzyletni: kwota za cały okres dzielona przez liczbę lat ważności.
        $this->assertSame(300.0, CertificateHelper::annualizedCost(900.0, 'multi_year', '2025-03-01', '2028-03-01'));
        $this->assertSame(450.0, CertificateHelper::annualizedCost(900.0, 'multi_year', '2026-01-10', '2028-01-05'));
        // Bez daty początku nie da się ustalić liczby lat — kwota bez zmian.
        $this->assertSame(900.0, CertificateHelper::annualizedCost(900.0, 'multi_year', null, '2028-03-01'));
        $this->assertSame(900.0, CertificateHelper::annualizedCost(900.0, 'multi_year', '2026-01-01', '2026-03-01'));
    }

    public function testPersonalThresholdsAreShorter(): void
    {
        $this->assertSame('critical', CertificateHelper::resolvePriority(3, 'personal'));
        $this->assertSame('warning', CertificateHelper::resolvePriority(4, 'personal'));
        $this->assertSame('warning', CertificateHelper::resolvePriority(15, 'personal'));
        $this->assertSame('ok', CertificateHelper::resolvePriority(16, 'personal'));
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
            'scope'                  => 'corporate',
            'certificate_type'       => 'QUALIFIED_SIGNATURE',
            'expiry_date'            => '2026-09-20',
            'annual_cost'            => '320.00',
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
        $this->assertSame(320.0, $row['annual_cost']);
        // Opłacona pozycja w progu odnowienia wymaga kolejnej płatności — widok pokazuje „wkrótce”.
        $this->assertSame('due_soon', $row['display_payment_status']);
    }

    public function testEnrichHandlesCertificateWithoutBeneficiary(): void
    {
        $rows = CertificateHelper::enrich([[
            'scope'          => 'corporate',
            'certificate_type' => 'DOMAIN',
            'expiry_date'    => '2026-12-31',
            'annual_cost'    => 129,
            'status'         => 'active',
            'payment_status' => 'paid',
        ]], new DateTimeImmutable('2026-09-16'));

        $this->assertSame('', $rows[0]['beneficiary_name']);
        $this->assertSame('ok', $rows[0]['priority']);
        $this->assertSame('paid', $rows[0]['display_payment_status']);
    }
}

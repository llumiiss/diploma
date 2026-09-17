<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\Actor;
use App\Service\EventLogger;
use App\Service\ReportService;
use App\Service\ServiceException;
use DateTimeImmutable;
use Tests\Support\IntegrationTestCase;

final class ReportServiceTest extends IntegrationTestCase
{
    private DateTimeImmutable $today;
    private Actor $owner;
    private Actor $colleague;
    private Actor $manager;
    private int $payerId;
    private int $personId;
    private int $critical;
    private int $colleagues;
    private int $archived;
    private int $renewed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = new DateTimeImmutable('today');
        $this->owner = $this->createActor(Rbac::OPERATOR);
        $this->colleague = $this->createActor(Rbac::OPERATOR);
        $this->manager = $this->createActor(Rbac::MANAGER);

        $this->payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.'], $this->owner->id);
        $this->personId = $this->insertBeneficiary($this->payerId, ['first_name' => 'Jan', 'last_name' => 'Kowalski'], $this->owner->id);

        // Certyfikat opiekuna: krytyczny, z otwartym zadaniem i zaproszeniem.
        $this->critical = $this->insertCertificate($this->owner->id, $this->payerId, [
            'name'           => 'Podpis kwalifikowany',
            'beneficiary_id' => $this->personId,
            'expiry_date'    => $this->day('+5 days'),
        ]);
        $this->db->exec("UPDATE certificates SET annual_cost = 320.00, billing_cycle = 'annual' WHERE id = {$this->critical}");
        // Certyfikat tej samej osoby prowadzony przez innego operatora.
        $this->colleagues = $this->insertCertificate($this->colleague->id, $this->payerId, [
            'name'           => 'Pieczęć kwalifikowana',
            'beneficiary_id' => $this->personId,
            'expiry_date'    => $this->day('+20 days'),
        ]);
        $this->db->exec("UPDATE certificates SET annual_cost = 25.00, billing_cycle = 'monthly', renewal_lead_days = 60 WHERE id = {$this->colleagues}");
        // Łańcuch odnowień: stary certyfikat w archiwum, nowy ważny ponad rok.
        $this->archived = $this->insertCertificate($this->owner->id, $this->payerId, [
            'name'           => 'Podpis kwalifikowany 2024',
            'beneficiary_id' => $this->personId,
            'expiry_date'    => $this->day('-30 days'),
            'archived_at'    => $this->day('-35 days') . ' 12:00:00',
        ]);
        $this->renewed = $this->insertCertificate($this->owner->id, $this->payerId, [
            'name'           => 'Podpis kwalifikowany 2026',
            'beneficiary_id' => $this->personId,
            'expiry_date'    => $this->day('+400 days'),
        ]);
        $this->db->exec("UPDATE certificates SET previous_certificate_id = {$this->archived} WHERE id = {$this->renewed}");

        $this->db->exec(
            "INSERT INTO renewal_tasks (certificate_id, assigned_user_id, status, priority, due_date)
             VALUES ({$this->critical}, {$this->owner->id}, 'in_progress', 'critical', '{$this->day('+5 days')}'),
                    ({$this->archived}, {$this->owner->id}, 'done', 'warning', '{$this->day('-30 days')}')"
        );
        $this->db->exec(
            "INSERT INTO invitations (certificate_id, recipient_type, recipient_email, subject, body_html, status, sent_at, reminder_count, last_reminder_at)
             VALUES ({$this->critical}, 'beneficiary', 'jan@example.com', 'Odnowienie', '<p>x</p>', 'sent',
                     '{$this->day('-6 days')} 09:00:00', 1, '{$this->day('-2 days')} 09:00:00')"
        );

        $events = new EventLogger($this->db);
        $context = static fn (int $certificateId, ?int $personId, int $payerId): array => [
            'certificate_id' => $certificateId, 'beneficiary_id' => $personId, 'payer_id' => $payerId,
        ];
        $events->log('certificate', $this->critical, 'updated', $this->owner->id, $context($this->critical, $this->personId, $this->payerId));
        $events->log('certificate', $this->colleagues, 'updated', $this->colleague->id, $context($this->colleagues, $this->personId, $this->payerId));
        $events->log('beneficiary', $this->personId, 'updated', $this->owner->id, ['beneficiary_id' => $this->personId, 'payer_id' => $this->payerId]);
    }

    private function day(string $modifier): string
    {
        return $this->today->modify($modifier)->format('Y-m-d');
    }

    private function service(): ReportService
    {
        return new ReportService($this->db);
    }

    public function testBeneficiaryCardShowsRenewalDatesTasksInvitationsAndHistory(): void
    {
        $card = $this->service()->beneficiaryCard($this->manager, $this->personId, $this->today);

        $this->assertSame('Kowalski', $card['beneficiary']['last_name']);
        $this->assertSame([$this->critical, $this->colleagues, $this->renewed], array_column($card['certificates'], 'id'));
        $this->assertSame([$this->archived], array_column($card['history'], 'id'));
        $this->assertSame($this->renewed, $card['history'][0]['next_certificate_id']);
        $this->assertTrue($card['archive_access']);

        [$critical, $colleagues, $renewed] = $card['certificates'];
        $this->assertSame('critical', $critical['priority']);
        $this->assertSame(5, $critical['days_left']);
        // Bez własnego wymaganego czasu odnowienia obowiązuje domyślny margines (30 dni).
        $this->assertSame(30, $critical['renewal_lead_days']);
        $this->assertFalse($critical['renewal_lead_custom']);
        $this->assertSame($this->day('-25 days'), $critical['renewal_from']);
        $this->assertTrue($critical['in_renewal_window']);
        $this->assertSame('in_progress', $critical['open_task']['status']);
        $this->assertSame(1, $critical['invitation_count']);
        $this->assertSame('warning', $colleagues['priority']);
        $this->assertSame($this->day('-40 days'), $colleagues['renewal_from']);
        $this->assertTrue($colleagues['renewal_lead_custom']);
        $this->assertSame($this->archived, $renewed['previous_certificate_id']);
        $this->assertFalse($renewed['in_renewal_window']);
        $this->assertSame('ok', $renewed['priority']);

        $summary = $card['summary'];
        $this->assertSame(3, $summary['active_certificates']);
        $this->assertSame(1, $summary['archived_certificates']);
        $this->assertSame(0, $summary['expired']);
        $this->assertSame(1, $summary['critical']);
        $this->assertSame(2, $summary['expiring_soon']);
        $this->assertSame($this->critical, $summary['next_expiry']['certificate_id']);
        $this->assertSame(1, $summary['open_tasks']);
        $this->assertSame(['todo' => 0, 'in_progress' => 1, 'done' => 1, 'abandoned' => 0], $summary['tasks_by_status']);
        $this->assertSame(1, $summary['invitations_sent']);
        $this->assertSame(1, $summary['reminders_sent']);
        $this->assertSame($this->day('-2 days') . ' 09:00:00', $summary['last_contact_at']);
        $this->assertSame(1, $summary['renewals']);

        $this->assertCount(2, $card['tasks']);
        $this->assertCount(1, $card['invitations']);
        $this->assertCount(3, $card['timeline']);
    }

    public function testOperatorCardContainsOnlyOwnCertificatesAndTheirEvents(): void
    {
        $card = $this->service()->beneficiaryCard($this->owner, $this->personId, $this->today);

        $this->assertSame([$this->critical, $this->renewed], array_column($card['certificates'], 'id'));
        $this->assertSame([], $card['history']);
        $this->assertFalse($card['archive_access']);
        // Zdarzenie certyfikatu kolegi nie trafia do historii osoby widzianej przez operatora.
        $this->assertNotContains($this->colleagues, array_column($card['timeline'], 'certificate_id'));
        $this->assertCount(2, $card['timeline']);
        $this->assertSame(1, $card['summary']['expiring_soon']);
    }

    public function testCardOfPersonOutsideOperatorScopeIsNotFound(): void
    {
        $otherPayer = $this->insertPayer([], $this->colleague->id);
        $stranger = $this->insertBeneficiary($otherPayer, ['first_name' => 'Anna', 'last_name' => 'Obca'], $this->colleague->id);
        $this->insertCertificate($this->colleague->id, $otherPayer, ['beneficiary_id' => $stranger]);

        try {
            $this->service()->beneficiaryCard($this->owner, $stranger, $this->today);
            $this->fail('Operator nie powinien zobaczyć karty cudzej osoby.');
        } catch (ServiceException $e) {
            $this->assertSame(404, $e->httpStatus());
        }

        try {
            $this->service()->payerCard($this->owner, $otherPayer, $this->today);
            $this->fail('Operator nie powinien zobaczyć karty cudzego płatnika.');
        } catch (ServiceException $e) {
            $this->assertSame(404, $e->httpStatus());
        }
    }

    public function testPayerCardListsRelatedPeopleScheduleAndAnnualCost(): void
    {
        // Osoba przypisana do innego płatnika, ale jej certyfikat opłaca NovaTech.
        $otherPayer = $this->insertPayer(['company_name' => 'Grupa Wisła'], $this->owner->id);
        $guest = $this->insertBeneficiary($otherPayer, ['first_name' => 'Ewa', 'last_name' => 'Gość'], $this->owner->id);
        $guestCertificate = $this->insertCertificate($this->owner->id, $this->payerId, [
            'beneficiary_id' => $guest,
            'expiry_date'    => $this->day('-3 days'),
        ]);

        $card = $this->service()->payerCard($this->manager, $this->payerId, $this->today);

        $this->assertSame('NovaTech Sp. z o.o.', $card['payer']['company_name']);
        $people = array_column($card['beneficiaries'], null, 'id');
        $this->assertTrue($people[$this->personId]['linked_directly']);
        $this->assertSame(3, $people[$this->personId]['certificate_count']);
        $this->assertFalse($people[$guest]['linked_directly']);
        $this->assertSame(-3, $people[$guest]['days_left']);
        $this->assertSame(2, $card['summary']['beneficiaries']);
        $this->assertSame(1, $card['summary']['expired']);

        // 320 zł rocznie + 25 zł miesięcznie × 12; certyfikat z archiwum się nie liczy.
        $this->assertSame([['currency' => 'PLN', 'amount' => 620.0]], $card['summary']['annual_cost']);

        $buckets = array_column($card['schedule'], null, 'key');
        $this->assertCount(13, $card['schedule']);
        $this->assertSame([$guestCertificate], array_column($buckets['overdue']['certificates'], 'id'));
        $this->assertContains($this->critical, array_column($buckets[substr($this->day('+5 days'), 0, 7)]['certificates'], 'id'));
        $this->assertContains($this->colleagues, array_column($buckets[substr($this->day('+20 days'), 0, 7)]['certificates'], 'id'));
        $this->assertCount(3, $card['timeline']);
    }

    public function testScheduleFiltersAndValidatesRange(): void
    {
        $service = $this->service();
        $otherPayer = $this->insertPayer([], $this->manager->id);
        $this->insertCertificate($this->manager->id, $otherPayer, [
            'certificate_type' => 'SSL_CERTIFICATE',
            'expiry_date'      => $this->day('+10 days'),
        ]);

        $all = $service->schedule($this->manager, ['months' => 3], $this->today);
        $this->assertSame(3, $all['months']);
        $this->assertCount(4, $all['buckets']);
        $this->assertSame(3, $all['total']);

        $payer = $service->schedule($this->manager, ['months' => 3, 'payer_id' => $this->payerId], $this->today);
        $this->assertSame(2, $payer['total']);

        $ssl = $service->schedule($this->manager, ['months' => 3, 'certificate_type' => 'SSL_CERTIFICATE'], $this->today);
        $this->assertSame(1, $ssl['total']);

        $operator = $service->schedule($this->owner, ['months' => 24], $this->today);
        $this->assertSame(2, $operator['total']);

        try {
            $service->schedule($this->manager, ['months' => 30], $this->today);
            $this->fail('Zakres ponad 24 miesiące powinien zostać odrzucony.');
        } catch (ServiceException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertArrayHasKey('months', $e->errors);
        }
    }
}

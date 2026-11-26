<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\BeneficiaryService;
use App\Service\CertificateService;
use App\Service\CompanyHistoryService;
use App\Service\PayerService;
use App\Service\ReportService;
use App\Service\SearchService;
use App\Service\ServiceException;
use App\Service\TaskService;
use Tests\Support\IntegrationTestCase;

/**
 * Etap 12: historia firm osoby (kilka firm naraz, przenieś / dodaj / zakończ) i historia płatników certyfikatu.
 */
final class CompanyHistoryTest extends IntegrationTestCase
{
    public function testCreatingAPersonOpensAPeriodInTheCompany(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $payerId = $this->insertPayer();
        $person = (new BeneficiaryService($this->db))->create($manager, ['first_name' => 'Ewa', 'last_name' => 'Nowa', 'payer_id' => $payerId]);

        $this->assertCount(1, $person['employments']);
        $this->assertSame($payerId, $person['employments'][0]['payer_id']);
        $this->assertTrue($person['employments'][0]['is_primary']);
        $this->assertNull($person['employments'][0]['ended_on']);
    }

    public function testMoveKeepsCertificatesWithThePersonAndOptionallyChangesThePayer(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new BeneficiaryService($this->db);
        $oldCompany = $this->insertPayer(['company_name' => 'Stara Firma']);
        $newCompany = $this->insertPayer(['company_name' => 'Nowa Firma']);
        $person = $service->create($manager, ['first_name' => 'Adam', 'last_name' => 'Zmienny', 'payer_id' => $oldCompany]);
        $certificate = (new CertificateService($this->db))->create($manager, [
            'name' => 'Podpis kwalifikowany', 'certificate_type' => 'QUALIFIED_SIGNATURE',
            'valid_from' => date('Y-m-d', strtotime('-100 days')), 'expiry_date' => date('Y-m-d', strtotime('+200 days')), 'beneficiary_id' => $person['id'],
        ]);
        $this->assertSame($oldCompany, $certificate['payer_id']);

        // Bez zaznaczenia płatnik zostaje — certyfikat nie trafia do archiwum.
        $moved = $service->moveCompany($manager, $person['id'], ['payer_id' => $newCompany]);
        $this->assertSame($newCompany, $moved['payer_id']);
        $byPayer = array_column($moved['employments'], null, 'payer_id');
        $this->assertNotNull($byPayer[$oldCompany]['ended_on']);
        $this->assertNull($byPayer[$newCompany]['ended_on']);
        $current = (new CertificateService($this->db))->get($manager, $certificate['id']);
        $this->assertNull($current['archived_at']);
        $this->assertSame($oldCompany, $current['payer_id']);
        $this->assertSame(1, $this->countEvents('beneficiary', $person['id'], 'company_moved'));

        // Powrót do dawnej firmy z przeniesieniem płatnika: historia płatników ma dwa okresy.
        $service->moveCompany($manager, $person['id'], ['payer_id' => $oldCompany, 'transfer_payer' => true]);
        $service->moveCompany($manager, $person['id'], ['payer_id' => $newCompany, 'transfer_payer' => true]);
        $current = (new CertificateService($this->db))->get($manager, $certificate['id']);
        $this->assertSame($newCompany, $current['payer_id']);
        $this->assertCount(2, $current['payer_history']);
        $this->assertSame(1, $this->countEvents('certificate', $certificate['id'], 'payer_changed'));
    }

    public function testPersonCanWorkForTwoCompaniesAndTheLastOneCannotBeEnded(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new BeneficiaryService($this->db);
        $first = $this->insertPayer();
        $second = $this->insertPayer();
        $person = $service->create($manager, ['first_name' => 'Ola', 'last_name' => 'Dwojaka', 'payer_id' => $first]);

        $person = $service->addCompany($manager, $person['id'], ['payer_id' => $second]);
        $this->assertCount(2, array_filter($person['employments'], static fn (array $e): bool => $e['is_current']));
        $this->assertSame($first, $person['payer_id']);

        try {
            $service->addCompany($manager, $person['id'], ['payer_id' => $second]);
            $this->fail('Druga otwarta pozycja w tej samej firmie nie może powstać.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('payer_id', $e->errors);
        }

        // Zakończenie firmy głównej przenosi status „główna” na drugą firmę.
        $primary = array_values(array_filter($person['employments'], static fn (array $e): bool => $e['payer_id'] === $first))[0];
        $person = $service->endCompany($manager, $person['id'], ['employment_id' => $primary['id']]);
        $this->assertSame($second, $person['payer_id']);

        $last = array_values(array_filter($person['employments'], static fn (array $e): bool => $e['is_current']))[0];
        try {
            $service->endCompany($manager, $person['id'], ['employment_id' => $last['id']]);
            $this->fail('Osoba nie może zostać bez firmy.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
        }

        // Poprawka pomyłki w datach zakończonego okresu.
        $closed = array_values(array_filter($person['employments'], static fn (array $e): bool => !$e['is_current']))[0];
        $person = $service->correctCompany($manager, $person['id'], [
            'employment_id' => $closed['id'], 'started_on' => '2020-01-01', 'ended_on' => '2021-06-30', 'note' => 'poprawka',
        ]);
        $corrected = array_values(array_filter($person['employments'], static fn (array $e): bool => $e['id'] === $closed['id']))[0];
        $this->assertSame('2020-01-01', $corrected['started_on']);
        $this->assertSame('2021-06-30', $corrected['ended_on']);
        $this->assertSame(1, $this->countEvents('beneficiary', $person['id'], 'company_corrected'));
    }

    public function testRegularEditCorrectsTheCompanyWithoutANewPeriod(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new BeneficiaryService($this->db);
        $wrong = $this->insertPayer();
        $right = $this->insertPayer();
        $person = $service->create($manager, ['first_name' => 'Piotr', 'last_name' => 'Pomyłka', 'payer_id' => $wrong]);

        $person = $service->update($manager, $person['id'], ['first_name' => 'Piotr', 'last_name' => 'Pomyłka', 'payer_id' => $right]);
        $this->assertCount(1, $person['employments']);
        $this->assertSame($right, $person['employments'][0]['payer_id']);
    }

    public function testArchivingThePersonEndsPeriodsAndRestoreReopensThePrimaryCompany(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new BeneficiaryService($this->db);
        $payerId = $this->insertPayer();
        $other = $this->insertPayer();
        $person = $service->create($manager, ['first_name' => 'Iza', 'last_name' => 'Archiwalna', 'payer_id' => $payerId]);
        $service->addCompany($manager, $person['id'], ['payer_id' => $other]);

        $archived = $service->archive($manager, $person['id']);
        $this->assertSame([], array_filter($archived['employments'], static fn (array $e): bool => $e['is_current']));

        $restored = $service->restore($manager, $person['id']);
        $current = array_values(array_filter($restored['employments'], static fn (array $e): bool => $e['is_current']));
        $this->assertCount(1, $current);
        $this->assertSame($payerId, $current[0]['payer_id']);
    }

    public function testPayerChangesInPaymentEditAndRenewalAreRecorded(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $accountant = $this->createActor(Rbac::ACCOUNTANT);
        $first = $this->insertPayer(['company_name' => 'Płaci w 2025']);
        $second = $this->insertPayer(['company_name' => 'Płaci w 2026']);
        $people = new BeneficiaryService($this->db);
        $person = $people->create($manager, ['first_name' => 'Marta', 'last_name' => 'Dwupłatna', 'payer_id' => $first]);
        $people->addCompany($manager, $person['id'], ['payer_id' => $second]);

        $certificates = new CertificateService($this->db);
        $certificate = $certificates->create($manager, [
            'name' => 'Podpis', 'certificate_type' => 'QUALIFIED_SIGNATURE', 'valid_from' => date('Y-m-d', strtotime('-345 days')), 'expiry_date' => date('Y-m-d', strtotime('+20 days')),
            'beneficiary_id' => $person['id'], 'payer_id' => $first,
        ]);
        $this->assertCount(1, $certificate['payer_history']);

        $paid = $certificates->updatePayment($accountant, $certificate['id'], [
            'payment_status' => 'paid', 'payer_id' => $second, 'payer_note' => 'w tym roku płaci druga firma',
        ]);
        $this->assertSame($second, $paid['payer_id']);
        $this->assertCount(2, $paid['payer_history']);
        $this->assertSame('w tym roku płaci druga firma', $paid['payer_history'][0]['note']);

        // Odnowienie z innym płatnikiem: nowy certyfikat ma własną historię, stary okres jest zamknięty.
        $this->db->prepare("INSERT INTO renewal_tasks (certificate_id, status, priority, due_date) VALUES (:certificate_id, 'todo', 'warning', :due_date)")
            ->execute(['certificate_id' => $certificate['id'], 'due_date' => $certificate['expiry_date']]);
        $taskId = (int) $this->db->lastInsertId();
        $result = (new TaskService($this->db))->renew($manager, $taskId, [
            'expiry_date' => date('Y-m-d', strtotime('+400 days')), 'payer_id' => $first,
        ]);
        $renewed = $certificates->get($manager, (int) $result['certificate']['id']);
        $this->assertSame($first, $renewed['payer_id']);
        $this->assertCount(1, $renewed['payer_history']);
        $old = $certificates->get($manager, $certificate['id']);
        $this->assertSame([], array_filter($old['payer_history'], static fn (array $p): bool => $p['ended_on'] === null));
    }

    public function testArchiveSearchFindsPeopleByCompanyTaxIdAndDates(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $people = new BeneficiaryService($this->db);
        $old = $this->insertPayer(['company_name' => 'Huta Dawna', 'tax_id' => '5260250995']);
        $new = $this->insertPayer(['company_name' => 'Biuro Nowe']);
        $person = $people->create($manager, ['first_name' => 'Karol', 'last_name' => 'Wędrowny', 'payer_id' => $old]);
        $people->moveCompany($manager, $person['id'], ['payer_id' => $new]);
        $employments = $people->get($manager, $person['id'])['employments'];
        $oldPeriod = array_values(array_filter($employments, static fn (array $e): bool => $e['payer_id'] === $old))[0];
        $people->correctCompany($manager, $person['id'], ['employment_id' => $oldPeriod['id'], 'started_on' => '2019-03-01', 'ended_on' => '2022-12-31']);

        $history = new CompanyHistoryService($this->db);
        $byTax = $history->search($manager, ['q' => '526-025-09-95']);
        $this->assertSame(['Huta Dawna'], array_column($byTax['items'], 'company_name'));
        $this->assertFalse($byTax['items'][0]['is_current']);

        $in2020 = $history->search($manager, ['from' => '2020-01-01', 'to' => '2020-12-31']);
        $this->assertSame(['Huta Dawna'], array_column($in2020['items'], 'company_name'));
        $current = $history->search($manager, ['q' => 'Wędrowny', 'state' => 'current']);
        $this->assertSame(['Biuro Nowe'], array_column($current['items'], 'company_name'));

        // Operator bez archiwum nie ma wglądu w historię.
        $this->expectException(ServiceException::class);
        $history->search($this->createActor(Rbac::OPERATOR), []);
    }

    public function testSecondCompanyShowsThePersonOnTheCompanyCardAndInSearch(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $people = new BeneficiaryService($this->db);
        $home = $this->insertPayer(['company_name' => 'Firma Główna']);
        $side = $this->insertPayer(['company_name' => 'Firma Poboczna']);
        $person = $people->create($manager, ['first_name' => 'Lena', 'last_name' => 'Równoległa', 'payer_id' => $home]);
        $people->addCompany($manager, $person['id'], ['payer_id' => $side]);

        $card = (new ReportService($this->db))->payerCard($manager, $side);
        $this->assertSame([$person['id']], array_column($card['beneficiaries'], 'id'));
        $this->assertTrue($card['beneficiaries'][0]['is_current']);

        $listed = array_column($people->list($manager), null, 'id');
        $this->assertSame('Firma Poboczna', $listed[$person['id']]['other_companies']);

        $found = (new SearchService($this->db))->search($manager, 'Poboczna');
        $this->assertContains($person['id'], array_column($found['groups']['beneficiaries']['items'], 'id'));
    }

    public function testPayerChangeDatedBeforeThePeriodStartReplacesThePayerOfThatPeriod(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $people = new BeneficiaryService($this->db);
        $old = $this->insertPayer();
        $new = $this->insertPayer();
        $person = $people->create($manager, ['first_name' => 'Ida', 'last_name' => 'Wcześniejsza', 'payer_id' => $old]);
        $certificate = (new CertificateService($this->db))->create($manager, [
            'name' => 'Podpis od przyszłego miesiąca', 'certificate_type' => 'QUALIFIED_SIGNATURE', 'beneficiary_id' => $person['id'],
            'valid_from' => date('Y-m-d', strtotime('+10 days')), 'expiry_date' => date('Y-m-d', strtotime('+375 days')),
        ]);

        $people->moveCompany($manager, $person['id'], ['payer_id' => $new, 'transfer_payer' => true]);
        $history = (new CertificateService($this->db))->get($manager, $certificate['id'])['payer_history'];
        $this->assertCount(1, $history);
        $this->assertSame($new, $history[0]['payer_id']);
        $this->assertNull($history[0]['ended_on']);
    }

    public function testArchivingACompanyEndsSecondaryPeriods(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $people = new BeneficiaryService($this->db);
        $home = $this->insertPayer();
        $side = $this->insertPayer();
        $person = $people->create($manager, ['first_name' => 'Tomasz', 'last_name' => 'Boczny', 'payer_id' => $home]);
        $people->addCompany($manager, $person['id'], ['payer_id' => $side]);

        (new PayerService($this->db))->archive($manager, $side);
        $current = array_filter($people->get($manager, $person['id'])['employments'], static fn (array $e): bool => $e['is_current']);
        $this->assertSame([$home], array_values(array_column($current, 'payer_id')));
    }
}

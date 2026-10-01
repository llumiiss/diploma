<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\PayerService;
use App\Service\ServiceException;
use Tests\Support\IntegrationTestCase;

final class PayerServiceTest extends IntegrationTestCase
{
    private const VALID_NIP = '6342851974';

    public function testOperatorSeesOnlyOwnAndLinkedPayers(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $service = new PayerService($this->db);

        $own = $service->create($operator, ['company_name' => 'Własny Sp. z o.o.', 'contact_person' => 'Anna Nowak']);
        $hidden = $this->insertPayer(['company_name' => 'Cudzy S.A.'], $manager->id);
        $linked = $this->insertPayer(['company_name' => 'Powiązany S.A.'], $manager->id);
        $this->insertCertificate($operator->id, $linked);

        $operatorNames = array_column($service->list($operator), 'company_name');
        $this->assertSame(['Powiązany S.A.', 'Własny Sp. z o.o.'], $operatorNames);

        $managerIds = array_column($service->list($manager), 'id');
        $this->assertContains($hidden, $managerIds);
        $this->assertContains($own['id'], $managerIds);

        $this->expectException(ServiceException::class);
        $service->get($operator, $hidden);
    }

    public function testCreateValidatesRequiredFieldsAndTaxId(): void
    {
        $service = new PayerService($this->db);
        $operator = $this->createActor();

        try {
            $service->create($operator, ['company_name' => '  ', 'tax_id' => '1234567890', 'email' => 'zły-adres']);
            $this->fail('Walidacja powinna odrzucić dane.');
        } catch (ServiceException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertArrayHasKey('company_name', $e->errors);
            $this->assertArrayHasKey('contact_person', $e->errors);
            $this->assertArrayHasKey('tax_id', $e->errors);
            $this->assertArrayHasKey('email', $e->errors);
        }

        $payer = $service->create($operator, [
            'company_name'   => 'NIP z kreskami',
            'contact_person' => 'Jan Kowalski',
            'tax_id'         => 'PL 634-285-19-74',
        ]);
        $this->assertSame(self::VALID_NIP, $payer['tax_id']);
    }

    public function testDuplicateTaxIdDetailsAreHiddenFromOperatorWhoCannotSeeThePayer(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $service = new PayerService($this->db);
        $existing = $service->create($manager, ['company_name' => 'NovaTech', 'contact_person' => 'K. Zielińska', 'tax_id' => self::VALID_NIP]);

        try {
            $service->create($operator, ['company_name' => 'Duplikat', 'contact_person' => 'X', 'tax_id' => self::VALID_NIP]);
            $this->fail('Duplikat NIP powinien zostać odrzucony.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame([], $e->details);
            $this->assertStringNotContainsString('NovaTech', $e->getMessage());
        }

        try {
            $service->create($manager, ['company_name' => 'Duplikat', 'contact_person' => 'X', 'tax_id' => self::VALID_NIP]);
            $this->fail('Duplikat NIP powinien zostać odrzucony.');
        } catch (ServiceException $e) {
            $this->assertSame($existing['id'], $e->details['existing']['id']);
        }
    }

    public function testArchiveIsBlockedWhileCertificatesAreActiveAndCanBeRestored(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new PayerService($this->db);
        $payerId = $this->insertPayer();
        $certificateId = $this->insertCertificate($manager->id, $payerId);

        try {
            $service->archive($manager, $payerId);
            $this->fail('Płatnik z aktywnym certyfikatem nie może trafić do archiwum.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(1, $e->details['certificates']);
        }

        $this->db->exec("UPDATE certificates SET archived_at = NOW() WHERE id = {$certificateId}");
        // Domyślny użytkownik certyfikatu z helpera też jest bieżący i też blokuje archiwizację firmy.
        $this->db->exec("UPDATE beneficiaries SET archived_at = NOW() WHERE payer_id = {$payerId}");
        $archived = $service->archive($manager, $payerId);
        $this->assertNotNull($archived['archived_at']);
        $this->assertNotContains($payerId, array_column($service->list($manager), 'id'));
        $this->assertContains($payerId, array_column($service->list($manager, true), 'id'));

        $restored = $service->restore($manager, $payerId);
        $this->assertNull($restored['archived_at']);
        $this->assertSame(1, $this->countEvents('payer', $payerId, 'archived'));
        $this->assertSame(1, $this->countEvents('payer', $payerId, 'restored'));
    }

    public function testOperatorCannotArchive(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $service = new PayerService($this->db);
        $payer = $service->create($operator, ['company_name' => 'Firma', 'contact_person' => 'Osoba']);

        try {
            $service->archive($operator, $payer['id']);
            $this->fail('Operator nie może archiwizować płatników.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }
    }

    public function testUpdateRecordsChangedFieldsInHistory(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $service = new PayerService($this->db);
        $payer = $service->create($operator, ['company_name' => 'Firma', 'contact_person' => 'Stara Osoba', 'city' => 'Katowice']);

        $service->update($operator, $payer['id'], ['company_name' => 'Firma', 'contact_person' => 'Nowa Osoba', 'city' => 'Katowice']);

        $payload = $this->db->query(
            "SELECT payload FROM events WHERE entity_type = 'payer' AND event_type = 'updated' AND entity_id = {$payer['id']}"
        )->fetchColumn();
        $changes = json_decode((string) $payload, true)['changes'];
        // MySQL porządkuje klucze obiektów JSON po swojemu, więc porównujemy bez kolejności.
        $this->assertEquals(['contact_person' => ['from' => 'Stara Osoba', 'to' => 'Nowa Osoba']], $changes);
    }
}

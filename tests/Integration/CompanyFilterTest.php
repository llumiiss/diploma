<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\PreferencesController;
use App\CertificateManager;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Rbac;
use App\Service\Actor;
use App\Service\BeneficiaryService;
use App\Service\CertificateService;
use App\Service\CompanyFilterService;
use App\Service\ExportService;
use App\Service\InvitationService;
use App\Service\PayerService;
use App\Service\ServiceException;
use App\Service\TaskService;
use App\UserManager;
use Tests\Support\IntegrationTestCase;

/**
 * Filtr firm operatora (Etap 10): wszystkie firmy, jedna wybrana albo lista wybranych.
 */
final class CompanyFilterTest extends IntegrationTestCase
{
    private Actor $operator;
    private int $nova;
    private int $wisla;
    private int $fundacja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = $this->createActor(Rbac::OPERATOR);

        $this->nova = $this->insertPayer(['company_name' => 'NovaTech', 'tax_id' => '6342851974'], $this->operator->id);
        $this->wisla = $this->insertPayer(['company_name' => 'Grupa Wisła', 'tax_id' => '9876543217'], $this->operator->id);
        $this->fundacja = $this->insertPayer(['company_name' => 'Fundacja', 'tax_id' => '5551112223'], $this->operator->id);
        foreach ([[$this->nova, 'Jan'], [$this->wisla, 'Piotr'], [$this->fundacja, 'Agnieszka']] as [$payerId, $name]) {
            $personId = $this->insertBeneficiary($payerId, ['first_name' => $name, 'last_name' => 'Test', 'email' => strtolower($name) . '@example.com'], $this->operator->id);
            $this->insertCertificate($this->operator->id, $payerId, ['name' => 'Podpis ' . $name, 'beneficiary_id' => $personId, 'expiry_date' => date('Y-m-d', strtotime('+3 days'))]);
        }
        $this->insertCertificate($this->operator->id, $this->nova, ['name' => 'Domena NovaTech', 'certificate_type' => 'DOMAIN']);
    }

    /**
     * Konto z filtrem odczytanym z bazy — tak jak robi to ApiKernel przy każdym żądaniu.
     */
    private function reload(Actor $actor): Actor
    {
        $user = (new UserManager($this->db))->findById($actor->id);
        $this->assertIsArray($user);

        return Actor::fromUser($user);
    }

    /**
     * @return list<string>
     */
    private function certificateNames(Actor $actor): array
    {
        $names = array_column((new CertificateService($this->db))->list($actor), 'name');
        sort($names);

        return $names;
    }

    public function testNoFilterMeansAllCompanies(): void
    {
        $service = new CompanyFilterService($this->db);

        $state = $service->get($this->operator);
        $this->assertSame('all', $state['mode']);
        $this->assertSame([], $state['ids']);
        $this->assertCount(3, $state['available']);
        $this->assertCount(4, $this->certificateNames($this->operator));
    }

    public function testOneSelectedCompany(): void
    {
        $service = new CompanyFilterService($this->db);

        $state = $service->set($this->operator, 'one', [$this->nova]);
        $this->assertSame(['mode' => 'one', 'ids' => [$this->nova]], ['mode' => $state['mode'], 'ids' => $state['ids']]);

        $actor = $this->reload($this->operator);
        $this->assertSame([$this->nova], $actor->companyIds);
        $this->assertSame(['Domena NovaTech', 'Podpis Jan'], $this->certificateNames($actor));
        $this->assertSame([$this->nova], array_column((new PayerService($this->db))->list($actor), 'id'));
        $this->assertSame(['Jan'], array_column((new BeneficiaryService($this->db))->list($actor), 'first_name'));
    }

    public function testListOfSelectedCompanies(): void
    {
        $service = new CompanyFilterService($this->db);
        $service->set($this->operator, 'list', [$this->wisla, (string) $this->fundacja, $this->wisla]);

        $state = $service->get($this->operator);
        $this->assertSame('list', $state['mode']);
        $this->assertEqualsCanonicalizing([$this->wisla, $this->fundacja], $state['ids']);

        $actor = $this->reload($this->operator);
        $this->assertSame(['Podpis Agnieszka', 'Podpis Piotr'], $this->certificateNames($actor));
        $payers = array_column((new PayerService($this->db))->list($actor), 'id');
        $this->assertEqualsCanonicalizing([$this->wisla, $this->fundacja], $payers);

        // Lista z jedną firmą zostaje listą, a nie przechodzi po cichu w tryb „jedna”.
        $this->assertSame('list', $service->set($this->operator, 'list', [$this->nova])['mode']);
    }

    public function testChoosingAllClearsTheFilter(): void
    {
        $service = new CompanyFilterService($this->db);
        $service->set($this->operator, 'one', [$this->nova]);
        $service->set($this->operator, 'all', []);

        $this->assertNull($this->reload($this->operator)->companyIds);
        $this->assertNull($this->db->query("SELECT company_filter FROM users WHERE id = {$this->operator->id}")->fetchColumn());
        $this->assertCount(4, $this->certificateNames($this->reload($this->operator)));
    }

    public function testFilterNarrowsDashboardTasksAndInvitations(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $tasks = new TaskService($this->db);
        foreach (['Podpis Jan', 'Podpis Piotr'] as $name) {
            $id = (int) $this->db->query("SELECT id FROM certificates WHERE name = '{$name}'")->fetchColumn();
            $tasks->create($manager, ['certificate_id' => $id]);
        }

        $service = new CompanyFilterService($this->db);
        $service->set($manager, 'one', [$this->wisla]);
        $filtered = $this->reload($manager);

        $this->assertSame(1, array_sum((new CertificateManager($this->db))->getStatusStats($filtered)));
        $this->assertSame(4, array_sum((new CertificateManager($this->db))->getStatusStats($manager)));
        $this->assertSame(['Podpis Piotr'], array_column($tasks->list($filtered), 'certificate_name'));
        $this->assertSame(1, $tasks->stats($filtered)['open']);
        $this->assertSame(2, $tasks->stats($manager)['open']);
        $this->assertSame([], (new InvitationService($this->db))->list($filtered));
    }

    public function testExportIgnoresTheFilter(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $service = new CompanyFilterService($this->db);
        $service->set($manager, 'one', [$this->nova]);

        $csv = (new ExportService($this->db))->export($this->reload($manager), 'certificates', 'csv');

        $this->assertSame(4, substr_count(trim($csv['body']), "\n"));
    }

    public function testOnlyVisibleActiveCompaniesCanBeChosen(): void
    {
        $service = new CompanyFilterService($this->db);
        $foreign = $this->insertPayer(['company_name' => 'Obca firma', 'tax_id' => '1111111111']);
        $foreignWithValidNip = $this->insertPayer(['company_name' => 'Obca firma 2', 'tax_id' => '5260250995']);

        foreach ([$foreign, $foreignWithValidNip, 999999] as $id) {
            try {
                $service->set($this->operator, 'one', [$id]);
                $this->fail('Operator nie wybierze firmy spoza swojego zakresu.');
            } catch (ServiceException $e) {
                $this->assertSame(422, $e->httpStatus());
                $this->assertArrayHasKey('ids', $e->errors);
            }
        }

        $this->db->exec("UPDATE payers SET archived_at = NOW() WHERE id = {$this->fundacja}");
        $this->expectException(ServiceException::class);
        $service->set($this->operator, 'list', [$this->fundacja]);
    }

    public function testInvalidSelectionsAreRejected(): void
    {
        $service = new CompanyFilterService($this->db);

        foreach ([['one', []], ['one', [$this->nova, $this->wisla]], ['list', []], ['bogus', [$this->nova]], ['list', ['abc', -1, 0]]] as [$mode, $ids]) {
            try {
                $service->set($this->operator, $mode, $ids);
                $this->fail("Tryb {$mode} z listą " . json_encode($ids) . ' powinien zostać odrzucony.');
            } catch (ServiceException $e) {
                $this->assertSame(422, $e->httpStatus());
            }
        }

        $this->assertSame('all', $service->get($this->operator)['mode']);
    }

    public function testEmployeeIgnoresTheFilterAndKeepsPersonalScope(): void
    {
        $employee = $this->createActor(Rbac::EMPLOYEE);
        $this->db->exec("UPDATE users SET company_filter = JSON_OBJECT('mode', 'one', 'ids', JSON_ARRAY({$this->nova})) WHERE id = {$employee->id}");

        $this->assertNull($this->reload($employee)->companyIds);
    }

    public function testCorruptedStoredFilterMeansAllCompanies(): void
    {
        foreach ([null, '', 'not json', '{"mode":"all","ids":[1]}', '{"mode":"one"}', '{"mode":"list","ids":[]}', '{"mode":"list","ids":["x"]}'] as $raw) {
            $this->assertNull(Actor::parseCompanyFilter($raw), (string) $raw);
        }
        $this->assertSame([3, 5], Actor::parseCompanyFilter('{"mode":"list","ids":[3,"5",3,0]}'));
    }

    public function testApiEndpointReadsAndWritesTheFilter(): void
    {
        $user = ['id' => $this->operator->id, 'role' => $this->operator->role, 'first_name' => 'X', 'last_name' => 'Y', 'email' => $this->operator->email];
        $kernel = new ApiKernel(static fn (): array => $user, static fn (?string $token): bool => true);
        $controller = new PreferencesController($this->db);

        $response = $kernel->handle(new Request('POST', [], ['action' => 'set_company_filter', 'data' => ['mode' => 'list', 'ids' => [$this->nova, $this->wisla]]]), $controller);
        $this->assertSame(200, $response->status);
        $this->assertSame('list', $response->payload['company_filter']['mode']);

        $read = $kernel->handle(new Request('GET', ['view' => 'company_filter']), $controller);
        $this->assertEqualsCanonicalizing([$this->nova, $this->wisla], $read->payload['company_filter']['ids']);

        $bad = $kernel->handle(new Request('POST', [], ['action' => 'set_company_filter', 'data' => ['mode' => 'one', 'ids' => []]]), $controller);
        $this->assertSame(422, $bad->status);
        $this->assertSame(400, $kernel->handle(new Request('POST', [], ['action' => 'nope']), $controller)->status);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Api\DashboardController;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Rbac;
use App\Service\Actor;
use App\Service\BeneficiaryService;
use App\Service\CertificateService;
use App\Service\PayerService;
use App\Service\ServiceException;
use App\Service\TaskService;
use Tests\Support\IntegrationTestCase;

/**
 * Zakres danych ról stanowisk (Etap 10): kto widzi które certyfikaty, osoby i firmy.
 */
final class RoleScopeTest extends IntegrationTestCase
{
    private int $payerA;
    private int $payerB;
    private int $payerC;
    private int $jan;
    private int $piotr;
    private int $agnieszka;
    /** @var array<string, int> */
    private array $certificate = [];
    private Actor $operator;
    private Actor $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = $this->createActor(Rbac::OPERATOR);
        $this->manager = $this->createActor(Rbac::MANAGER);

        $this->payerA = $this->insertPayer(['company_name' => 'Firma A', 'tax_id' => '6342851974']);
        $this->payerB = $this->insertPayer(['company_name' => 'Firma B', 'tax_id' => '9876543217']);
        $this->payerC = $this->insertPayer(['company_name' => 'Firma C', 'tax_id' => '5551112223']);
        $this->jan = $this->insertBeneficiary($this->payerA, ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com']);
        $this->piotr = $this->insertBeneficiary($this->payerB, ['first_name' => 'Piotr', 'last_name' => 'Lewandowski', 'email' => 'piotr@example.com']);
        $this->agnieszka = $this->insertBeneficiary($this->payerC, ['first_name' => 'Agnieszka', 'last_name' => 'Mazur', 'email' => 'agnieszka@example.com']);

        $this->certificate['signature_a'] = $this->insertCertificate($this->operator->id, $this->payerA, ['name' => 'Podpis Jana', 'beneficiary_id' => $this->jan]);
        $this->certificate['signature_b'] = $this->insertCertificate($this->manager->id, $this->payerB, ['name' => 'Podpis Piotra', 'beneficiary_id' => $this->piotr]);
        $this->certificate['ssl_b'] = $this->insertCertificate($this->manager->id, $this->payerB, ['name' => 'SSL firmy B', 'certificate_type' => 'SSL_CERTIFICATE']);
        $this->certificate['domain_a'] = $this->insertCertificate($this->manager->id, $this->payerA, ['name' => 'Domena firmy A', 'certificate_type' => 'DOMAIN']);
        $this->certificate['seal_c'] = $this->insertCertificate($this->manager->id, $this->payerC, ['name' => 'Pieczęć firmy C', 'certificate_type' => 'QUALIFIED_SEAL', 'beneficiary_id' => $this->agnieszka]);
    }

    /**
     * @return list<string>
     */
    private function names(Actor $actor): array
    {
        $names = array_column((new CertificateService($this->db))->list($actor), 'name');
        sort($names);

        return $names;
    }

    private function createActorWithPerson(string $role, ?int $beneficiaryId): Actor
    {
        $actor = $this->createActor($role);
        if ($beneficiaryId !== null) {
            $this->db->exec("UPDATE users SET beneficiary_id = {$beneficiaryId} WHERE id = {$actor->id}");
        }

        return new Actor($actor->id, $role, $actor->firstName, $actor->lastName, $actor->email, $beneficiaryId);
    }

    public function testOrganisationWideRolesSeeEverything(): void
    {
        $all = ['Domena firmy A', 'Pieczęć firmy C', 'Podpis Jana', 'Podpis Piotra', 'SSL firmy B'];

        foreach ([Rbac::ADMIN, Rbac::DIRECTOR, Rbac::MANAGER, Rbac::ACCOUNTANT] as $role) {
            $this->assertSame($all, $this->names($this->createActor($role)), $role);
        }
    }

    public function testOperatorSeesOnlyOwnCertificatesAndTheirCompanies(): void
    {
        $this->assertSame(['Podpis Jana'], $this->names($this->operator));
        $this->assertSame([$this->payerA], array_column((new PayerService($this->db))->list($this->operator), 'id'));
        $this->assertSame([$this->jan], array_column((new BeneficiaryService($this->db))->list($this->operator), 'id'));
    }

    public function testInformaticianSeesTechnicalCertificatesOnly(): void
    {
        $it = $this->createActor(Rbac::IT);

        $this->assertSame(['Domena firmy A', 'SSL firmy B'], $this->names($it));

        $payers = array_column((new PayerService($this->db))->list($it), 'id');
        sort($payers);
        $this->assertSame([$this->payerA, $this->payerB], $payers);
        // Dane osobowe sygnatariuszy certyfikatów kwalifikowanych zostają poza zakresem informatyka.
        $this->assertSame([], (new BeneficiaryService($this->db))->list($it));

        $this->expectException(ServiceException::class);
        (new CertificateService($this->db))->get($it, $this->certificate['signature_a']);
    }

    public function testInformaticianCannotCreateQualifiedCertificates(): void
    {
        $it = $this->createActor(Rbac::IT);
        $service = new CertificateService($this->db);
        $options = $service->formOptions($it);

        $this->assertNotContains('QUALIFIED_SIGNATURE', $options['types']);
        $this->assertContains('SSL_CERTIFICATE', $options['types']);

        try {
            $service->create($it, [
                'name' => 'Podpis informatyka', 'certificate_type' => 'QUALIFIED_SIGNATURE', 'expiry_date' => '2030-01-01',
                'payer_id' => $this->payerA, 'beneficiary_id' => $this->jan,
            ]);
            $this->fail('Informatyk nie zakłada certyfikatów kwalifikowanych.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('certificate_type', $e->errors);
        }

        $ssl = $service->create($it, ['name' => 'SSL informatyka', 'certificate_type' => 'SSL_CERTIFICATE', 'expiry_date' => '2030-01-01', 'payer_id' => $this->payerA]);
        $this->assertSame($it->id, $ssl['user_id']);
    }

    public function testEmployeeSeesOnlyOwnCertificatesAndTheirCompanies(): void
    {
        // Piotr Lewandowski loguje się jako pracownik: jego jest podpis, a do firmy B należy też SSL (cudzy).
        $employee = $this->createActorWithPerson(Rbac::EMPLOYEE, $this->piotr);

        $this->assertSame(['Podpis Piotra'], $this->names($employee));
        $this->assertSame([$this->payerB], array_column((new PayerService($this->db))->list($employee), 'id'));
        $this->assertSame([$this->piotr], array_column((new BeneficiaryService($this->db))->list($employee), 'id'));

        $certificates = new CertificateService($this->db);
        $this->assertSame('Podpis Piotra', $certificates->get($employee, $this->certificate['signature_b'])['name']);
        foreach (['ssl_b', 'signature_a', 'seal_c'] as $other) {
            try {
                $certificates->get($employee, $this->certificate[$other]);
                $this->fail("Pracownik nie widzi certyfikatu {$other}.");
            } catch (ServiceException $e) {
                $this->assertSame(404, $e->httpStatus());
            }
        }
    }

    public function testEmployeeAlsoSeesCertificatesHeKeeps(): void
    {
        $employee = $this->createActorWithPerson(Rbac::EMPLOYEE, null);
        $this->assertSame([], $this->names($employee));

        $this->db->exec("UPDATE certificates SET user_id = {$employee->id} WHERE id = {$this->certificate['ssl_b']}");

        $this->assertSame(['SSL firmy B'], $this->names($employee));
        $this->assertSame([$this->payerB], array_column((new PayerService($this->db))->list($employee), 'id'));
    }

    public function testEmployeeCannotChangeAnything(): void
    {
        $employee = $this->createActorWithPerson(Rbac::EMPLOYEE, $this->piotr);
        $certificates = new CertificateService($this->db);

        $attempts = [
            fn () => $certificates->create($employee, ['name' => 'X', 'certificate_type' => 'DOMAIN', 'expiry_date' => '2030-01-01', 'payer_id' => $this->payerB]),
            fn () => $certificates->update($employee, $this->certificate['signature_b'], ['name' => 'Zmiana']),
            fn () => $certificates->updatePayment($employee, $this->certificate['signature_b'], ['payment_status' => 'paid']),
            fn () => $certificates->archive($employee, $this->certificate['signature_b']),
            fn () => (new BeneficiaryService($this->db))->update($employee, $this->piotr, ['first_name' => 'P', 'last_name' => 'L', 'payer_id' => $this->payerB]),
            fn () => (new PayerService($this->db))->update($employee, $this->payerB, ['company_name' => 'Zmiana', 'contact_person' => 'X']),
        ];
        foreach ($attempts as $index => $attempt) {
            try {
                $attempt();
                $this->fail("Próba {$index} powinna zostać odrzucona.");
            } catch (ServiceException $e) {
                $this->assertSame(403, $e->httpStatus(), "Próba {$index}");
            }
        }
    }

    public function testDirectorViewsButDoesNotEdit(): void
    {
        $director = $this->createActor(Rbac::DIRECTOR);
        $certificates = new CertificateService($this->db);

        $this->assertSame('Podpis Jana', $certificates->get($director, $this->certificate['signature_a'])['name']);

        $this->expectException(ServiceException::class);
        $certificates->update($director, $this->certificate['signature_a'], ['name' => 'Zmiana']);
    }

    public function testAccountantChangesPaymentsAndDiscountsOnly(): void
    {
        $accountant = $this->createActor(Rbac::ACCOUNTANT);
        $service = new CertificateService($this->db);

        $updated = $service->updatePayment($accountant, $this->certificate['signature_a'], [
            'payment_status' => 'overdue', 'last_payment_date' => '2026-01-15', 'discount_percent' => '-10%',
        ]);

        $this->assertSame('overdue', $updated['payment_status']);
        $this->assertSame(10.0, $updated['discount_percent']);
        $this->assertSame('Podpis Jana', $updated['name']);
        $event = array_values(array_filter($updated['events'], static fn (array $e): bool => $e['event_type'] === 'updated'))[0];
        $this->assertEqualsCanonicalizing(['payment_status', 'last_payment_date', 'discount_percent'], array_keys($event['payload']['changes']));
        $this->assertSame('payment', $event['payload']['scope']);

        try {
            $service->updatePayment($accountant, $this->certificate['signature_a'], ['payment_status' => 'bogus']);
            $this->fail('Nieznany status płatności.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('payment_status', $e->errors);
        }

        try {
            $service->update($accountant, $this->certificate['signature_a'], ['name' => 'Zmiana']);
            $this->fail('Księgowa nie edytuje certyfikatów.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }
    }

    public function testTasksFollowTheRoleScope(): void
    {
        $this->db->exec('UPDATE certificates SET expiry_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)');
        $tasks = new TaskService($this->db);
        $tasks->create($this->manager, ['certificate_id' => $this->certificate['signature_a']]);
        $tasks->create($this->manager, ['certificate_id' => $this->certificate['ssl_b']]);

        $it = $this->createActor(Rbac::IT);
        $employee = $this->createActorWithPerson(Rbac::EMPLOYEE, $this->jan);

        $this->assertCount(2, $tasks->list($this->manager));
        $this->assertSame(['SSL firmy B'], array_column($tasks->list($it), 'certificate_name'));
        $this->assertSame(['Podpis Jana'], array_column($tasks->list($employee), 'certificate_name'));
    }

    public function testDashboardCountsFollowTheRoleScopeAndAreNotSharedBetweenRoles(): void
    {
        $summary = function (Actor $actor): array {
            $user = [
                'id' => $actor->id, 'role' => $actor->role, 'first_name' => 'X', 'last_name' => 'Y',
                'email' => $actor->email, 'beneficiary_id' => $actor->beneficiaryId,
            ];
            $kernel = new ApiKernel(static fn (): array => $user, static fn (?string $token): bool => true);
            $response = $kernel->handle(new Request('GET', [], []), new DashboardController($this->db));
            $this->assertSame(200, $response->status);

            return $response->payload['summary'];
        };

        $employee = $this->createActorWithPerson(Rbac::EMPLOYEE, $this->piotr);

        $this->assertSame(5, array_sum($summary($this->createActor(Rbac::ACCOUNTANT))['stats']));
        $this->assertSame(2, array_sum($summary($this->createActor(Rbac::IT))['stats']));
        $this->assertSame(1, array_sum($summary($this->operator)['stats']));
        $this->assertSame(1, array_sum($summary($employee)['stats']));
        // Drugie wywołanie idzie z cache i nie miesza zakresów.
        $this->assertSame(5, array_sum($summary($this->createActor(Rbac::DIRECTOR))['stats']));
        $this->assertSame(1, array_sum($summary($employee)['stats']));
    }
}

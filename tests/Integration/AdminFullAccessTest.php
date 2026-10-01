<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\AccountService;
use App\Service\BeneficiaryService;
use App\Service\CertificateService;
use App\Service\PayerService;
use App\Service\RenewalScanner;
use App\Service\SettingsService;
use App\Service\TaskService;
use App\Service\TemplateService;
use App\Service\TimelineService;
use App\Service\Visibility;
use Tests\Support\IntegrationTestCase;

/**
 * Administrator widzi wszystko i zarządza wszystkim (Etap 10) — także rekordami, które założył
 * i którymi opiekuje się ktoś inny, oraz kontami, szablonami, ustawieniami i dziennikiem zdarzeń.
 */
final class AdminFullAccessTest extends IntegrationTestCase
{
    public function testAdministratorHasNoDataRestriction(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);

        $this->assertSame(['1 = 1', []], Visibility::certificates($admin));
        $this->assertSame(['1 = 1', []], Visibility::beneficiaries($admin));
        $this->assertSame(['1 = 1', []], Visibility::payers($admin));
    }

    public function testAdministratorManagesRecordsCreatedByOperators(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $operator = $this->createActor(Rbac::OPERATOR);
        $other = $this->createActor(Rbac::MANAGER);

        $payers = new PayerService($this->db);
        $beneficiaries = new BeneficiaryService($this->db);
        $certificates = new CertificateService($this->db);

        $payer = $payers->create($operator, ['company_name' => 'Firma operatora', 'contact_person' => 'Jan', 'tax_id' => '6342851974']);
        $person = $beneficiaries->create($operator, ['first_name' => 'Jan', 'last_name' => 'Operatora', 'payer_id' => $payer['id']]);
        $certificate = $certificates->create($operator, [
            'name' => 'Podpis operatora', 'certificate_type' => 'QUALIFIED_SIGNATURE', 'expiry_date' => '2030-01-01',
            'beneficiary_id' => $person['id'], 'payer_id' => $payer['id'],
        ]);

        // Odczyt, edycja, zmiana opiekuna, płatność.
        $this->assertContains($certificate['id'], array_column($certificates->list($admin), 'id'));
        $updated = $certificates->update($admin, $certificate['id'], [
            'name' => 'Podpis po zmianie', 'certificate_type' => 'QUALIFIED_SIGNATURE', 'expiry_date' => '2031-01-01',
            'beneficiary_id' => $person['id'], 'payer_id' => $payer['id'], 'user_id' => $other->id,
        ]);
        $this->assertSame($other->id, $updated['user_id']);
        $this->assertSame('overdue', $certificates->updatePayment($admin, $certificate['id'], ['payment_status' => 'overdue'])['payment_status']);

        $this->assertSame('Zmieniona', $beneficiaries->update($admin, $person['id'], ['first_name' => 'Jan', 'last_name' => 'Zmieniona', 'payer_id' => $payer['id']])['last_name']);
        $this->assertSame('Firma po zmianie', $payers->update($admin, $payer['id'], ['company_name' => 'Firma po zmianie', 'contact_person' => 'Jan', 'tax_id' => '6342851974'])['company_name']);

        // Archiwizacja i przywracanie w poprawnej kolejności.
        $this->assertNotNull($certificates->archive($admin, $certificate['id'])['archived_at']);
        $this->assertNotNull($beneficiaries->archive($admin, $person['id'])['archived_at']);
        $this->assertNotNull($payers->archive($admin, $payer['id'])['archived_at']);
        $this->assertNull($payers->restore($admin, $payer['id'])['archived_at']);
        $this->assertNull($beneficiaries->restore($admin, $person['id'])['archived_at']);
        $this->assertNull($certificates->restore($admin, $certificate['id'])['archived_at']);

        // Archiwum widzi tylko administrator i wyższe role firmowe.
        $this->assertSame([], $certificates->list($admin, [], true));
    }

    public function testAdministratorRunsTheRenewalProcessAndTheSystemAdministration(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $operator = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer();
        $certificateId = $this->insertCertificate($operator->id, $payerId, [
            'certificate_type' => 'SSL_CERTIFICATE', 'expiry_date' => date('Y-m-d', strtotime('+3 days')),
        ]);

        $scan = (new RenewalScanner($this->db))->run($admin);
        $this->assertSame(1, $scan['created']);

        $tasks = new TaskService($this->db);
        $task = $tasks->list($admin)[0];
        $this->assertSame($certificateId, $task['certificate_id']);
        $this->assertSame($operator->id, $tasks->assign($admin, $task['id'], $operator->id)['assigned_user_id']);
        $this->assertSame('in_progress', $tasks->changeStatus($admin, $task['id'], 'in_progress')['status']);
        $this->assertSame(1, $tasks->stats($admin)['open']);

        // Konta: nowa rola stanowiska, zmiana roli, wyłączenie.
        $accounts = new AccountService($this->db);
        $account = $accounts->create($admin, ['first_name' => 'Ola', 'last_name' => 'Księgowa', 'email' => 'ola.ksiegowa@example.com', 'role' => 'ACCOUNTANT']);
        $this->assertSame('ACCOUNTANT', $account['role']);
        $this->assertSame('IT', $accounts->update($admin, $account['id'], ['first_name' => 'Ola', 'last_name' => 'Księgowa', 'email' => 'ola.ksiegowa@example.com', 'role' => 'IT'])['role']);
        $this->assertNotNull($accounts->deactivate($admin, $account['id'])['deactivated_at']);

        // Szablony, ustawienia i dziennik zdarzeń.
        $this->assertNotSame([], (new TemplateService($this->db))->list($admin));
        $this->assertNotSame([], (new SettingsService($this->db))->describe($admin));
        $this->assertGreaterThan(0, (new TimelineService($this->db))->journal($admin)['total']);
    }

    public function testNoOtherRoleManagesAccounts(): void
    {
        $accounts = new AccountService($this->db);

        foreach (array_diff(Rbac::roles(), [Rbac::ADMIN]) as $role) {
            try {
                $accounts->list($this->createActor($role));
                $this->fail("{$role} nie zarządza kontami.");
            } catch (\App\Service\ServiceException $e) {
                $this->assertSame(403, $e->httpStatus(), $role);
            }
        }
    }
}

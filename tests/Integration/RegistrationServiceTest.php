<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\Actor;
use App\Service\CertificateService;
use App\Service\PayerService;
use App\Service\RegistrationIntakeService;
use App\Service\RegistrationService;
use App\Service\ServiceException;
use Tests\Support\FakeCompanyRegistry;
use Tests\Support\IntegrationTestCase;

/**
 * Sprawdzanie i zatwierdzanie wniosków (Etap 10): jeden przycisk zakłada firmę, użytkownika i certyfikat.
 */
final class RegistrationServiceTest extends IntegrationTestCase
{
    private FakeCompanyRegistry $registry;
    private RegistrationIntakeService $intake;
    private RegistrationService $service;
    private Actor $operator;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new FakeCompanyRegistry();
        $this->registry->add('6342851974', FakeCompanyRegistry::subject('NOVATECH SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '6342851974', 'PRZEMYSŁOWA 12, 40-020 KATOWICE'));
        $this->registry->add('9876543210', FakeCompanyRegistry::subject('GRUPA WISŁA SPÓŁKA AKCYJNA', '9876543210', 'NADRZECZNA 5, 43-460 WISŁA'));
        $this->storage = sys_get_temp_dir() . '/certisub-reg-' . bin2hex(random_bytes(4));
        $this->intake = new RegistrationIntakeService($this->db, $this->registry, $this->storage);
        $this->service = new RegistrationService($this->db, $this->intake);
        $this->operator = $this->createActor(Rbac::OPERATOR);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function draft(string $nip = '6342851974', string $first = 'Jan', string $last = 'Kowalski', string $email = 'jan.kowalski@novatech.example.com', string $extra = ''): int
    {
        static $sequence = 0;
        ++$sequence;
        $raw = "From: {$first} {$last} <{$email}>\r\nSubject: Wniosek {$sequence}\r\nMessage-ID: <reg-{$sequence}@example.com>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
            . "Imię: {$first}\nNazwisko: {$last}\nE-mail: {$email}\nNIP: {$nip}\nRodzaj certyfikatu: certyfikat kwalifikowany\nOkres ważności: 1 rok\nRabat: -10%\n{$extra}";

        return (int) $this->intake->ingest($raw)['draft_id'];
    }

    public function testApprovalCreatesCompanyUserAndCertificateInOneStep(): void
    {
        $id = $this->draft();
        $form = $this->service->get($this->operator, $id)['form'];

        $result = $this->service->approve($this->operator, $id, $form);

        $this->assertSame('approved', $result['status']);
        $this->assertFalse($result['can_review']);
        $ids = $result['result'];
        $this->assertNotNull($ids['payer_id']);
        $this->assertNotNull($ids['beneficiary_id']);
        $this->assertNotNull($ids['certificate_id']);

        $payer = $this->db->query("SELECT * FROM payers WHERE id = {$ids['payer_id']}")->fetch();
        $this->assertSame('Novatech Spółka z Ograniczoną Odpowiedzialnością', $payer['company_name']);
        $this->assertSame('6342851974', $payer['tax_id']);
        $this->assertSame('Przemysłowa 12', $payer['address_line']);
        $this->assertSame('40-020', $payer['postal_code']);
        $this->assertSame('Katowice', $payer['city']);
        $this->assertSame('Jan Kowalski', $payer['contact_person']);
        $this->assertSame($this->operator->id, (int) $payer['created_by_user_id']);

        $person = $this->db->query("SELECT * FROM beneficiaries WHERE id = {$ids['beneficiary_id']}")->fetch();
        $this->assertSame([(string) $ids['payer_id'], 'Jan', 'Kowalski', 'jan.kowalski@novatech.example.com'], [(string) $person['payer_id'], $person['first_name'], $person['last_name'], $person['email']]);

        $certificate = $this->db->query("SELECT * FROM certificates WHERE id = {$ids['certificate_id']}")->fetch();
        $this->assertSame('QUALIFIED_SIGNATURE', $certificate['certificate_type']);
        $this->assertSame((string) $ids['beneficiary_id'], (string) $certificate['beneficiary_id']);
        $this->assertSame((string) $ids['payer_id'], (string) $certificate['payer_id']);
        $this->assertSame((string) $this->operator->id, (string) $certificate['user_id']);
        $this->assertSame('10.00', $certificate['discount_percent']);
        $this->assertSame('pending', $certificate['status']);
        $this->assertSame('Certyfikat kwalifikowany — Jan Kowalski', $certificate['name']);

        // Wszystko od razu widać w zakresie operatora, który zatwierdził wniosek.
        $visible = (new CertificateService($this->db))->list($this->operator);
        $this->assertSame([(int) $ids['certificate_id']], array_column($visible, 'id'));
        $this->assertSame([(int) $ids['payer_id']], array_column((new PayerService($this->db))->list($this->operator), 'id'));

        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM events WHERE event_type = 'registration_approved'")->fetchColumn());
        $this->assertSame($this->operator->id, (int) $this->db->query("SELECT reviewed_by_user_id FROM registration_drafts WHERE id = {$id}")->fetchColumn());
    }

    public function testOperatorEditsAreWhatGetsSaved(): void
    {
        $id = $this->draft();
        $form = $this->service->get($this->operator, $id)['form'];
        $form['person']['last_name'] = 'Kowalski-Nowak';
        $form['certificate']['name'] = 'Podpis Jana (poprawiony)';
        $form['certificate']['serial_number'] = '5A3F9C21B7E04D18';
        $form['certificate']['status'] = 'active';
        $form['company']['contact_person'] = 'Katarzyna Zielińska';

        $saved = $this->service->save($this->operator, $id, $form);
        $this->assertSame('Kowalski-Nowak', $saved['form']['person']['last_name']);
        $this->assertSame($this->operator->id, $saved['assigned_user_id']);

        $result = $this->service->approve($this->operator, $id, $saved['form']);

        $this->assertSame('Kowalski-Nowak', $this->db->query("SELECT last_name FROM beneficiaries WHERE id = {$result['result']['beneficiary_id']}")->fetchColumn());
        $this->assertSame('Katarzyna Zielińska', $this->db->query("SELECT contact_person FROM payers WHERE id = {$result['result']['payer_id']}")->fetchColumn());
        $certificate = $this->db->query("SELECT name, serial_number, status FROM certificates WHERE id = {$result['result']['certificate_id']}")->fetch();
        $this->assertSame(['name' => 'Podpis Jana (poprawiony)', 'serial_number' => '5A3F9C21B7E04D18', 'status' => 'active'], $certificate);
    }

    public function testExistingCompanyIsReusedEvenWhenTheOperatorCouldNotSeeItBefore(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'tax_id' => '6342851974']);
        $this->assertSame([], (new PayerService($this->db))->list($this->operator), 'przed zatwierdzeniem firma jest poza zakresem operatora');

        $id = $this->draft();
        $view = $this->service->get($this->operator, $id);
        $this->assertSame(['id' => $payerId, 'visible' => false, 'archived' => false], $view['matched_payer']);
        $this->assertArrayNotHasKey('company_name', $view['matched_payer']);

        $result = $this->service->approve($this->operator, $id, $view['form']);

        $this->assertSame($payerId, $result['result']['payer_id']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM payers')->fetchColumn());
        $this->assertSame([$payerId], array_column((new PayerService($this->db))->list($this->operator), 'id'));
    }

    public function testOperatorCannotAttachToACompanyThatWasNotMatchedFromTheMessage(): void
    {
        $this->insertPayer(['company_name' => 'Firma z wniosku', 'tax_id' => '6342851974']);
        $foreign = $this->insertPayer(['company_name' => 'Cudza firma', 'tax_id' => '5551112223']);
        $id = $this->draft();
        $form = $this->service->get($this->operator, $id)['form'];
        $form['company']['payer_id'] = $foreign;

        try {
            $this->service->approve($this->operator, $id, $form);
            $this->fail('Podmieniony identyfikator firmy powinien zostać odrzucony.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('company.payer_id', $e->errors);
        }

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM certificates')->fetchColumn());
        $this->assertSame('pending', $this->db->query("SELECT status FROM registration_drafts WHERE id = {$id}")->fetchColumn());
    }

    public function testExistingPersonMustBelongToTheChosenCompany(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'NovaTech', 'tax_id' => '6342851974']);
        $personId = $this->insertBeneficiary($payerId, ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan.kowalski@novatech.example.com']);
        $otherPerson = $this->insertBeneficiary($this->insertPayer(['tax_id' => '5551112223']), ['email' => 'inny@example.com']);
        $id = $this->draft();
        $form = $this->service->get($this->operator, $id)['form'];
        $this->assertSame(['existing', $personId], [$form['person']['mode'], $form['person']['beneficiary_id']]);

        $form['person']['beneficiary_id'] = $otherPerson;
        try {
            $this->service->approve($this->operator, $id, $form);
            $this->fail('Osoba z innej firmy.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('person.beneficiary_id', $e->errors);
        }

        $form['person']['beneficiary_id'] = $personId;
        $result = $this->service->approve($this->operator, $id, $form);
        $this->assertSame($personId, $result['result']['beneficiary_id']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM beneficiaries WHERE email = "jan.kowalski@novatech.example.com"')->fetchColumn());
    }

    public function testValidationErrorsComeBackPerSectionAndNothingIsSaved(): void
    {
        $id = $this->draft();
        $form = $this->service->get($this->operator, $id)['form'];
        $form['person']['last_name'] = '';
        $form['certificate']['expiry_date'] = '2020-01-01';

        try {
            $this->service->approve($this->operator, $id, $form);
            $this->fail('Błędny formularz.');
        } catch (ServiceException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertArrayHasKey('person.last_name', $e->errors);
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM payers')->fetchColumn(), 'firma założona w trakcie nieudanego zatwierdzenia jest wycofana');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM beneficiaries')->fetchColumn());

        $form['person']['last_name'] = 'Kowalski';
        $form['certificate']['valid_from'] = '2027-01-01';
        try {
            $this->service->approve($this->operator, $id, $form);
            $this->fail('Data „od” po dacie „do”.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('certificate.valid_from', $e->errors);
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM payers')->fetchColumn());

        $form['company']['tax_id'] = '1234567890';
        $form['certificate']['valid_from'] = '2026-10-01';
        $form['certificate']['expiry_date'] = '2027-10-01';
        try {
            $this->service->approve($this->operator, $id, $form);
            $this->fail('Niepoprawny NIP.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('company.tax_id', $e->errors);
        }
    }

    public function testArchivedCompanyBlocksApproval(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'Archiwalna', 'tax_id' => '6342851974']);
        $this->db->exec("UPDATE payers SET archived_at = NOW() WHERE id = {$payerId}");
        $id = $this->draft();

        try {
            $this->service->approve($this->operator, $id, $this->service->get($this->operator, $id)['form']);
            $this->fail('Firma w archiwum.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM certificates')->fetchColumn());
    }

    public function testApprovedOrRejectedDraftCannotBeChangedAgain(): void
    {
        $approved = $this->draft();
        $this->service->approve($this->operator, $approved, $this->service->get($this->operator, $approved)['form']);
        $rejected = $this->draft('9876543210', 'Piotr', 'Lewandowski', 'piotr@grupawisla.example.com');
        $this->service->reject($this->operator, $rejected, 'To nie jest wniosek');

        foreach ([$approved, $rejected] as $id) {
            foreach ([
                fn () => $this->service->approve($this->operator, $id, []),
                fn () => $this->service->reject($this->operator, $id),
                fn () => $this->service->save($this->operator, $id, []),
                fn () => $this->service->claim($this->operator, $id),
                fn () => $this->service->refreshCompany($this->operator, $id, '6342851974'),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Zamknięty wniosek nie przyjmuje zmian.');
                } catch (ServiceException $e) {
                    $this->assertSame(409, $e->httpStatus());
                }
            }
        }

        $closed = $this->service->get($this->operator, $rejected);
        $this->assertSame('rejected', $closed['status']);
        $this->assertSame('To nie jest wniosek', $closed['rejection_reason']);
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM certificates WHERE name LIKE '%Lewandowski%'")->fetchColumn());
    }

    public function testListFiltersByStatusAndCountsPending(): void
    {
        $one = $this->draft();
        $two = $this->draft('9876543210', 'Piotr', 'Lewandowski', 'piotr@grupawisla.example.com');
        $this->service->reject($this->operator, $two);

        $this->assertSame(1, $this->service->pendingCount($this->operator));
        $pending = $this->service->list($this->operator);
        $this->assertSame([$one], array_column($pending, 'id'));
        $this->assertSame('Jan Kowalski', $pending[0]['person_name']);
        $this->assertSame('Novatech Spółka z Ograniczoną Odpowiedzialnością', $pending[0]['company_name']);
        $this->assertSame([$two], array_column($this->service->list($this->operator, ['status' => 'rejected']), 'id'));
        $this->assertCount(2, $this->service->list($this->operator, ['status' => 'all']));
    }

    public function testRefreshCompanyReloadsTheRegistryAndKeepsOperatorContactData(): void
    {
        $id = $this->draft('1234567890');
        $draft = $this->service->get($this->operator, $id);
        $this->assertContains('nip_invalid', array_column($draft['warnings'], 'code'));
        $this->assertSame('new', $draft['form']['company']['mode']);

        $form = $draft['form'];
        $form['company']['email'] = 'faktury@novatech.example.com';
        $form['company']['contact_person'] = 'Katarzyna Zielińska';
        $this->service->save($this->operator, $id, $form);

        try {
            $this->service->refreshCompany($this->operator, $id, '1234567890');
            $this->fail('Niepoprawny NIP nie jest sprawdzany w rejestrze.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('company.tax_id', $e->errors);
        }

        $refreshed = $this->service->refreshCompany($this->operator, $id, '634-285-19-74');

        $company = $refreshed['form']['company'];
        $this->assertSame('6342851974', $company['tax_id']);
        $this->assertSame('Novatech Spółka z Ograniczoną Odpowiedzialnością', $company['company_name']);
        $this->assertSame('Katowice', $company['city']);
        $this->assertSame('faktury@novatech.example.com', $company['email']);
        $this->assertSame('Katarzyna Zielińska', $company['contact_person']);
        $this->assertSame('found', $refreshed['company_lookup']['status']);
        $codes = array_column($refreshed['warnings'], 'code');
        $this->assertNotContains('nip_invalid', $codes);
        $this->assertNotContains('nip_missing', $codes);
        $this->assertSame($this->operator->id, $refreshed['assigned_user_id']);

        $result = $this->service->approve($this->operator, $id, $refreshed['form']);
        $this->assertSame('faktury@novatech.example.com', $this->db->query("SELECT email FROM payers WHERE id = {$result['result']['payer_id']}")->fetchColumn());
    }

    public function testRefreshingToACompanyThatAlreadyExistsSwitchesToIt(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'Grupa Wisła S.A.', 'tax_id' => '9876543210']);
        $id = $this->draft('6342851974');

        $refreshed = $this->service->refreshCompany($this->operator, $id, '9876543210');

        $this->assertSame(['existing', $payerId], [$refreshed['form']['company']['mode'], $refreshed['form']['company']['payer_id']]);
        $this->assertContains('company_exists', array_column($refreshed['warnings'], 'code'));
    }

    public function testClaimingAndTakingOver(): void
    {
        $id = $this->draft();
        $other = $this->createActor(Rbac::OPERATOR);
        $manager = $this->createActor(Rbac::MANAGER);

        $this->assertSame($this->operator->id, $this->service->claim($this->operator, $id)['assigned_user_id']);
        try {
            $this->service->claim($other, $id);
            $this->fail('Drugi operator nie przejmie cudzego wniosku.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
        }
        $this->assertSame($manager->id, $this->service->claim($manager, $id)['assigned_user_id']);
    }

    public function testRolePermissions(): void
    {
        $id = $this->draft();

        foreach ([Rbac::EMPLOYEE, Rbac::ACCOUNTANT, Rbac::IT] as $role) {
            try {
                $this->service->get($this->createActor($role), $id);
                $this->fail("{$role} nie widzi wniosków.");
            } catch (ServiceException $e) {
                $this->assertSame(403, $e->httpStatus(), $role);
            }
        }

        // Szef widzi kolejkę, ale nie zatwierdza.
        $director = $this->createActor(Rbac::DIRECTOR);
        $this->assertFalse($this->service->get($director, $id)['can_review']);
        $this->expectException(ServiceException::class);
        $this->service->approve($director, $id, []);
    }

    public function testApprovedCertificateOfTheSameSerialIsRejectedAsDuplicate(): void
    {
        $first = $this->draft();
        $form = $this->service->get($this->operator, $first)['form'];
        $form['certificate']['serial_number'] = 'QS-1';
        $form['certificate']['issuer'] = 'Certum';
        $this->service->approve($this->operator, $first, $form);

        $second = $this->draft('9876543210', 'Piotr', 'Lewandowski', 'piotr@grupawisla.example.com');
        $form = $this->service->get($this->operator, $second)['form'];
        $form['certificate']['serial_number'] = 'QS-1';
        $form['certificate']['issuer'] = 'Certum';

        try {
            $this->service->approve($this->operator, $second, $form);
            $this->fail('Numer seryjny jest unikalny u wystawcy.');
        } catch (ServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
            $this->assertArrayHasKey('certificate.serial_number', $e->errors);
        }
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM payers WHERE tax_id = '6342851974'")->fetchColumn());
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM payers WHERE tax_id = '9876543210'")->fetchColumn(), 'firma z nieudanego zatwierdzenia nie zostaje');
    }
}

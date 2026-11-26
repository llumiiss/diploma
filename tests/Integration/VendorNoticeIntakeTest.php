<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\Actor;
use App\Service\ReadingRuleService;
use App\Service\RegistrationIntakeService;
use App\Service\RegistrationService;
use Tests\Support\FakeCompanyRegistry;
use Tests\Support\IntegrationTestCase;
use Tests\Support\VendorNoticeFixtures;

/**
 * Powiadomienia dostawcy certyfikatów (Etap 11): firma nie wynika z wiadomości, więc operator wskazuje ją
 * z ewidencji — albo dobiera ją numer certyfikatu lub adres użytkownika.
 */
final class VendorNoticeIntakeTest extends IntegrationTestCase
{
    private RegistrationIntakeService $intake;
    private string $storage = '';
    private Actor $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/certisub-notice-' . bin2hex(random_bytes(4));
        $this->intake = new RegistrationIntakeService($this->db, new FakeCompanyRegistry(), $this->storage);
        $this->operator = $this->createActor(Rbac::OPERATOR);
    }

    protected function tearDown(): void
    {
        // Bez flagi RUN_INTEGRATION_TESTS test jest pominięty w setUp, zanim powstał katalog.
        if ($this->storage !== '') {
            foreach (glob($this->storage . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->storage);
        }
        parent::tearDown();
    }

    public function testNoticeFillsPersonAndCertificateAndOperatorPicksTheCompany(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'Firma Klienta'], $this->operator->id);
        $service = new RegistrationService($this->db, $this->intake);
        $id = (int) $this->intake->ingest(VendorNoticeFixtures::issued(), 'imap')['draft_id'];

        $draft = $service->get($this->operator, $id);
        $form = $draft['form'];
        $this->assertSame('Katarzyna', $form['person']['first_name']);
        $this->assertSame('Przykładowa', $form['person']['last_name']);
        $this->assertSame('k.przykladowa@example.com', $form['person']['email']);
        $this->assertSame('', $form['company']['tax_id']);
        $this->assertSame('', $form['company']['company_name']);
        $this->assertSame('2029-03-09', $form['certificate']['expiry_date']);
        $this->assertSame('multi_year', $form['certificate']['billing_cycle']);
        $this->assertNull($draft['company_lookup'], 'bez NIP-u klienta rejestr nie jest odpytywany');
        $blocking = array_filter($draft['warnings'], static fn (array $w): bool => $w['level'] === 'error');
        $this->assertSame(['company_not_in_message'], array_values(array_column($blocking, 'code')));

        $form['company']['mode'] = 'existing';
        $form['company']['payer_id'] = (string) $payerId;
        $approved = $service->approve($this->operator, $id, $form);

        $this->assertSame('approved', $approved['status']);
        $certificate = $this->db->query("SELECT payer_id, expiry_date FROM certificates WHERE id = {$approved['result']['certificate_id']}")->fetch();
        $this->assertSame($payerId, (int) $certificate['payer_id']);
        $this->assertSame('2029-03-09', $certificate['expiry_date']);
    }

    public function testKnownCertificateNumberPointsToTheCompanyAndWarnsAboutTheDuplicate(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'Znana Firma']);
        $personId = $this->insertBeneficiary($payerId, ['first_name' => 'Tomasz', 'last_name' => 'Testowy', 'email' => 'inny.adres@example.com']);
        $this->insertCertificate($this->operator->id, $payerId, [
            'beneficiary_id' => $personId,
            'serial_number'  => '0a1b2c3d4e5f60718293a4b5c6d7e8f9',
            'issuer'         => 'Certum',
        ]);

        $id = (int) $this->intake->ingest(VendorNoticeFixtures::expiring(), 'imap')['draft_id'];
        $draft = (new RegistrationService($this->db, $this->intake))->get($this->operator, $id);

        $this->assertSame('existing', $draft['form']['company']['mode']);
        $this->assertSame($payerId, $draft['form']['company']['payer_id']);
        $this->assertSame($personId, $draft['form']['person']['beneficiary_id']);
        $codes = array_column($draft['warnings'], 'code');
        $this->assertContains('certificate_exists', $codes);
        $this->assertContains('matched_by_person', $codes);
        $this->assertContains('renewal_notice', $codes);
        $this->assertNotContains('company_not_in_message', $codes);
    }

    public function testKnownEmailOfOnePersonPointsToTheCompany(): void
    {
        $payerId = $this->insertPayer();
        $personId = $this->insertBeneficiary($payerId, ['first_name' => 'Katarzyna', 'last_name' => 'Przykładowa', 'email' => 'K.Przykladowa@example.com']);

        $id = (int) $this->intake->ingest(VendorNoticeFixtures::issued(), 'webhook')['draft_id'];
        $draft = (new RegistrationService($this->db, $this->intake))->get($this->operator, $id);

        $this->assertSame($payerId, $draft['form']['company']['payer_id']);
        $this->assertSame($personId, $draft['form']['person']['beneficiary_id']);
    }

    public function testReadingRulesApplyToIncomingMessages(): void
    {
        (new ReadingRuleService($this->db))->create($this->createActor(Rbac::ADMIN), [
            'name' => 'Partner', 'field' => 'person.full_name', 'label' => 'Abonent końcowy', 'sender_match' => 'partner.example.com',
        ]);
        $raw = "From: Biuro <biuro@partner.example.com>\r\nSubject: Wniosek o certyfikat\r\nMessage-ID: <rule-1@example.com>\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n\r\nAbonent końcowy: Ola Wymyślona\r\nNIP: 6342851974\r\n";

        $id = (int) $this->intake->ingest($raw)['draft_id'];
        $form = (new RegistrationService($this->db, $this->intake))->get($this->operator, $id)['form'];

        $this->assertSame('Ola', $form['person']['first_name']);
        $this->assertSame('Wymyślona', $form['person']['last_name']);
    }
}

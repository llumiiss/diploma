<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Intake\ImapClient;
use App\Rbac;
use App\Service\Actor;
use App\Service\NotificationService;
use App\Service\RegistrationIntakeService;
use App\Service\ServiceException;
use Tests\Support\FakeCompanyRegistry;
use Tests\Support\IntegrationTestCase;
use Tests\Support\ScriptedImapServer;

/**
 * Przyjmowanie wniosków z poczty (Etap 10): wiadomość → odczyt → rejestr firm → powiązanie z ewidencją → formularz.
 */
final class RegistrationIntakeTest extends IntegrationTestCase
{
    private FakeCompanyRegistry $registry;
    private RegistrationIntakeService $intake;
    private string $storage;
    private Actor $admin;
    private Actor $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new FakeCompanyRegistry();
        $this->registry->add('6342851974', FakeCompanyRegistry::subject('NOVATECH SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '6342851974', 'PRZEMYSŁOWA 12, 40-020 KATOWICE'));
        $this->storage = sys_get_temp_dir() . '/certisub-intake-' . bin2hex(random_bytes(4));
        $this->intake = new RegistrationIntakeService($this->db, $this->registry, $this->storage);
        $this->admin = $this->createActor(Rbac::ADMIN);
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

    /**
     * @param array<string, string> $overrides
     */
    private function eml(array $overrides = []): string
    {
        static $sequence = 0;
        ++$sequence;
        $headers = array_merge([
            'From'       => 'Jan Kowalski <jan.kowalski@novatech.example.com>',
            'To'         => 'rejestracja@certisub.example.com',
            'Subject'    => 'Wniosek o certyfikat kwalifikowany',
            'Message-ID' => "<wniosek-{$sequence}@novatech.example.com>",
            'Date'       => 'Fri, 02 Oct 2026 08:15:00 +0200',
        ], $overrides);
        $body = $overrides['body'] ?? "Imię: Jan\nNazwisko: Kowalski\nE-mail: jan.kowalski@novatech.example.com\nTelefon: +48 600 100 200\nNIP: 634-285-19-74\nRodzaj certyfikatu: Certyfikat kwalifikowany\nOkres ważności: 2 lata\nRabat: -5%\n";
        unset($headers['body']);

        $raw = '';
        foreach ($headers as $name => $value) {
            $raw .= "{$name}: {$value}\r\n";
        }

        return $raw . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(int $id): array
    {
        $row = $this->db->query("SELECT * FROM registration_drafts WHERE id = {$id}")->fetch();
        $this->assertIsArray($row);
        foreach (['extracted', 'form', 'company_lookup', 'warnings'] as $column) {
            $row[$column] = $row[$column] !== null ? json_decode((string) $row[$column], true) : null;
        }

        return $row;
    }

    public function testNewCompanyIsBuiltFromTheRegistryAndTheMessage(): void
    {
        $result = $this->intake->ingest($this->eml(), 'upload', $this->operator);

        $this->assertSame('created', $result['status']);
        $draft = $this->draft((int) $result['draft_id']);
        $this->assertSame('pending', $draft['status']);
        $this->assertSame('upload', $draft['source']);
        $this->assertSame($this->operator->id, (int) $draft['created_by_user_id']);
        $this->assertSame('found', $draft['company_lookup']['status']);
        $this->assertSame(['6342851974'], $this->registry->queries);

        $company = $draft['form']['company'];
        $this->assertSame('new', $company['mode']);
        $this->assertSame('Novatech Spółka z Ograniczoną Odpowiedzialnością', $company['company_name']);
        $this->assertSame('6342851974', $company['tax_id']);
        $this->assertSame('Przemysłowa 12', $company['address_line']);
        $this->assertSame('40-020', $company['postal_code']);
        $this->assertSame('Katowice', $company['city']);
        $this->assertSame('Jan Kowalski', $company['contact_person']);

        $person = $draft['form']['person'];
        $this->assertSame(['new', 'Jan', 'Kowalski', 'jan.kowalski@novatech.example.com', '+48 600 100 200'], [$person['mode'], $person['first_name'], $person['last_name'], $person['email'], $person['phone']]);

        $certificate = $draft['form']['certificate'];
        $this->assertSame('QUALIFIED_SIGNATURE', $certificate['certificate_type']);
        $this->assertSame('Certyfikat kwalifikowany — Jan Kowalski', $certificate['name']);
        $this->assertSame('-5', $certificate['discount_percent']);
        $this->assertSame('multi_year', $certificate['billing_cycle']);
        $this->assertSame('pending', $certificate['status']);
        $this->assertSame(date('Y-m-d', strtotime($certificate['valid_from'] . ' +24 months')), $certificate['expiry_date']);

        $this->assertSame([], array_filter($draft['warnings'], static fn (array $w): bool => in_array($w['level'], ['error', 'warning'], true)));
        $this->assertNull($draft['matched_payer_id']);
        $this->assertSame('2026-10-02', substr((string) $draft['received_at'], 0, 10));
    }

    public function testExistingCompanyAndPersonAreMatchedByNipAndEmail(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'tax_id' => '6342851974']);
        $personId = $this->insertBeneficiary($payerId, ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan.kowalski@novatech.example.com']);

        $draft = $this->draft((int) $this->intake->ingest($this->eml())['draft_id']);

        $this->assertSame($payerId, (int) $draft['matched_payer_id']);
        $this->assertSame($personId, (int) $draft['matched_beneficiary_id']);
        $this->assertSame(['existing', $payerId], [$draft['form']['company']['mode'], $draft['form']['company']['payer_id']]);
        $this->assertSame('NovaTech Sp. z o.o.', $draft['form']['company']['company_name']);
        $this->assertSame(['existing', $personId], [$draft['form']['person']['mode'], $draft['form']['person']['beneficiary_id']]);
        $codes = array_column($draft['warnings'], 'code');
        $this->assertContains('company_exists', $codes);
        $this->assertContains('person_exists', $codes);
        $this->assertNotContains('company_name_differs', $codes);
    }

    public function testPersonWithTheSameEmailInAnotherCompanyIsNotSilentlyReused(): void
    {
        $other = $this->insertPayer(['company_name' => 'Inna firma', 'tax_id' => '9876543210']);
        $this->insertBeneficiary($other, ['email' => 'jan.kowalski@novatech.example.com']);

        $draft = $this->draft((int) $this->intake->ingest($this->eml())['draft_id']);

        $this->assertNull($draft['matched_beneficiary_id']);
        $this->assertSame('new', $draft['form']['person']['mode']);
        $this->assertContains('person_other_company', array_column($draft['warnings'], 'code'));
    }

    public function testArchivedCompanyBlocksTheDraftWithAnErrorWarning(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'Stara firma', 'tax_id' => '6342851974']);
        $this->db->exec("UPDATE payers SET archived_at = NOW() WHERE id = {$payerId}");

        $draft = $this->draft((int) $this->intake->ingest($this->eml())['draft_id']);

        $archived = array_values(array_filter($draft['warnings'], static fn (array $w): bool => $w['code'] === 'company_archived'));
        $this->assertCount(1, $archived);
        $this->assertSame('error', $archived[0]['level']);
        $this->assertContains('company_name_differs', array_column($draft['warnings'], 'code'));
    }

    public function testRegistryProblemsDoNotBlockTheDraft(): void
    {
        $this->registry->fail('6342851974', 'Rejestr jest niedostępny (HTTP 503).');
        $draft = $this->draft((int) $this->intake->ingest($this->eml())['draft_id']);

        $this->assertSame('error', $draft['company_lookup']['status']);
        $this->assertContains('registry_error', array_column($draft['warnings'], 'code'));
        // Dane firmy zostają z wiadomości — operator może je uzupełnić ręcznie.
        $this->assertSame('6342851974', $draft['form']['company']['tax_id']);
        $this->assertSame('new', $draft['form']['company']['mode']);

        $unknown = $this->draft((int) $this->intake->ingest($this->eml(['body' => "NIP: 9876543210\nFirma: Nieznana SA\nImię: Ewa\nNazwisko: Pawlak"]))['draft_id']);
        $this->assertSame('not_found', $unknown['company_lookup']['status']);
        $this->assertSame('Nieznana SA', $unknown['form']['company']['company_name']);
        $this->assertContains('registry_not_found', array_column($unknown['warnings'], 'code'));
    }

    public function testInactiveVatStatusIsFlagged(): void
    {
        $this->registry->add('9876543210', FakeCompanyRegistry::subject('WISŁA SPÓŁKA AKCYJNA', '9876543210', 'NADRZECZNA 5, 43-460 WISŁA', 'Niezarejestrowany'));

        $draft = $this->draft((int) $this->intake->ingest($this->eml(['body' => "NIP: 9876543210\nImię: Piotr\nNazwisko: Lewandowski"]))['draft_id']);

        $vat = array_values(array_filter($draft['warnings'], static fn (array $w): bool => $w['code'] === 'vat_inactive'));
        $this->assertCount(1, $vat);
        $this->assertSame('Niezarejestrowany', $vat[0]['params']['status']);
    }

    public function testMessageWithoutNipProducesBlockingWarning(): void
    {
        $draft = $this->draft((int) $this->intake->ingest($this->eml(['body' => "Imię: Ewa\nNazwisko: Pawlak\nProszę o certyfikat."]), 'upload')['draft_id']);

        $this->assertSame([], $this->registry->queries);
        $warning = array_values(array_filter($draft['warnings'], static fn (array $w): bool => $w['code'] === 'nip_missing'));
        $this->assertSame('error', $warning[0]['level']);
        $this->assertNull($draft['company_lookup']);
    }

    public function testTheSameMessageIsNeverImportedTwice(): void
    {
        $raw = $this->eml();

        $first = $this->intake->ingest($raw);
        $again = $this->intake->ingest($raw);
        $this->assertSame('duplicate', $again['status']);
        $this->assertSame($first['draft_id'], $again['draft_id']);

        // Ta sama wiadomość z innym Message-ID i inną treścią techniczną to nadal inny skrót — ale Message-ID rozstrzyga.
        $redelivered = $this->intake->ingest(str_replace('Subject: Wniosek', 'X-Received: kolejny serwer' . "\r\n" . 'Subject: Wniosek', $raw));
        $this->assertSame('duplicate', $redelivered['status']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM registration_drafts')->fetchColumn());
    }

    public function testAutomaticChannelsSkipMessagesThatAreNotRegistrations(): void
    {
        $junk = $this->eml(['Subject' => 'Spotkanie w piątek', 'From' => 'Ktoś <ktos@example.com>', 'body' => 'Cześć, w piątek spotkanie o 10:00.']);

        foreach (['imap', 'webhook', 'cli'] as $source) {
            $result = $this->intake->ingest($junk, $source);
            $this->assertSame(['status' => 'skipped', 'draft_id' => null, 'reason' => 'not_registration'], $result, $source);
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM registration_drafts')->fetchColumn());

        // Ręczne wczytanie pliku tworzy wniosek zawsze — operator wie, co robi.
        $this->assertSame('created', $this->intake->ingest($junk, 'upload', $this->operator)['status']);
    }

    public function testOnlyRolesWithIntakePermissionUpload(): void
    {
        foreach ([Rbac::EMPLOYEE, Rbac::ACCOUNTANT, Rbac::DIRECTOR, Rbac::IT] as $role) {
            try {
                $this->intake->ingest($this->eml(), 'upload', $this->createActor($role));
                $this->fail("{$role} nie wczytuje wniosków.");
            } catch (ServiceException $e) {
                $this->assertSame(403, $e->httpStatus(), $role);
            }
        }

        $this->assertSame('created', $this->intake->ingest($this->eml(), 'upload', $this->operator)['status']);
    }

    public function testUnreadableMessageIsRejected(): void
    {
        $this->expectException(ServiceException::class);
        $this->intake->ingest('to nie jest wiadomość', 'upload', $this->operator);
    }

    public function testOriginalMessageIsStoredOutsideTheDatabaseRowAndPeopleAreNotified(): void
    {
        $employee = $this->createActor(Rbac::EMPLOYEE);
        $draftId = (int) $this->intake->ingest($this->eml())['draft_id'];

        $draft = $this->draft($draftId);
        $this->assertNotNull($draft['raw_file']);
        $this->assertFileExists($this->storage . '/' . $draft['raw_file']);
        $this->assertStringContainsString('NIP: 634-285-19-74', (string) file_get_contents($this->storage . '/' . $draft['raw_file']));

        $notifications = new NotificationService($this->db);
        foreach ([$this->admin, $this->operator] as $reviewer) {
            $inbox = $notifications->inbox($reviewer);
            $this->assertCount(1, $inbox);
            $this->assertSame('system', $inbox[0]['type']);
            $this->assertSame('registration', $inbox[0]['related_type']);
            $this->assertSame($draftId, $inbox[0]['related_id']);
            $this->assertStringContainsString('Jan Kowalski', $inbox[0]['subject']);
        }
        $this->assertSame([], $notifications->inbox($employee));

        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM events WHERE event_type = 'registration_received'")->fetchColumn());
    }

    public function testMailboxRunCreatesDraftsMarksMessagesAndSurvivesBadOnes(): void
    {
        $server = new ScriptedImapServer();
        $server->addMessage(1, $this->eml());
        $server->addMessage(2, $this->eml(['Subject' => 'Spotkanie', 'From' => 'Ktoś <ktos@example.com>', 'body' => 'Spotkanie o 10.']));
        $server->addMessage(3, 'to nie jest wiadomość e-mail');
        $server->addMessage(4, str_repeat('x', 10_000_001));
        $server->addMessage(5, $this->eml(), true);

        $client = new ImapClient($server);
        $client->greeting();
        $client->loginPassword('u', 'sekret');

        $summary = $this->intake->fetchMailbox($client, 'INBOX', 20);

        $this->assertSame(['fetched' => 4, 'created' => 1, 'duplicates' => 0, 'skipped' => 2, 'failed' => 1], array_diff_key($summary, ['errors' => true]));
        $this->assertCount(2, $summary['errors']);
        // Każda wiadomość po obróbce jest przeczytana, więc kolejny przebieg ich nie powtórzy.
        $this->assertSame([], array_filter($server->messages, static fn (array $m): bool => !$m['seen']));
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM registration_drafts')->fetchColumn());

        $second = $this->intake->fetchMailbox($client, 'INBOX', 20);
        $this->assertSame(0, $second['fetched']);
    }

    public function testMailboxRunCanMoveProcessedMessagesAndHonoursTheLimit(): void
    {
        $server = new ScriptedImapServer();
        $server->addMessage(1, $this->eml());
        $server->addMessage(2, $this->eml(['body' => "NIP: 6342851974\nImię: Ewa\nNazwisko: Pawlak"]));
        $client = new ImapClient($server);
        $client->greeting();
        $client->loginPassword('u', 'sekret');

        $summary = $this->intake->fetchMailbox($client, 'INBOX', 1, 'Przetworzone');

        $this->assertSame(1, $summary['fetched']);
        $this->assertCount(1, $server->copied['Przetworzone']);
        $this->assertArrayNotHasKey(1, $server->messages);
        $this->assertArrayHasKey(2, $server->messages);
    }

    public function testConnectionFailureMidRunLeavesTheRestUnread(): void
    {
        $server = new ScriptedImapServer('sekret', true, 5);
        $server->addMessage(1, $this->eml());
        $server->addMessage(2, $this->eml(['body' => "NIP: 6342851974\nImię: Ewa\nNazwisko: Pawlak"]));
        $client = new ImapClient($server);
        $client->greeting();
        $client->loginPassword('u', 'sekret');

        try {
            $this->intake->fetchMailbox($client, 'INBOX', 10);
            $this->fail('Zerwane połączenie powinno przerwać przebieg.');
        } catch (\App\Intake\ImapException) {
            $this->assertFalse($server->messages[2]['seen']);
        }
    }
}

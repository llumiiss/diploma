<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exchange\CsvWriter;
use App\Exchange\XmlExporter;
use App\Rbac;
use App\Service\Actor;
use App\Service\ImportService;
use App\Service\ServiceException;
use Tests\Support\IntegrationTestCase;

final class ImportServiceTest extends IntegrationTestCase
{
    private Actor $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createActor(Rbac::ADMIN, 'admin.import@example.com');
    }

    private function service(): ImportService
    {
        return new ImportService($this->db);
    }

    private function tableCount(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /**
     * @param list<list<string|null>> $rows
     */
    private static function csv(array $headers, array $rows): string
    {
        return CsvWriter::write($headers, $rows);
    }

    public function testPayerPreviewValidatesRowsWithoutWritingAndCommitImportsValidOnes(): void
    {
        $content = self::csv(['Nazwa firmy', 'NIP', 'Osoba kontaktowa', 'Miasto'], [
            ['NovaTech Sp. z o.o.', '634-285-19-74', 'Katarzyna Zielińska', 'Katowice'],
            ['Zły NIP S.A.', '1234567890', 'Jan Nowak', 'Gliwice'],
            ['Bez kontaktu', null, null, 'Zabrze'],
            ['NovaTech Sp. z o.o. (duplikat)', 'PL6342851974', 'Ktoś', null],
        ]);

        $preview = $this->service()->preview($this->admin, 'payers', 'platnicy.csv', $content);

        $this->assertFalse($preview['committed']);
        $this->assertSame(['rows' => 4, 'create' => 1, 'update' => 0, 'unchanged' => 0, 'skip' => 0, 'error' => 3], $preview['totals']);
        $this->assertSame(['create', 'error', 'error', 'error'], array_column($preview['rows'], 'status'));
        $this->assertSame([2, 3, 4, 5], array_column($preview['rows'], 'line'));
        $this->assertNull($preview['rows'][0]['record_id']);
        $this->assertSame('tax_id', $preview['rows'][1]['errors'][0]['field']);
        $this->assertSame('NIP', $preview['rows'][1]['errors'][0]['label']);
        $this->assertSame('contact_person', $preview['rows'][2]['errors'][0]['field']);
        $this->assertStringContainsString('2', $preview['rows'][3]['errors'][0]['message']);
        $this->assertSame(0, $this->tableCount('payers'));
        $this->assertSame(0, $this->tableCount('events'));

        $result = $this->service()->commit($this->admin, 'payers', 'platnicy.csv', $content);

        $this->assertTrue($result['committed']);
        $this->assertSame(1, $result['totals']['create']);
        $this->assertSame(1, $this->tableCount('payers'));
        $payerId = (int) $result['rows'][0]['record_id'];
        $this->assertSame(1, $this->countEvents('payer', $payerId, 'created'));
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM events WHERE event_type = 'data_imported'")->fetchColumn());
        $this->assertSame('6342851974', $this->db->query("SELECT tax_id FROM payers WHERE id = {$payerId}")->fetchColumn());
    }

    public function testExistingPayersAreSkippedOrUpdatedWithColumnsFromTheFile(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'tax_id' => '6342851974', 'contact_person' => 'Katarzyna Zielińska']);
        $this->db->exec("UPDATE payers SET city = 'Katowice', email = 'faktury@novatech.example.com' WHERE id = {$payerId}");
        $content = self::csv(['NIP', 'Nazwa płatnika', 'Osoba kontaktowa', 'Miejscowość'], [
            ['6342851974', 'NovaTech Sp. z o.o.', 'Katarzyna Zielińska', 'Gliwice'],
        ]);

        $skip = $this->service()->commit($this->admin, 'payers', 'platnicy.csv', $content);
        $this->assertSame('skip', $skip['rows'][0]['status']);
        $this->assertSame($payerId, $skip['rows'][0]['record_id']);

        $update = $this->service()->commit($this->admin, 'payers', 'platnicy.csv', $content, ['mode' => 'update']);
        $this->assertSame('update', $update['rows'][0]['status']);
        $this->assertSame(['Miejscowość'], $update['rows'][0]['changes']);
        $row = $this->db->query("SELECT city, email FROM payers WHERE id = {$payerId}")->fetch();
        $this->assertSame('Gliwice', $row['city']);
        // Kolumny spoza pliku zostają bez zmian.
        $this->assertSame('faktury@novatech.example.com', $row['email']);
        $this->assertSame(1, $this->countEvents('payer', $payerId, 'updated'));

        $again = $this->service()->commit($this->admin, 'payers', 'platnicy.csv', $content, ['mode' => 'update']);
        $this->assertSame('unchanged', $again['rows'][0]['status']);
    }

    public function testBeneficiariesResolvePayerByTaxIdOrNameAndReportUnknownPayers(): void
    {
        $nova = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'tax_id' => '6342851974']);
        $this->insertPayer(['company_name' => 'Duplikat']);
        $this->insertPayer(['company_name' => 'Duplikat']);

        $result = $this->service()->commit($this->admin, 'beneficiaries', 'osoby.csv', self::csv(
            ['Imię', 'Nazwisko', 'E-mail', 'NIP płatnika', 'Płatnik'],
            [
                ['Jan', 'Kowalski', 'jan@example.com', '634-285-19-74', null],
                ['Maria', 'Wójcik', 'maria@example.com', null, 'novatech sp. z o.o.'],
                ['Anna', 'Obca', null, null, 'Nieznana Firma'],
                ['Piotr', 'Dwuznaczny', null, null, 'Duplikat'],
                ['Ewa', 'Bez Płatnika', null, null, null],
            ]
        ));

        $this->assertSame(['create', 'create', 'error', 'error', 'create'], array_column($result['rows'], 'status'));
        $this->assertSame('payer_name', $result['rows'][2]['errors'][0]['field']);
        $this->assertStringContainsString('Nieznana Firma', $result['rows'][2]['errors'][0]['message']);
        $this->assertSame('payer_name', $result['rows'][3]['errors'][0]['field']);
        $payers = $this->db->query('SELECT last_name, payer_id FROM beneficiaries ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertEquals(['Kowalski' => $nova, 'Wójcik' => $nova, 'Bez Płatnika' => null], $payers);
    }

    public function testCertificatesAcceptLabelsPolishDatesAndReferencesAndMatchBySerial(): void
    {
        $owner = $this->createActor(Rbac::OPERATOR, 'ewa.opiekun@example.com');
        $payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'tax_id' => '6342851974']);
        $personId = $this->insertBeneficiary($payerId, ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com']);
        $existing = $this->insertCertificate($this->admin->id, $payerId, [
            'name' => 'Stary SSL', 'serial_number' => 'ABC123456', 'issuer' => 'Certum', 'expiry_date' => '2026-12-01',
        ]);

        $headers = ['Nazwa', 'Typ', 'Numer seryjny', 'Wystawca', 'Ważny od', 'Data wygaśnięcia', 'E-mail osoby', 'Opiekun', 'Koszt', 'Okres rozliczenia', 'Odnawianie automatyczne'];
        $content = self::csv($headers, [
            ['Podpis Jana', 'Certyfikat kwalifikowany', 'QS-2027-001', 'Certum', '01.10.2025', '30.09.2027', 'jan@example.com', 'ewa.opiekun@example.com', '320,00 zł', 'roczny', 'tak'],
            ['SSL odnowiony', 'SSL_CERTIFICATE', 'ABC123456', 'certum', null, '2027-12-01', null, null, '99', 'annual', 'nie'],
            ['Zła data', 'Certyfikat kwalifikowany', null, null, null, '31.02.2027', 'jan@example.com', null, null, null, null],
            ['Nieznany typ', 'Karta rabatowa', null, null, null, '2027-01-01', 'jan@example.com', null, null, null, null],
            ['Nieznany opiekun', 'Domena', null, null, null, '2027-01-01', 'jan@example.com', 'nikt@example.com', null, null, null],
        ]);

        $preview = $this->service()->preview($this->admin, 'certificates', 'certyfikaty.csv', $content, ['mode' => 'update']);
        $this->assertSame(['create', 'update', 'error', 'error', 'error'], array_column($preview['rows'], 'status'), (string) json_encode($preview['rows'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(['expiry_date'], array_column($preview['rows'][2]['errors'], 'field'));
        $this->assertSame(['certificate_type'], array_column($preview['rows'][3]['errors'], 'field'));
        $this->assertSame(['owner_email'], array_column($preview['rows'][4]['errors'], 'field'));
        $this->assertStringContainsString('nikt@example.com', $preview['rows'][4]['errors'][0]['message']);

        $result = $this->service()->commit($this->admin, 'certificates', 'certyfikaty.csv', $content, ['mode' => 'update']);
        $created = $this->db->query('SELECT * FROM certificates WHERE id = ' . (int) $result['rows'][0]['record_id'])->fetch();
        $this->assertSame('QUALIFIED_SIGNATURE', $created['certificate_type']);
        $this->assertSame('2025-10-01', $created['valid_from']);
        $this->assertSame('2027-09-30', $created['expiry_date']);
        $this->assertSame((string) $personId, (string) $created['beneficiary_id']);
        // Płatnik przechodzi z osoby, gdy plik go nie podaje.
        $this->assertSame((string) $payerId, (string) $created['payer_id']);
        $this->assertSame((string) $owner->id, (string) $created['user_id']);
        $this->assertSame('320.00', $created['annual_cost']);
        $this->assertSame('1', (string) $created['auto_renew']);

        $updated = $this->db->query("SELECT name, expiry_date, certificate_type FROM certificates WHERE id = {$existing}")->fetch();
        $this->assertSame(['name' => 'SSL odnowiony', 'expiry_date' => '2027-12-01', 'certificate_type' => 'SSL_CERTIFICATE'], $updated);
        $this->assertSame($existing, $result['rows'][1]['record_id']);
    }

    public function testXmlImportAndMissingRequiredColumns(): void
    {
        $payerId = $this->insertPayer(['company_name' => 'Grupa Wisła S.A.', 'tax_id' => '9876543210']);
        $xml = XmlExporter::records('certisub', 'certificate', [
            ['name' => 'Domena wisla.pl', 'certificate_type' => 'DOMAIN', 'expiry_date' => '2027-03-01', 'payer_tax_id' => '9876543210'],
        ], ['dataset' => 'certificates']);

        $result = $this->service()->commit($this->admin, 'certificates', 'dane.xml', $xml);
        $this->assertSame('xml', $result['format']);
        $this->assertSame('create', $result['rows'][0]['status']);
        $this->assertSame((string) $payerId, (string) $this->db->query("SELECT payer_id FROM certificates WHERE name = 'Domena wisla.pl'")->fetchColumn());

        $incomplete = self::csv(['Nazwa', 'NIP'], [['Bez typu', '9876543210']]);
        $preview = $this->service()->preview($this->admin, 'certificates', 'braki.csv', $incomplete);
        $this->assertArrayHasKey('fatal', $preview);
        $this->assertSame(['certificate_type', 'expiry_date'], array_column($preview['columns']['missing'], 'field'));
        $this->assertSame([], $preview['rows']);

        try {
            $this->service()->commit($this->admin, 'certificates', 'braki.csv', $incomplete);
            $this->fail('Import bez wymaganych kolumn powinien zostać odrzucony.');
        } catch (ServiceException $e) {
            $this->assertSame(422, $e->httpStatus());
        }
    }

    public function testCsvAttachmentOfEmlMessageCanBeImported(): void
    {
        $csv = self::csv(['Imię', 'Nazwisko', 'E-mail'], [['Anna', 'Nowak', 'anna.nowak@example.com']]);
        $eml = "From: partner@example.com\r\nSubject: Lista osób\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nW załączniku lista.\r\n"
            . "--B\r\nContent-Type: text/csv; name=\"osoby.csv\"\r\nContent-Disposition: attachment; filename=\"osoby.csv\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($csv)) . "--B--\r\n";

        $result = $this->service()->commit($this->admin, 'beneficiaries', 'poczta.eml', $eml, ['attachment' => 0]);

        $this->assertSame('poczta.eml → osoby.csv', $result['file_name']);
        $this->assertSame('create', $result['rows'][0]['status']);
        $this->assertSame(1, $this->tableCount('beneficiaries'));

        $this->expectException(ServiceException::class);
        $this->service()->preview($this->admin, 'beneficiaries', 'poczta.eml', $eml, ['attachment' => 5]);
    }

    public function testOnlyAdministratorImports(): void
    {
        try {
            $this->service()->preview($this->createActor(Rbac::MANAGER), 'payers', 'p.csv', self::csv(['Nazwa firmy', 'Osoba kontaktowa'], [['A', 'B']]));
            $this->fail('Import jest tylko dla administratora.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }
    }
}

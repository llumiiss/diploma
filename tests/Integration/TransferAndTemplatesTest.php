<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exchange\CsvWriter;
use App\Exchange\EmlParser;
use App\Rbac;
use App\Service\Actor;
use App\Service\ExportService;
use App\Service\ImportService;
use App\Service\ReadingRuleService;
use App\Service\ServiceException;
use Tests\Support\FakeCompanyRegistry;
use Tests\Support\IntegrationTestCase;

/**
 * Przenoszenie danych (Etap 11): konta personelu, szablony wiadomości i szablony odczytu —
 * eksport, import z podglądem, wyłączanie wierszy i uprawnienia.
 */
final class TransferAndTemplatesTest extends IntegrationTestCase
{
    private Actor $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createActor(Rbac::ADMIN, 'admin.transfer@example.com');
    }

    private function import(): ImportService
    {
        return new ImportService($this->db, null, new FakeCompanyRegistry());
    }

    private function rows(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    public function testAccountsArePreviewedThenCreatedWithoutPasswords(): void
    {
        $payerId = $this->insertPayer();
        $personId = $this->insertBeneficiary($payerId, ['email' => 'pracownik@example.com']);
        $content = CsvWriter::write(['Imię', 'Nazwisko', 'E-mail', 'Rola', 'E-mail użytkownika certyfikatu'], [
            ['Anna', 'Admin', 'anna.admin@example.com', 'Administrator', null],
            ['Olek', 'Operator', 'olek@example.com', 'operator', null],
            ['Piotr', 'Pracownik', 'piotr@example.com', 'pracownik', 'pracownik@example.com'],
            ['Zła', 'Rola', 'zla@example.com', 'prezes wszystkiego', null],
            ['Ktoś', 'Wyłączony', 'wylaczony@example.com', 'operator', null],
        ]);
        $before = $this->rows('users');

        $preview = $this->import()->preview($this->admin, 'accounts', 'konta.csv', $content);

        $this->assertSame(['create', 'create', 'create', 'error', 'create'], array_column($preview['rows'], 'status'));
        $this->assertSame($before, $this->rows('users'), 'podgląd niczego nie zapisuje');
        $values = array_column($preview['rows'][2]['values'], 'value', 'field');
        $this->assertSame('pracownik', $values['role']);
        $this->assertSame('pracownik@example.com', $values['beneficiary_email']);

        // Wiersz 6 (ostatni) odznaczony w podglądzie.
        $result = $this->import()->commit($this->admin, 'accounts', 'konta.csv', $content, ['exclude' => [6]]);

        $this->assertSame(['rows' => 5, 'create' => 3, 'update' => 0, 'unchanged' => 0, 'skip' => 0, 'excluded' => 1, 'error' => 1], $result['totals']);
        $this->assertSame($before + 3, $this->rows('users'));
        $rows = $this->db->query("SELECT email, role, beneficiary_id, password_hash FROM users WHERE email IN ('anna.admin@example.com', 'piotr@example.com') ORDER BY email")->fetchAll();
        $this->assertSame('ADMIN', $rows[0]['role']);
        $this->assertNull($rows[0]['password_hash']);
        $this->assertSame('EMPLOYEE', $rows[1]['role']);
        $this->assertSame($personId, (int) $rows[1]['beneficiary_id']);
        $this->assertFalse($this->db->query("SELECT 1 FROM users WHERE email = 'wylaczony@example.com'")->fetchColumn());

        $again = $this->import()->commit($this->admin, 'accounts', 'konta.csv', CsvWriter::write(['Imię', 'Nazwisko', 'E-mail', 'Rola'], [
            ['Olek', 'Operator', 'OLEK@example.com', 'Menedżer'],
        ]), ['mode' => 'update']);
        $this->assertSame(1, $again['totals']['update']);
        $this->assertSame('MANAGER', $this->db->query("SELECT role FROM users WHERE email = 'olek@example.com'")->fetchColumn());
    }

    public function testAccountExportHasNoPasswordsAndImportsBack(): void
    {
        $this->createActor(Rbac::ACCOUNTANT, 'ksiegowa.transfer@example.com');
        $export = new ExportService($this->db);

        foreach (['csv', 'xml'] as $format) {
            $file = $export->export($this->admin, 'accounts', $format);
            $this->assertStringContainsString('ksiegowa.transfer@example.com', $file['body']);
            $this->assertStringNotContainsString('$2y$', $file['body'], 'skróty haseł nigdy nie opuszczają systemu');
            $this->assertStringNotContainsString('password', $file['body']);

            $preview = $this->import()->preview($this->admin, 'accounts', 'konta.' . $format, $file['body']);
            $this->assertSame($preview['totals']['rows'], $preview['totals']['skip'], $format . ': wszystkie konta już istnieją');
        }

        foreach ([Rbac::MANAGER, Rbac::ACCOUNTANT, Rbac::DIRECTOR] as $role) {
            $actor = $this->createActor($role);
            foreach (['accounts', 'email_templates', 'reading_rules'] as $dataset) {
                try {
                    $export->export($actor, $dataset, 'csv');
                    $this->fail($role . ' nie eksportuje zbioru ' . $dataset);
                } catch (ServiceException $e) {
                    $this->assertSame(403, $e->httpStatus());
                }
            }
        }
    }

    public function testEmailTemplatesRoundTripThroughCsvAndXml(): void
    {
        $export = new ExportService($this->db);
        $original = $this->db->query('SELECT code, locale, subject, body_html FROM email_templates ORDER BY code, locale')->fetchAll();

        foreach (['csv', 'xml'] as $format) {
            $body = $export->export($this->admin, 'email_templates', $format)['body'];
            $preview = $this->import()->preview($this->admin, 'email_templates', 'szablony.' . $format, $body, ['mode' => 'update']);
            $this->assertSame(count($original), $preview['totals']['unchanged'], $format . ': eksport wraca bez zmian');
        }

        $content = CsvWriter::write(['code', 'locale', 'name', 'subject', 'body_html'], [
            ['wlasny_szablon', 'PL', 'Własny szablon', 'Odnowienie {nazwa_certyfikatu}', "<p>Dzień dobry {imie},</p>\n<p>termin: {data_waznosci}</p>"],
            ['Zły Kod', 'pl', 'Błędny', 'Temat', '<p>x</p>'],
            [$original[0]['code'], $original[0]['locale'], 'Zmieniona nazwa', 'Nowy temat', '<p>nowa treść</p>'],
        ]);
        $result = $this->import()->commit($this->admin, 'email_templates', 'nowe.csv', $content, ['mode' => 'update']);

        $this->assertSame(['create', 'error', 'update'], array_column($result['rows'], 'status'));
        $this->assertSame("<p>Dzień dobry {imie},</p>\n<p>termin: {data_waznosci}</p>", $this->db->query("SELECT body_html FROM email_templates WHERE code = 'wlasny_szablon'")->fetchColumn());
        $this->assertSame('Nowy temat', $this->db->query("SELECT subject FROM email_templates WHERE code = '{$original[0]['code']}' AND locale = '{$original[0]['locale']}'")->fetchColumn());
    }

    public function testReadingRulesAreImportedAndUsedOnlyForMatchingMessages(): void
    {
        $content = CsvWriter::write(['Nazwa', 'Pole formularza', 'Etykieta w wiadomości', 'Nadawca zawiera'], [
            ['Formularz partnera', 'Użytkownik: imię i nazwisko', 'Abonent:', '@partner.example.com'],
            ['Formularz partnera', 'company.nip', 'Kontrahent', '@partner.example.com'],
            ['Formularz partnera', 'nie ma takiego pola', 'Cokolwiek', null],
        ]);

        $preview = $this->import()->preview($this->admin, 'reading_rules', 'odczyt.csv', $content);
        $this->assertSame(['create', 'create', 'error'], array_column($preview['rows'], 'status'));
        $this->assertSame(0, $this->rows('reading_rules'));

        $this->import()->commit($this->admin, 'reading_rules', 'odczyt.csv', $content);
        $service = new ReadingRuleService($this->db);
        $this->assertSame(['Kontrahent', 'Abonent'], array_column($service->list($this->admin), 'label'), 'kolejność: nazwa, pole, etykieta');

        $mail = static fn (string $from): string => "From: {$from}\r\nSubject: Zlecenie\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nAbonent: Ola Wymyślona";
        $this->assertCount(2, $service->matching(EmlParser::parse($mail('biuro@partner.example.com'))));
        $this->assertSame([], $service->matching(EmlParser::parse($mail('ktos@inna.example.com'))));

        $export = (new ExportService($this->db))->export($this->admin, 'reading_rules', 'csv')['body'];
        $this->assertStringContainsString('Użytkownik: imię i nazwisko', $export);
        $this->assertSame(2, $this->import()->preview($this->admin, 'reading_rules', 'odczyt.csv', $export)['totals']['skip']);
    }

    public function testReadingRulesCrudRequiresAdministrator(): void
    {
        $service = new ReadingRuleService($this->db);
        $rule = $service->create($this->admin, ['name' => 'Test', 'field' => 'cert.serial', 'label' => 'Nr karty klienta: ', 'is_active' => true]);
        $this->assertSame('Nr karty klienta', $rule['label']);

        $updated = $service->update($this->admin, $rule['id'], ['is_active' => false]);
        $this->assertFalse($updated['is_active']);
        $this->assertSame([], $service->matching(EmlParser::parse("From: a@example.com\r\nSubject: x\r\n\r\ny")));

        try {
            $service->create($this->admin, ['name' => 'Test', 'field' => 'cert.serial', 'label' => 'Nr karty klienta']);
            $this->fail('Duplikat etykiety.');
        } catch (ServiceException $e) {
            $this->assertSame(422, $e->httpStatus());
        }

        foreach ([Rbac::MANAGER, Rbac::OPERATOR] as $role) {
            try {
                $service->list($this->createActor($role));
                $this->fail($role);
            } catch (ServiceException $e) {
                $this->assertSame(403, $e->httpStatus());
            }
        }

        $service->delete($this->admin, $rule['id']);
        $this->assertSame(0, $this->rows('reading_rules'));
    }
}

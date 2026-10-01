<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exchange\CsvReader;
use App\Exchange\XmlRecordReader;
use App\Rbac;
use App\Service\Actor;
use App\Service\EventLogger;
use App\Service\ExportService;
use App\Service\ImportService;
use App\Service\ServiceException;
use Tests\Support\IntegrationTestCase;

final class ExportServiceTest extends IntegrationTestCase
{
    private Actor $manager;
    private int $payerId;
    private int $personId;
    private int $certificateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->createActor(Rbac::MANAGER, 'ewa.pawlak@example.com');
        $this->payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'tax_id' => '6342851974', 'contact_person' => 'Katarzyna Zielińska']);
        $this->db->exec("UPDATE payers SET phone = '+48 32 111 22 33', city = 'Katowice' WHERE id = {$this->payerId}");
        $this->personId = $this->insertBeneficiary($this->payerId, ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com']);
        $this->certificateId = $this->insertCertificate($this->manager->id, $this->payerId, [
            'name'             => 'Podpis kwalifikowany',
            'certificate_type' => 'QUALIFIED_SIGNATURE',
            'serial_number'    => '5A3F9C21B7E04D18',
            'issuer'           => 'Certum',
            'beneficiary_id'   => $this->personId,
            'expiry_date'      => date('Y-m-d', strtotime('+5 days')),
        ]);
        $this->db->exec("UPDATE certificates SET discount_percent = 5.00, auto_renew = 1 WHERE id = {$this->certificateId}");
        $this->insertCertificate($this->manager->id, $this->payerId, ['name' => 'Stary podpis', 'beneficiary_id' => $this->personId, 'archived_at' => '2025-01-01 10:00:00']);
    }

    private function service(): ExportService
    {
        return new ExportService($this->db);
    }

    public function testCertificatesCsvHasLabelsForPeopleAndExportIsLogged(): void
    {
        $file = $this->service()->export($this->manager, 'certificates', 'csv');

        $this->assertSame('certyfikaty-' . date('Y-m-d') . '.csv', $file['filename']);
        $this->assertSame('text/csv; charset=UTF-8', $file['content_type']);
        $this->assertSame(1, $file['records']);

        $table = CsvReader::parse($file['body']);
        $this->assertContains('Numer seryjny', $table->headers);
        $this->assertContains('NIP płatnika', $table->headers);
        $row = $table->rows[0]['values'];
        $this->assertSame('Podpis kwalifikowany', $row['Nazwa']);
        $this->assertSame('Certyfikat kwalifikowany', $row['Typ']);
        $this->assertSame('6342851974', $row['NIP płatnika']);
        $this->assertSame('jan@example.com', $row['E-mail użytkownika certyfikatu']);
        $this->assertSame('ewa.pawlak@example.com', $row['E-mail opiekuna']);
        $this->assertSame('-5%', $row['Rabat']);
        $this->assertSame('1', $row['Odnawianie automatyczne']);
        $this->assertSame('Krytyczne', $row['Priorytet']);

        $event = $this->db->query("SELECT payload FROM events WHERE event_type = 'data_exported'")->fetchColumn();
        $this->assertEquals(['dataset' => 'certificates', 'format' => 'csv', 'records' => 1], json_decode((string) $event, true));
    }

    public function testXmlUsesFieldNamesAndCodesAndArchiveIsSeparate(): void
    {
        $file = $this->service()->export($this->manager, 'certificates', 'xml', ['archived' => true]);

        $this->assertSame('certyfikaty-archiwum-' . date('Y-m-d') . '.xml', $file['filename']);
        $table = XmlRecordReader::parse($file['body']);
        $this->assertCount(1, $table->rows);
        $this->assertSame('Stary podpis', $table->rows[0]['values']['name']);
        $this->assertSame('QUALIFIED_SIGNATURE', $table->rows[0]['values']['certificate_type']);
        $this->assertNotSame('', $table->rows[0]['values']['archived_at']);
    }

    public function testExportedPayersAndPeopleImportBackWithoutDuplicates(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $import = new ImportService($this->db);

        foreach (['payers', 'beneficiaries', 'certificates'] as $dataset) {
            foreach (['csv', 'xml'] as $format) {
                $file = $this->service()->export($this->manager, $dataset, $format);
                $result = $import->preview($admin, $dataset, $file['filename'], $file['body'], ['mode' => 'update']);

                $this->assertArrayNotHasKey('fatal', $result, "{$dataset}.{$format}");
                $this->assertSame(
                    ['unchanged'],
                    array_values(array_unique(array_column($result['rows'], 'status'))),
                    "{$dataset}.{$format}: " . json_encode($result['rows'], JSON_UNESCAPED_UNICODE)
                );
            }
        }
    }

    public function testReportCardsScheduleTasksAndTemplates(): void
    {
        $card = $this->service()->export($this->manager, 'payer_card', 'xml', ['id' => $this->payerId]);
        $this->assertSame('karta-platnika-' . $this->payerId . '-' . date('Y-m-d') . '.xml', $card['filename']);
        $xml = simplexml_load_string($card['body']);
        $this->assertNotFalse($xml);
        $this->assertSame('NovaTech Sp. z o.o.', (string) $xml->payer->company_name);
        $this->assertSame('Podpis kwalifikowany', (string) $xml->certificates->certificate[0]->name);
        $this->assertSame('Jan Kowalski', (string) $xml->beneficiaries->beneficiary[0]->first_name . ' ' . (string) $xml->beneficiaries->beneficiary[0]->last_name);

        $cardCsv = CsvReader::parse($this->service()->export($this->manager, 'beneficiary_card', 'csv', ['id' => $this->personId])['body']);
        $this->assertSame('Podpis kwalifikowany', $cardCsv->rows[0]['values']['Nazwa']);
        $this->assertSame('Krytyczne', $cardCsv->rows[0]['values']['Priorytet']);

        $schedule = CsvReader::parse($this->service()->export($this->manager, 'schedule', 'csv', ['filters' => ['months' => '3']])['body']);
        $this->assertSame(substr(date('Y-m-d', strtotime('+5 days')), 0, 7), $schedule->rows[0]['values']['Miesiąc wygaśnięcia']);

        $this->db->exec("INSERT INTO renewal_tasks (certificate_id, status, priority, due_date) VALUES ({$this->certificateId}, 'todo', 'critical', CURDATE())");
        $tasks = CsvReader::parse($this->service()->export($this->manager, 'tasks', 'csv')['body']);
        $this->assertSame('Do zrobienia', $tasks->rows[0]['values']['Status zadania']);

        $template = $this->service()->export($this->manager, 'payers', 'csv', ['template' => true]);
        $this->assertSame('platnicy-wzor-' . date('Y-m-d') . '.csv', $template['filename']);
        $templateTable = CsvReader::parse($template['body']);
        $this->assertSame([], $templateTable->rows);
        $this->assertSame('Nazwa płatnika', $templateTable->headers[0]);
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM events WHERE event_type = 'data_exported' AND JSON_EXTRACT(payload, '$.dataset') = 'payers'")->fetchColumn());
    }

    public function testEventLogExportNeedsAdministratorAndKeepsFilters(): void
    {
        (new EventLogger($this->db))->log('payer', $this->payerId, 'created', $this->manager->id, ['payer_id' => $this->payerId], ['company_name' => 'NovaTech']);
        (new EventLogger($this->db))->log('system', null, 'renewal_scan', null, [], ['scanned' => 1]);

        try {
            $this->service()->export($this->manager, 'events', 'csv');
            $this->fail('Dziennik zdarzeń eksportuje tylko administrator.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }

        $admin = $this->createActor(Rbac::ADMIN);
        $file = $this->service()->export($admin, 'events', 'csv', ['filters' => ['user' => 'system']]);
        $table = CsvReader::parse($file['body']);
        $this->assertCount(1, $table->rows);
        $this->assertSame('System', $table->rows[0]['values']['Autor']);
        $this->assertSame('Uruchomiono skaner odnowień', $table->rows[0]['values']['Zdarzenie']);
        $this->assertSame('{"scanned":1}', $table->rows[0]['values']['Szczegóły (JSON)']);
    }

    public function testOperatorCannotExportAndUnknownDatasetsAreRejected(): void
    {
        try {
            $this->service()->export($this->createActor(Rbac::OPERATOR), 'payers', 'csv');
            $this->fail('Eksport wymaga roli MANAGER.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }

        foreach ([['users', 'csv'], ['payers', 'pdf']] as [$dataset, $format]) {
            try {
                $this->service()->export($this->manager, $dataset, $format);
                $this->fail("{$dataset}.{$format} powinien zostać odrzucony.");
            } catch (ServiceException $e) {
                $this->assertSame(400, $e->httpStatus());
            }
        }
    }
}

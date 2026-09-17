<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exchange\Columns;
use App\Exchange\CsvReader;
use App\Exchange\CsvWriter;
use App\Exchange\Normalizer;
use App\Exchange\XmlExporter;
use App\Exchange\XmlRecordReader;
use App\Service\ServiceException;
use App\Session;
use PHPUnit\Framework\TestCase;

final class ExchangeFormatsTest extends TestCase
{
    protected function setUp(): void
    {
        Session::ensureStarted();
        $_SESSION = [];
    }

    public function testCsvRoundTripKeepsSeparatorsQuotesNewLinesAndPolishCharacters(): void
    {
        $csv = CsvWriter::write(['Nazwa płatnika', 'NIP', 'Uwagi'], [
            ['Łódź Sp. z o.o.', '6342851974', "Linia 1\nLinia 2; średnik"],
            ['Firma "Cytat"', null, 'ok'],
        ]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("\r\n", $csv);

        $table = CsvReader::parse($csv);
        $this->assertSame(';', $table->delimiter);
        $this->assertSame('UTF-8', $table->encoding);
        $this->assertSame(['Nazwa płatnika', 'NIP', 'Uwagi'], $table->headers);
        $this->assertCount(2, $table->rows);
        $this->assertSame("Linia 1\nLinia 2; średnik", $table->rows[0]['values']['Uwagi']);
        $this->assertSame('Firma "Cytat"', $table->rows[1]['values']['Nazwa płatnika']);
        $this->assertSame('', $table->rows[1]['values']['NIP']);
        // Druga pozycja zaczyna się w linii 4, bo pierwsza zajmuje dwie linie pliku.
        $this->assertSame([2, 4], array_column($table->rows, 'line'));
    }

    public function testCsvFormulasAreNeutralisedOnExportAndRestoredOnImport(): void
    {
        $csv = CsvWriter::write(['Telefon', 'Nazwa'], [['+48 600 100 200', '=HYPERLINK("http://example.com")'], [null, '@SUM(A1)']]);

        $this->assertStringContainsString("'+48 600 100 200", $csv);
        $this->assertStringContainsString("\"'=HYPERLINK", $csv);

        $rows = CsvReader::parse($csv)->rows;
        $this->assertSame('+48 600 100 200', $rows[0]['values']['Telefon']);
        $this->assertSame('=HYPERLINK("http://example.com")', $rows[0]['values']['Nazwa']);
        $this->assertSame('@SUM(A1)', $rows[1]['values']['Nazwa']);
        // Liczby nie dostają apostrofu.
        $this->assertStringContainsString('-12', CsvWriter::write(['Dni'], [[-12]]));
        $this->assertStringNotContainsString("'-12", CsvWriter::write(['Dni'], [[-12]]));
    }

    public function testCsvFromPolishExcelInWindows1250WithCommasIsDetected(): void
    {
        $content = (string) iconv('UTF-8', 'CP1250', "Nazwa firmy,NIP,Osoba kontaktowa\r\n\"Żółć, Sp. z o.o.\",634-285-19-74,Józef Ćwik\r\n\r\n");

        $table = CsvReader::parse($content);

        $this->assertSame(',', $table->delimiter);
        $this->assertSame('Windows-1250', $table->encoding);
        $this->assertSame('Żółć, Sp. z o.o.', $table->rows[0]['values']['Nazwa firmy']);
        $this->assertSame('Józef Ćwik', $table->rows[0]['values']['Osoba kontaktowa']);
    }

    public function testCsvWithoutRowsOrWithTooManyRowsIsRejected(): void
    {
        try {
            CsvReader::parse("\n\n");
            $this->fail('Pusty plik powinien zostać odrzucony.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('file', $e->errors);
        }

        $this->expectException(ServiceException::class);
        CsvReader::parse("Nazwa\n" . str_repeat("x\n", CsvReader::MAX_ROWS + 1));
    }

    public function testXmlRoundTripEscapesTextAndReadsRecords(): void
    {
        $xml = XmlExporter::records('certisub', 'payer', [
            ['id' => 7, 'company_name' => 'A & B <Spółka>', 'tax_id' => null, 'notes' => "zły\x01znak"],
            ['id' => 8, 'company_name' => 'Druga', 'tax_id' => '6342851974', 'notes' => ''],
        ], ['dataset' => 'payers']);

        $this->assertStringContainsString('A &amp; B &lt;Spółka&gt;', $xml);

        $table = XmlRecordReader::parse($xml);
        $this->assertSame('xml', $table->format);
        $this->assertSame(['id', 'company_name', 'tax_id', 'notes'], $table->headers);
        $this->assertSame('A & B <Spółka>', $table->rows[0]['values']['company_name']);
        $this->assertSame('złyznak', $table->rows[0]['values']['notes']);
        $this->assertSame('6342851974', $table->rows[1]['values']['tax_id']);
    }

    public function testXmlWithContainerElementAndAttributesIsRead(): void
    {
        $table = XmlRecordReader::parse(
            '<?xml version="1.0"?><export><platnicy><platnik nip="6342851974"><Nazwa_firmy>NovaTech</Nazwa_firmy></platnik>'
            . '<platnik><Nazwa_firmy>Wisła</Nazwa_firmy></platnik></platnicy></export>'
        );

        $this->assertCount(2, $table->rows);
        $this->assertSame(['nip' => '6342851974', 'Nazwa_firmy' => 'NovaTech'], $table->rows[0]['values']);
        $this->assertSame(['nip' => 'tax_id', 'Nazwa_firmy' => 'company_name'], Columns::mapHeaders('payers', $table->headers)['mapping']);
    }

    public function testXmlWithDoctypeOrBrokenSyntaxIsRejected(): void
    {
        foreach ([
            '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x><y>&e;</y></x>',
            '<root><payer><name>Otwarty',
            '',
        ] as $content) {
            try {
                XmlRecordReader::parse($content);
                $this->fail('Dokument powinien zostać odrzucony: ' . $content);
            } catch (ServiceException $e) {
                $this->assertArrayHasKey('file', $e->errors);
            }
        }
    }

    public function testNormalizerUnifiesSpreadsheetValues(): void
    {
        $this->assertSame('2026-09-17', Normalizer::date('17.09.2026'));
        $this->assertSame('2026-09-07', Normalizer::date('7/9/2026'));
        $this->assertSame('2026-09-07', Normalizer::date('2026-9-7 00:00:00'));
        $this->assertSame('jutro', Normalizer::date('jutro'));
        $this->assertSame('1', Normalizer::bool('Tak'));
        $this->assertSame('0', Normalizer::bool('NIE'));
        $this->assertSame('może', Normalizer::bool('może'));
        $this->assertSame('datawygasniecia', Normalizer::key(' Data wygaśnięcia '));

        $types = ['QUALIFIED_SIGNATURE' => 'type.qualified_signature', 'SSL_CERTIFICATE' => 'type.ssl'];
        $this->assertSame('QUALIFIED_SIGNATURE', Normalizer::choice('certyfikat kwalifikowany', $types));
        $this->assertSame('QUALIFIED_SIGNATURE', Normalizer::choice('Qualified certificate', $types));
        $this->assertSame('SSL_CERTIFICATE', Normalizer::choice('ssl_certificate', $types));
        $this->assertSame('Nieznany', Normalizer::choice('Nieznany', $types));
    }

    public function testHeadersInPolishEnglishAndCommonSpreadsheetNamesAreRecognised(): void
    {
        $certificates = Columns::mapHeaders('certificates', [
            'Nazwa', 'Typ', 'Nr seryjny', 'Data wygaśnięcia', 'NIP', 'E-mail', 'Opiekun', 'Kolumna własna', 'ID',
        ]);
        $this->assertSame([
            'Nazwa'            => 'name',
            'Typ'              => 'certificate_type',
            'Nr seryjny'       => 'serial_number',
            'Data wygaśnięcia' => 'expiry_date',
            'NIP'              => 'payer_tax_id',
            'E-mail'           => 'beneficiary_email',
            'Opiekun'          => 'owner_email',
        ], $certificates['mapping']);
        $this->assertSame(['Kolumna własna', 'ID'], $certificates['ignored']);
        $this->assertSame([], $certificates['missing']);

        $payers = Columns::mapHeaders('payers', ['Payer name', 'Tax ID', 'city']);
        $this->assertSame(['company_name', 'tax_id', 'city'], array_values($payers['mapping']));
        $this->assertSame(['contact_person'], $payers['missing']);
    }

    public function testEveryImportColumnLabelMapsBackToItsField(): void
    {
        foreach (Columns::IMPORTABLE as $dataset) {
            $aliases = Columns::aliases($dataset);
            foreach (Columns::fields($dataset) as $field) {
                foreach (['pl', 'en'] as $locale) {
                    $label = Normalizer::translator($locale)->translate(Columns::labelKey($dataset, $field));
                    $this->assertSame($field, $aliases[Normalizer::key($label)] ?? null, "{$dataset}.{$field} ({$locale}: {$label})");
                }
            }
        }
    }
}

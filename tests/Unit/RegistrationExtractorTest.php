<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exchange\EmlParser;
use App\Intake\RegistrationExtractor;
use App\Session;
use PHPUnit\Framework\TestCase;

final class RegistrationExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        Session::ensureStarted();
        $_SESSION = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(string $body, string $subject = 'Wniosek o certyfikat kwalifikowany', string $from = 'Jan Kowalski <jan.kowalski@novatech.example.com>', string $contentType = 'text/plain; charset=utf-8'): array
    {
        $eml = "From: {$from}\r\nTo: rejestracja@certisub.example.com\r\nSubject: {$subject}\r\nMessage-ID: <abc@example.com>\r\nMIME-Version: 1.0\r\nContent-Type: {$contentType}\r\n\r\n{$body}\r\n";

        return RegistrationExtractor::extract(EmlParser::parse($eml));
    }

    public function testLabelledFormInPolish(): void
    {
        $result = $this->extract(<<<'TXT'
            Dzień dobry,

            Imię: Jan
            Nazwisko: Kowalski
            E-mail: jan.kowalski@novatech.example.com
            Telefon: +48 600 100 200

            Firma: NovaTech Sp. z o.o.
            NIP: 634-285-19-74

            Rodzaj certyfikatu: Certyfikat kwalifikowany do podpisu elektronicznego
            Wystawca: Certum
            Okres ważności: 2 lata
            Rabat: -5%
            Uwagi: Odbiór osobisty

            Pozdrawiam
            TXT);

        $this->assertSame(['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan.kowalski@novatech.example.com', 'phone' => '+48 600 100 200'], $result['person']);
        $this->assertSame('634285197' . '4', $result['company']['nip']);
        $this->assertSame('NovaTech Sp. z o.o.', $result['company']['name']);
        $this->assertSame('QUALIFIED_SIGNATURE', $result['certificate']['certificate_type']);
        $this->assertSame('Certum', $result['certificate']['issuer']);
        $this->assertSame(24, $result['certificate']['validity_months']);
        $this->assertSame(5.0, $result['certificate']['discount_percent']);
        $this->assertSame('Odbiór osobisty', $result['certificate']['notes']);
        $this->assertSame('label', $result['sources']['company.nip']);
        $this->assertSame([], $result['warnings']);
        $this->assertTrue($result['looks_like_registration']);
    }

    public function testQualifiedSealIsRecognisedByKeyword(): void
    {
        $result = $this->extract("Proszę o wystawienie pieczęci kwalifikowanej dla firmy.\nNIP: 6342851974");

        $this->assertSame('QUALIFIED_SEAL', $result['certificate']['certificate_type']);
        $this->assertSame('keyword', $result['sources']['certificate.certificate_type']);
    }

    public function testSectionsResolveWhoseEmailAndPhoneItIs(): void
    {
        $result = $this->extract(<<<'TXT'
            Dane wnioskodawcy:
            Imię i nazwisko: dr inż. Maria Nowak-Zielińska
            E-mail: maria@example.com
            Telefon: 601 202 303

            Dane firmy:
            Nazwa: Grupa Wisła S.A.
            NIP: PL9876543210
            E-mail: ksiegowosc@grupawisla.example.com
            Telefon: 33 444 55 66
            Adres: ul. Nadrzeczna 5, 43-460 Wisła
            TXT);

        $this->assertSame('Maria', $result['person']['first_name']);
        $this->assertSame('Nowak-Zielińska', $result['person']['last_name']);
        $this->assertSame('maria@example.com', $result['person']['email']);
        $this->assertSame('601 202 303', $result['person']['phone']);
        $this->assertSame('Grupa Wisła S.A.', $result['company']['name']);
        $this->assertSame('ksiegowosc@grupawisla.example.com', $result['company']['email']);
        $this->assertSame('33 444 55 66', $result['company']['phone']);
        $this->assertSame('ul. Nadrzeczna 5', $result['company']['address_line']);
        $this->assertSame('43-460', $result['company']['postal_code']);
        $this->assertSame('Wisła', $result['company']['city']);
    }

    public function testHtmlTableAndMissingDataFallBackToHeadersAndText(): void
    {
        $html = '<html><body><p>Wniosek:</p><table>'
            . '<tr><td>Imię</td><td>Piotr</td></tr>'
            . '<tr><td>Nazwisko</td><td>Lewandowski</td></tr>'
            . '<tr><td>NIP firmy</td><td>9876543210</td></tr>'
            . '<tr><td>Ważny od</td><td>01.10.2026</td></tr>'
            . '<tr><td>Ważny do</td><td>30.09.2028</td></tr>'
            . '<tr><td>Numer seryjny</td><td>5A3F9C21B7E04D18</td></tr>'
            . '</table></body></html>';

        $result = $this->extract($html, 'Rejestracja', 'Piotr Lewandowski <piotr@grupawisla.example.com>', 'text/html; charset=utf-8');

        $this->assertSame('Piotr', $result['person']['first_name']);
        $this->assertSame('Lewandowski', $result['person']['last_name']);
        $this->assertSame('piotr@grupawisla.example.com', $result['person']['email']);
        $this->assertSame('header', $result['sources']['person.email']);
        $this->assertSame('9876543210', $result['company']['nip']);
        $this->assertSame('2026-10-01', $result['certificate']['valid_from']);
        $this->assertSame('2028-09-30', $result['certificate']['expiry_date']);
        $this->assertSame('5A3F9C21B7E04D18', $result['certificate']['serial_number']);
    }

    public function testFreeTextSentenceStillYieldsNipNameAndPhone(): void
    {
        $result = $this->extract(
            'Dzień dobry, proszę o wystawienie certyfikatu kwalifikowanego dla Agnieszki Mazur z Fundacji Cyfrowy Śląsk (NIP 555-111-22-23). Kontakt: 600 300 400.',
            'Certyfikat dla Agnieszki',
            'Agnieszka Mazur <agnieszka@cyfrowyslask.example.org>'
        );

        $this->assertSame('5551112223', $result['company']['nip']);
        $this->assertSame('text', $result['sources']['company.nip']);
        $this->assertSame('Agnieszka', $result['person']['first_name']);
        $this->assertSame('Mazur', $result['person']['last_name']);
        $this->assertSame('header', $result['sources']['person.first_name']);
        $this->assertSame('600 300 400', $result['person']['phone']);
        $this->assertTrue($result['looks_like_registration']);
    }

    public function testInvalidChecksumNipIsReportedAndAnotherValidOneIsUsed(): void
    {
        $result = $this->extract("NIP: 1234567890\nPłatnik: NIP 6342851974");

        $this->assertSame('6342851974', $result['company']['nip']);
        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('nip_invalid', $codes);

        $none = $this->extract("Firma: Bez NIP-u\nNIP: 1234567890");
        $this->assertNull($none['company']['nip']);
        $this->assertContains('nip_invalid', array_column($none['warnings'], 'code'));
    }

    public function testSeveralDifferentNipsInTheTextAreFlagged(): void
    {
        $result = $this->extract('Faktura na NIP 6342851974, a płatnik 9876543210.');

        $this->assertSame('6342851974', $result['company']['nip']);
        $this->assertContains('nip_multiple', array_column($result['warnings'], 'code'));
    }

    public function testEnglishLabelsAndMarkdownTable(): void
    {
        $result = $this->extract(<<<'TXT'
            | Field | Value |
            |---|---|
            | First name | Anna |
            | Surname | Smith |
            | Company | Acme Poland |
            | VAT | 5260250995 |
            | Certificate type | Qualified electronic seal |
            | Validity | 3 years |
            TXT);

        $this->assertSame('Anna', $result['person']['first_name']);
        $this->assertSame('Smith', $result['person']['last_name']);
        $this->assertSame('Acme Poland', $result['company']['name']);
        $this->assertSame('5260250995', $result['company']['nip']);
        $this->assertSame('QUALIFIED_SEAL', $result['certificate']['certificate_type']);
        $this->assertSame(36, $result['certificate']['validity_months']);
    }

    public function testQuotedReplyAndBulletsAreRead(): void
    {
        $result = $this->extract("> - Imię: Ewa\n> - Nazwisko: Pawlak\n> - NIP: 6342851974");

        $this->assertSame('Ewa', $result['person']['first_name']);
        $this->assertSame('6342851974', $result['company']['nip']);
    }

    public function testPlaceholdersAndForwardedHeadersAreIgnored(): void
    {
        $result = $this->extract("Od: Ktoś <ktos@example.com>\nDo: Ktoś inny\nTelefon: brak\nImię: -\nNazwisko: Nowak\nNIP: 6342851974");

        $this->assertNull($result['person']['phone']);
        $this->assertSame('Nowak', $result['person']['last_name']);
        $this->assertNull($result['certificate']['valid_from']);
    }

    public function testBadDatesDiscountAndValidityProduceWarningsNotErrors(): void
    {
        $result = $this->extract("Ważny od: 31.02.2026\nRabat: dużo\nOkres ważności: wkrótce\nNIP: 6342851974");

        $codes = array_column($result['warnings'], 'code');
        $this->assertContains('date_invalid', $codes);
        $this->assertContains('discount_invalid', $codes);
        $this->assertContains('validity_unreadable', $codes);
        $this->assertNull($result['certificate']['valid_from']);
        $this->assertNull($result['certificate']['discount_percent']);
        $this->assertNull($result['certificate']['validity_months']);
    }

    public function testNotARegistrationIsDetected(): void
    {
        $result = $this->extract('Cześć, w piątek spotkanie zespołu o 10:00.', 'Spotkanie', 'Ktoś <ktos@example.com>');

        $this->assertFalse($result['looks_like_registration']);
        $this->assertContains('nip_missing', array_column($result['warnings'], 'code'));
    }

    public function testValidityMonthsParsing(): void
    {
        $this->assertSame(12, RegistrationExtractor::months('1 rok'));
        $this->assertSame(24, RegistrationExtractor::months('2 lata'));
        $this->assertSame(60, RegistrationExtractor::months('5 lat'));
        $this->assertSame(36, RegistrationExtractor::months('36 miesięcy'));
        $this->assertSame(36, RegistrationExtractor::months('3 years'));
        $this->assertSame(12, RegistrationExtractor::months('12 months'));
        $this->assertNull(RegistrationExtractor::months('bezterminowo'));
        $this->assertNull(RegistrationExtractor::months('500 lat'));
    }

    public function testCompanyAddressInOneLineIsSplit(): void
    {
        $result = $this->extract("Firma: X\nNIP: 6342851974\nAdres: Przemysłowa 12/3, 40-020 Katowice");

        $this->assertSame('Przemysłowa 12/3', $result['company']['address_line']);
        $this->assertSame('40-020', $result['company']['postal_code']);
        $this->assertSame('Katowice', $result['company']['city']);
    }

    public function testSurnameBeforeNameLabelIsReversed(): void
    {
        $result = $this->extract("Nazwisko i imię: Kowalski Jan\nNIP: 6342851974");

        $this->assertSame('Jan', $result['person']['first_name']);
        $this->assertSame('Kowalski', $result['person']['last_name']);
    }
}

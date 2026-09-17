<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exchange\EmlParser;
use App\Service\EmlImportService;
use App\Service\ServiceException;
use App\Session;
use PHPUnit\Framework\TestCase;

final class EmlParserTest extends TestCase
{
    protected function setUp(): void
    {
        Session::ensureStarted();
        $_SESSION = [];
    }

    private static function multipartMessage(): string
    {
        $csv = "Imię;Nazwisko;E-mail\r\nAnna;Nowak;anna.nowak@example.com\r\n";

        return "Return-Path: <jan.kowalski@novatech.example.com>\r\n"
            . "From: =?UTF-8?Q?Jan_Kowalski?= <Jan.Kowalski@NovaTech.example.com>\r\n"
            . "To: \"Biuro, CertiSub\" <biuro@certisub.local>, ania@example.com\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode('Odnowienie podpisu — Łódź') . "?=\r\n"
            . "Date: Wed, 16 Sep 2026 08:15:00 +0000\r\n"
            . "Message-ID: <abc123@mail.example.com>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed;\r\n\tboundary=\"XYZ\"\r\n"
            . "\r\n"
            . "To jest wiadomość MIME.\r\n"
            . "--XYZ\r\n"
            . "Content-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
            . "--ALT\r\n"
            . "Content-Type: text/plain; charset=\"iso-8859-2\"\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "Dzie=F1 dobry, prosz=EA o odnowienie certyfikatu 5A3F9C21B7E04D18 dla firmy=\r\n"
            . " o NIP PL 634-285-19-74. Tel. +48 600 100 200.\r\n"
            . "--ALT\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n\r\n"
            . "<p>Wersja HTML</p>\r\n"
            . "--ALT--\r\n"
            . "--XYZ\r\n"
            . "Content-Type: text/csv; name=\"lista.csv\"\r\n"
            . "Content-Disposition: attachment;\r\n filename*=UTF-8''lista%20os%C3%B3b.csv\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($csv), 40, "\r\n")
            . "--XYZ--\r\n"
            . "Epilog\r\n";
    }

    public function testMultipartMessageWithEncodedHeadersTextAndAttachment(): void
    {
        $message = EmlParser::parse(self::multipartMessage());

        $this->assertSame('Odnowienie podpisu — Łódź', $message->subject);
        $this->assertSame(['name' => 'Jan Kowalski', 'email' => 'jan.kowalski@novatech.example.com'], $message->from);
        $this->assertSame([
            ['name' => 'Biuro, CertiSub', 'email' => 'biuro@certisub.local'],
            ['name' => null, 'email' => 'ania@example.com'],
        ], $message->to);
        $this->assertSame('abc123@mail.example.com', $message->messageId);
        $this->assertNotNull($message->date);
        $this->assertStringStartsWith('2026-09-16', (string) $message->date);
        // Tekst z quoted-printable w ISO-8859-2, z miękkim łamaniem linii; wersja HTML nie jest dublowana.
        $this->assertSame(
            'Dzień dobry, proszę o odnowienie certyfikatu 5A3F9C21B7E04D18 dla firmy o NIP PL 634-285-19-74. Tel. +48 600 100 200.',
            $message->text
        );

        $this->assertCount(1, $message->attachments);
        $attachment = $message->attachments[0];
        $this->assertSame('lista osób.csv', $attachment['filename']);
        $this->assertSame('text/csv', $attachment['content_type']);
        $this->assertStringContainsString('anna.nowak@example.com', $attachment['content']);
    }

    public function testDateKeepsTheCalendarDayEvenWhenTheWeekdayNameIsWrong(): void
    {
        // Nagłówek z błędną nazwą dnia tygodnia (16.09.2026 to środa) nie może przesuwać daty.
        $message = EmlParser::parse("From: a@example.com\nDate: Tue, 16 Sep 2026 09:12:00 +0200 (CEST)\nSubject: X\n\nTreść");

        $this->assertSame('2026-09-16 09:12:00', $message->date);
    }

    public function testHtmlOnlyMessageIsConvertedToText(): void
    {
        $message = EmlParser::parse(
            "From: biuro@example.com\nSubject: Test\nContent-Type: text/html; charset=utf-8\nContent-Transfer-Encoding: base64\n\n"
            . base64_encode('<p>Certyfikat <b>wygasa</b> 5.10.2026</p><p>Pozdrawiamy</p>')
        );

        $this->assertSame(['name' => null, 'email' => 'biuro@example.com'], $message->from);
        $this->assertSame("Certyfikat wygasa 5.10.2026\n\nPozdrawiamy", $message->text);
        $this->assertSame([], $message->attachments);
    }

    public function testFileWithoutMailHeadersIsRejected(): void
    {
        $this->expectException(ServiceException::class);
        EmlParser::parse("To nie jest wiadomość\n\nTylko tekst.");
    }

    public function testDetectsPhonesTaxIdsAndDatasetOfAttachment(): void
    {
        $text = 'NIP PL 634-285-19-74, drugi 1234567890, telefon +48 600 100 200 albo (32) 111 22 33, faktura 6342851974.';

        $this->assertSame(['6342851974'], EmlImportService::taxIds($text));
        $this->assertSame(['+48 600 100 200', '(32) 111 22 33'], EmlImportService::phones($text));

        $this->assertSame('beneficiaries', EmlImportService::guessDataset(['Imię', 'Nazwisko', 'E-mail']));
        $this->assertSame('certificates', EmlImportService::guessDataset(['Nazwa', 'Typ', 'Data wygaśnięcia', 'Numer seryjny']));
        $this->assertSame('payers', EmlImportService::guessDataset(['Nazwa płatnika', 'NIP', 'Osoba kontaktowa']));
        $this->assertNull(EmlImportService::guessDataset(['Kolumna A', 'Kolumna B']));
    }
}

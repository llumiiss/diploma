<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Wymyślone powiadomienia dostawcy certyfikatów o układzie spotykanym w praktyce: tabele układu strony,
 * style w treści, „Etykieta:” i wartość w osobnych znacznikach i liniach źródła, nazwisko pod nagłówkiem
 * oraz stopka z danymi rejestrowymi nadawcy. Wszystkie osoby, numery i adresy są fikcyjne.
 */
final class VendorNoticeFixtures
{
    public static function issued(): string
    {
        return self::message('Podpis już czeka na instalację', <<<'HTML'
            <tr><td><div>Na podstawie wniosku o numerze <strong>R100200</strong> wydaliśmy dla Twojego klienta podpis kwalifikowany na karcie</div></td></tr>
            <tr><td><div>Dane podpisu</div></td></tr>
            <tr><td>
                <div>
                    <span>Imię:</span>
                    <b>Katarzyna</b>
                </div>
                <div>
                    <span>Nazwisko:</span>
                    <b>Przykładowa</b>
                </div>
                <div>
                    <span>E-kod:</span>
                    <b>Odnowienie certyfikatu na karcie, podpis 3-letni</b>
                </div>
                <div>
                    <span>Początek ważności certyfikatu:</span>
                    <b>10.03.2026 09:15:00 UTC</b>
                </div>
                <div>
                    <span>Koniec ważności certyfikatu:</span>
                    <b>09.03.2029 22:59:59 UTC</b>
                </div>
                <div>
                    <span>Telefon:</span>
                    <b>+48 500100200</b>
                </div>
                <div>
                    <span>E-mail:</span>
                    <b>k.przykladowa@example.com</b>
                </div>
            </td></tr>
            HTML);
    }

    public static function expiring(): string
    {
        return self::message('Podpis klienta wygaśnie za 60 dni. Skontaktuj się z nim już teraz!', <<<'HTML'
            <tr><td><div>Podpis Twojego klienta wygaśnie za 60 dni!</div></td></tr>
            <tr><td><div>Podpis elektroniczny Twojego klienta straci ważność za <strong>60 dni</strong>:<br /> 15.07.2027 21:59:59 UTC</div></td></tr>
            <tr><td><div>Dane podpisu na karcie</div></td></tr>
            <tr><td>
                <div>
                    <span>Numer karty:</span>
                    <b>1000200030004000</b>
                </div>
                <div>
                    <span>Data wydania:</span>
                    <b>16.07.2024 08:00:00 UTC</b>
                </div>
                <div>
                    <span>Numer seryjny certyfikatu:</span>
                    <b>0a1b2c3d4e5f60718293a4b5c6d7e8f9</b>
                </div>
                <div><b>WYDANY DLA</b></div>
                <div><b>Tomasz Testowy</b></div>
            </td></tr>
            <tr><td><div>Skontaktuj się z klientem już teraz!</div></td></tr>
            <tr><td><div><div>+48 600700800</div><div>tomasz.testowy@firma-testowa.example</div></div></td></tr>
            <tr><td><div>Nie odnowisz podpisu, który wygasł lub został unieważniony.</div></td></tr>
            HTML);
    }

    private static function message(string $subject, string $rows): string
    {
        static $sequence = 0;
        ++$sequence;

        $html = '<html><head><title>Powiadomienie</title><style>#outlook a { padding: 0; } body { width: 100% !important; }</style></head><body>'
            . '<!-- układ strony --><table><tr><td><table>' . $rows . '</table></td></tr></table>'
            . '<table><tr><td><div>Dziękujemy za zaufanie naszym usługom,</div><div>Zespół dostawcy</div></td></tr>'
            . '<tr><td>Uwaga! Ta wiadomość została wygenerowana automatycznie. Prosimy na nią nie odpowiadać.</td></tr>'
            . '<tr><td><div>Dostawca - dane adresowe:</div><div>ul. Wymyślona 1, 00-001 Warszawa</div></td></tr>'
            . '<tr><td>© Dostawca Testowy S.A. Sąd Rejonowy w Warszawie, KRS: 0000000000, NIP: 526-025-09-95, kapitał zakładowy 100 000 PLN, tel. 22 100 20 30.</td></tr>'
            . '</table></body></html>';

        return "From: CERTUM PCC <autoresponder1@certum.pl>\r\nTo: partner@example.com\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . "Message-ID: <notice-{$sequence}-" . bin2hex(random_bytes(4)) . "@example.com>\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n";
    }
}

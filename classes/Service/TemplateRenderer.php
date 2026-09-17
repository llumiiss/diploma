<?php

declare(strict_types=1);

namespace App\Service;

use App\CertificateHelper;
use App\Translator;
use DateTimeImmutable;

/**
 * Wypełnianie szablonów wiadomości (F13) danymi certyfikatu i odbiorcy.
 *
 * Pola mają postać {nazwa}. W treści HTML wartości są escapowane, a w temacie usuwane są znaki
 * nowej linii (ochrona przed wstrzyknięciem nagłówków). Nieznane pola zostają bez zmian.
 */
final class TemplateRenderer
{
    public const PLACEHOLDERS = [
        'imie', 'nazwisko', 'nazwa_certyfikatu', 'typ_certyfikatu', 'numer_seryjny', 'wystawca',
        'data_waznosci', 'dni_do_wygasniecia', 'platnik', 'opiekun', 'opiekun_email',
    ];

    /**
     * @param array<string, string> $values
     * @return array{subject: string, body_html: string, body_text: string}
     */
    public static function render(string $subject, string $bodyHtml, ?string $bodyText, array $values): array
    {
        $plain = [];
        $escaped = [];
        foreach ($values as $name => $value) {
            $plain['{' . $name . '}'] = $value;
            $escaped['{' . $name . '}'] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $html = strtr($bodyHtml, $escaped);
        $text = $bodyText !== null && trim($bodyText) !== '' ? strtr($bodyText, $plain) : self::htmlToText($html);

        return [
            'subject'   => trim((string) preg_replace('/[\r\n]+/', ' ', strtr($subject, $plain))),
            'body_html' => $html,
            'body_text' => $text,
        ];
    }

    /**
     * Wartości pól dla certyfikatu i odbiorcy w języku szablonu.
     *
     * @param array<string, mixed> $certificate wiersz z CertificateService (name, certificate_type, serial_number,
     *                                          issuer, expiry_date, company_name, user_first_name, user_last_name, user_email)
     * @param array{first_name: string, last_name: string} $recipient
     * @return array<string, string>
     */
    public static function values(array $certificate, array $recipient, string $locale, ?DateTimeImmutable $today = null): array
    {
        $translator = Translator::forLocale($locale);
        $today = $today ?? new DateTimeImmutable('today');
        $expiry = new DateTimeImmutable((string) $certificate['expiry_date']);
        $daysLeft = (int) $today->diff($expiry)->format('%r%a');

        return [
            'imie'               => $recipient['first_name'],
            'nazwisko'           => $recipient['last_name'],
            'nazwa_certyfikatu'  => (string) $certificate['name'],
            'typ_certyfikatu'    => $translator->translate(CertificateHelper::typeKey((string) $certificate['certificate_type'])),
            'numer_seryjny'      => (string) ($certificate['serial_number'] ?? '') !== '' ? (string) $certificate['serial_number'] : '—',
            'wystawca'           => (string) ($certificate['issuer'] ?? '') !== '' ? (string) $certificate['issuer'] : '—',
            'data_waznosci'      => $expiry->format($locale === 'pl' ? 'd.m.Y' : 'j M Y'),
            'dni_do_wygasniecia' => (string) $daysLeft,
            'platnik'            => (string) ($certificate['company_name'] ?? ''),
            'opiekun'            => trim(($certificate['user_first_name'] ?? '') . ' ' . ($certificate['user_last_name'] ?? '')),
            'opiekun_email'      => (string) ($certificate['user_email'] ?? ''),
        ];
    }

    /**
     * Rozdziela „Imię Nazwisko” osoby kontaktowej płatnika na dwa pola szablonu.
     *
     * @return array{first_name: string, last_name: string}
     */
    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [''];

        return ['first_name' => $parts[0], 'last_name' => $parts[1] ?? ''];
    }

    public static function htmlToText(string $html): string
    {
        $text = (string) preg_replace('#<\s*br\s*/?\s*>#i', "\n", $html);
        $text = (string) preg_replace('#</\s*(p|div|li|h[1-6]|tr)\s*>#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim(implode("\n", array_map('trim', explode("\n", $text))));
    }
}

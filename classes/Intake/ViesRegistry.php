<?php

declare(strict_types=1);

namespace App\Intake;

use DateTimeImmutable;

/**
 * Klient VIES — unijnego systemu weryfikacji numerów VAT (Komisja Europejska):
 *   POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number
 *   {"countryCode":"DE","vatNumber":"123456789"}
 * Publiczne API bez klucza i bez rejestracji. Odpowiedź: {"valid":true,"name":…,"address":…}
 * (pola traderXxx z wartością „---” to dane nieujawnione przez dany kraj).
 *
 * Po co obok Białej listy: Biała lista zna wyłącznie polskich podatników, a certyfikaty opłacają
 * też firmy z innych krajów Unii. VIES zwraca mniej niż Biała lista (nazwa i adres, bez REGON-u
 * i KRS-u), ale wystarcza, żeby nie wpisywać danych z ręki i żeby potwierdzić, że numer VAT jest ważny.
 *
 * Adres przychodzi jako jeden ciąg z łamaniem wiersza, w formacie zależnym od kraju — rozbijamy
 * go ostrożnie i gdy nie rozpoznamy układu, wszystko trafia do pola „ulica”, a operator poprawia.
 *
 * @see https://ec.europa.eu/taxation_customs/vies/
 */
final class ViesRegistry implements CompanyRegistry
{
    public const DEFAULT_BASE_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api';

    /** Kraje Unii (plus kod XI dla Irlandii Północnej) — tylko ich numery ma sens pytać. */
    private const COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    /** @var callable(string, string): array{0: int, 1: string} */
    private $transport;

    /**
     * @param (callable(string, string): array{0: int, 1: string})|null $transport adres, treść → [kod HTTP, odpowiedź]
     */
    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeout = 8,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->defaultTransport(...);
    }

    /**
     * VIES obsługuje numery z kodem kraju. Polskie numery zostawiamy Białej liście — daje więcej danych.
     */
    public function supports(string $taxId): bool
    {
        [$country, $number] = self::split($taxId);

        return $country !== null && $country !== 'PL' && $number !== '';
    }

    public function lookupNip(string $taxId, ?DateTimeImmutable $date = null): ?CompanyRecord
    {
        [$country, $number] = self::split($taxId);

        if ($country === null || $number === '') {
            throw new RegistryException('VIES: numer bez kodu kraju Unii — ' . $taxId);
        }

        $payload = (string) json_encode(['countryCode' => $country, 'vatNumber' => $number]);
        [$status, $body] = ($this->transport)(rtrim($this->baseUrl, '/') . '/check-vat-number', $payload);

        if ($status === 429) {
            throw new RegistryException('VIES odrzucił zapytanie (limit zapytań).');
        }
        if ($status >= 500 || $status === 0) {
            throw new RegistryException('VIES jest niedostępny (HTTP ' . $status . ').');
        }

        /** @var mixed $data */
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new RegistryException('Nieczytelna odpowiedź VIES.');
        }

        if ($status >= 400 || isset($data['errorWrappers'])) {
            throw new RegistryException('VIES odrzucił zapytanie: ' . self::errorMessage($data, $status));
        }

        // REST API zwraca pole „valid” (SOAP tak samo); „isValid” zostaje jako zapas,
        // bo tak nazywa je część przykładów w dokumentacji.
        $valid = $data['valid'] ?? $data['isValid'] ?? false;
        if ($valid !== true) {
            return null;
        }

        return self::record($country . $number, $data);
    }

    /**
     * @param array<string, mixed> $data odpowiedź check-vat-number
     */
    public static function record(string $taxId, array $data): CompanyRecord
    {
        $text = static function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;
            // VIES na nieujawnione pola zwraca „---”.
            if (!is_string($value) || trim($value) === '' || trim($value) === '---') {
                return null;
            }

            return trim($value);
        };

        $name = $text('name') ?? '';
        $rawAddress = $text('address');
        [$street, $postal, $city] = self::parseAddress($rawAddress);

        return new CompanyRecord(
            $taxId,
            $name,
            WhiteListRegistry::prettyName($name),
            // Ważny numer w VIES znaczy: podmiot jest zarejestrowany do transakcji wewnątrzunijnych.
            'Czynny',
            null,
            null,
            $street,
            $postal,
            $city,
            $rawAddress,
            $text('requestDate'),
        );
    }

    /**
     * Adres z VIES bywa wielowierszowy, a układ zależy od kraju. Rozpoznajemy kod pocztowy
     * z miejscowością na końcu; przy nieznanym układzie całość zostaje jako ulica.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    public static function parseAddress(?string $raw): array
    {
        if ($raw === null) {
            return [null, null, null];
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $raw) ?: []), static fn (string $line): bool => $line !== ''));

        if ($lines === []) {
            return [null, null, null];
        }

        $last = $lines[count($lines) - 1];

        // „12345 Berlin”, „1000 Bruxelles”, „09-411 Płock”
        if (count($lines) > 1 && preg_match('/^([A-Z]{0,2}[\s-]?\d{3,6}(?:-\d{3})?)\s+(.+)$/u', $last, $m) === 1) {
            return [self::street($lines), trim($m[1]), WhiteListRegistry::titleCase(trim($m[2]))];
        }

        // „Berlin 12345”
        if (count($lines) > 1 && preg_match('/^(.+?)\s+(\d{3,6})$/u', $last, $m) === 1) {
            return [self::street($lines), trim($m[2]), WhiteListRegistry::titleCase(trim($m[1]))];
        }

        return [WhiteListRegistry::titleCase(implode(', ', $lines)), null, null];
    }

    /**
     * Wiersze adresu bez ostatniego (kod pocztowy z miejscowością) w jednej linii.
     *
     * @param list<string> $lines
     */
    private static function street(array $lines): string
    {
        return WhiteListRegistry::titleCase(implode(', ', array_slice($lines, 0, -1)));
    }

    /**
     * Numer VAT na kod kraju i resztę. Zwraca [null, ''] dla numeru bez kodu kraju Unii.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function split(string $taxId): array
    {
        $normalized = strtoupper((string) preg_replace('/[\s\-.]/', '', $taxId));

        if (preg_match('/^([A-Z]{2})([A-Z0-9]{2,18})$/', $normalized, $m) !== 1) {
            return [null, ''];
        }

        return in_array($m[1], self::COUNTRIES, true) ? [$m[1], $m[2]] : [null, ''];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function errorMessage(array $data, int $status): string
    {
        $wrappers = $data['errorWrappers'] ?? null;

        if (is_array($wrappers) && isset($wrappers[0]) && is_array($wrappers[0])) {
            $error = $wrappers[0]['error'] ?? null;
            if (is_string($error) && $error !== '') {
                return $error;
            }
        }

        return 'HTTP ' . $status;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function defaultTransport(string $url, string $payload): array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                return [0, ''];
            }

            curl_setopt_array($handle, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_USERAGENT      => 'CertiSub-Assistant/1.0',
            ]);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            return [$status, is_string($body) ? $body : ''];
        }

        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => $payload,
            'timeout'       => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return [0, ''];
        }

        $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
        $body = stream_get_contents($handle);
        fclose($handle);

        $status = 0;
        foreach (is_array($headers) ? $headers : [] as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, is_string($body) ? $body : ''];
    }
}

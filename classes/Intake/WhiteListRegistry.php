<?php

declare(strict_types=1);

namespace App\Intake;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Klient API Białej Listy podatników VAT (Ministerstwo Finansów):
 *   GET https://wl-api.mf.gov.pl/api/search/nip/{nip}?date=RRRR-MM-DD
 * Publiczne API bez klucza. Firma, której nie ma w rejestrze, daje odpowiedź 200 z polem subject = null.
 *
 * Transport (cURL albo strumienie PHP) jest wstrzykiwany, więc test podaje własne odpowiedzi.
 */
final class WhiteListRegistry implements CompanyRegistry
{
    public const DEFAULT_BASE_URL = 'https://wl-api.mf.gov.pl';

    /** @var callable(string): array{0: int, 1: string} */
    private $transport;

    /**
     * @param (callable(string): array{0: int, 1: string})|null $transport adres → [kod HTTP, treść]
     */
    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeout = 8,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->defaultTransport(...);
    }

    /**
     * Biała lista zna wyłącznie polskich podatników: dziesięć cyfr, opcjonalnie z prefiksem PL.
     */
    public function supports(string $taxId): bool
    {
        $normalized = strtoupper((string) preg_replace('/[\s\-.]/', '', $taxId));

        return preg_match('/^(?:PL)?\d{10}$/', $normalized) === 1;
    }

    public function lookupNip(string $nip, ?DateTimeImmutable $date = null): ?CompanyRecord
    {
        $nip = (string) preg_replace('/\D/', '', $nip);
        if (preg_match('/^\d{10}$/', $nip) !== 1) {
            throw new RegistryException(\__('registry.error.invalid_nip', ['nip' => $nip]));
        }

        $date ??= new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
        $url = rtrim($this->baseUrl, '/') . '/api/search/nip/' . $nip . '?date=' . $date->format('Y-m-d');

        [$status, $body] = ($this->transport)($url);

        if ($status === 429) {
            throw new RegistryException(\__('registry.error.rate_limited'));
        }
        if ($status >= 500 || $status === 0) {
            throw new RegistryException(\__('registry.error.unavailable', ['status' => $status]));
        }

        /** @var mixed $data */
        $data = json_decode($body, true);
        if ($status >= 400) {
            $message = is_array($data) && isset($data['message']) && is_string($data['message']) ? $data['message'] : 'HTTP ' . $status;
            throw new RegistryException(\__('registry.error.rejected', ['message' => $message]));
        }
        if (!is_array($data) || !is_array($data['result'] ?? null)) {
            throw new RegistryException(\__('registry.error.unreadable'));
        }

        $subject = $data['result']['subject'] ?? null;

        return is_array($subject) ? self::record($nip, $subject) : null;
    }

    /**
     * @param array<string, mixed> $subject
     */
    public static function record(string $nip, array $subject): CompanyRecord
    {
        $string = static fn (string $key): ?string => isset($subject[$key]) && is_string($subject[$key]) && trim($subject[$key]) !== '' ? trim($subject[$key]) : null;

        // Firmy mają adres siedziby (workingAddress), osoby prowadzące działalność — czasem tylko adres zamieszkania.
        $raw = $string('workingAddress') ?? $string('residenceAddress');
        [$street, $postal, $city] = self::parseAddress($raw);
        $official = $string('name') ?? '';

        return new CompanyRecord(
            $string('nip') ?? $nip,
            $official,
            self::prettyName($official),
            $string('statusVat'),
            $string('regon'),
            $string('krs'),
            $street,
            $postal,
            $city,
            $raw,
            $string('registrationLegalDate'),
        );
    }

    /**
     * „CHEMIKÓW 7, 09-411 PŁOCK” → [Chemików 7, 09-411, Płock]. Adres bez kodu pocztowego zostaje w całości jako ulica.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    public static function parseAddress(?string $raw): array
    {
        if ($raw === null) {
            return [null, null, null];
        }

        if (preg_match('/^(.*),\s*(\d{2}-\d{3})\s+(.+)$/u', $raw, $m) === 1) {
            return [self::titleCase(trim($m[1])), $m[2], self::titleCase(trim($m[3]))];
        }

        return [self::titleCase($raw), null, null];
    }

    /**
     * Nazwa pisana wielkimi literami (tak zwraca ją rejestr) wraca do zwykłej pisowni, z poprawionymi
     * skrótami form prawnych. Nazwa już pisana normalnie zostaje bez zmian.
     */
    public static function prettyName(string $name): string
    {
        if ($name === '' || $name !== mb_strtoupper($name)) {
            return $name;
        }

        $text = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');
        $text = (string) preg_replace_callback(
            '/\s(Z|Ze|I|W|Na|Oraz)\s/u',
            static fn (array $m): string => ' ' . mb_strtolower($m[1]) . ' ',
            $text
        );

        return trim(strtr($text, [
            'O.o.'   => 'o.o.',
            'S.a.'   => 'S.A.',
            'S.k.a.' => 'S.K.A.',
            'Sp. K.' => 'sp. k.',
            'Sp. J.' => 'sp. j.',
            'Sp. P.' => 'sp. p.',
            'Sp.k.'  => 'sp.k.',
            'Sp. '   => 'sp. ',
        ]));
    }

    /**
     * Wszystkie litery wielkie → zapis normalny; tekst już pisany normalnie zostaje bez zmian.
     * Publiczne, bo tak samo traktujemy adresy z VIES (ViesRegistry) — rejestry zwracają kapitaliki,
     * a w formularzu mają wyglądać jak dane wpisane ręcznie.
     */
    public static function titleCase(string $value): string
    {
        if ($value !== mb_strtoupper($value)) {
            return $value;
        }

        $words = preg_split('/(\s+)/u', mb_strtolower($value), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($words as $index => $word) {
            // Człony z cyfrą zostają małymi literami: „3RD FLOOR” → „3rd Floor”, „12B” → „12b”,
            // bo „3Rd” i „12B” w adresie wyglądają jak błąd.
            if (preg_match('/\d/u', $word) === 1) {
                continue;
            }

            $words[$index] = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }

        return implode('', $words);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function defaultTransport(string $url): array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                return [0, ''];
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_USERAGENT      => 'CertiSub-Assistant/1.0',
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            return [$status, is_string($body) ? $body : ''];
        }

        $context = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => $this->timeout,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: CertiSub-Assistant/1.0\r\n",
        ]]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return [0, ''];
        }

        // Nagłówki odpowiedzi są w metadanych strumienia (wrapper_data), tam też jest kod HTTP.
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

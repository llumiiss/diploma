<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * Krajowy Rejestr Sądowy — publiczne API Ministerstwa Sprawiedliwości, bez klucza:
 *   GET https://api-krs.ms.gov.pl/api/krs/OdpisAktualny/{krs}?rejestr=P&format=json
 *
 * To **nie** jest kolejne źródło `CompanyRegistry`: API przyjmuje wyłącznie numer KRS, więc nie da
 * się nim odpowiedzieć na pytanie „co wiesz o tym NIP-ie”. Używamy go jako uzupełnienia — Biała lista
 * zwraca numer KRS, a KRS dokłada to, czego ona nie ma: **formę prawną** i **adres rozbity na pola**
 * (Biała lista podaje adres jednym ciągiem, który trzeba rozbierać wyrażeniem regularnym).
 *
 * Czego świadomie nie czytamy: działu 2 z reprezentacją. To dane osobowe (imię, nazwisko, PESEL),
 * w odpowiedzi i tak zamaskowane, a do ewidencji certyfikatów niepotrzebne.
 *
 * Odpowiedź waży ok. 60 kB, więc zapisujemy ją w pamięci pośredniej na tych samych zasadach co
 * odpowiedzi Białej listy: wynik (także „nie ma takiego numeru”) na kilka dni, błędy nigdy.
 */
final class KrsRegistry
{
    public const DEFAULT_BASE_URL = 'https://api-krs.ms.gov.pl';
    public const DEFAULT_TTL_DAYS = 7;

    /** @var callable(string): array{0: int, 1: string} */
    private $transport;

    /**
     * @param (callable(string): array{0: int, 1: string})|null $transport
     */
    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeout = 10,
        private readonly ?string $cacheDirectory = null,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_DAYS * 86400,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->defaultTransport(...);
    }

    /**
     * @return array{krs: string, name: ?string, legal_form: ?string, address_line: ?string, postal_code: ?string, city: ?string}|null
     *     null, gdy rejestr nie zna numeru
     *
     * @throws RegistryException gdy rejestr jest niedostępny albo odpowiedź jest nieczytelna
     */
    public function lookupKrs(string $krs): ?array
    {
        $number = str_pad((string) preg_replace('/\D/', '', $krs), 10, '0', STR_PAD_LEFT);

        if (preg_match('/^\d{10}$/', $number) !== 1) {
            throw new RegistryException(\__('registry.error.krs_invalid', ['krs' => $krs]));
        }

        $cached = $this->read($number);
        if ($cached !== null) {
            return $cached['found'] ? $cached['record'] : null;
        }

        [$status, $body] = ($this->transport)(rtrim($this->baseUrl, '/') . '/api/krs/OdpisAktualny/' . $number . '?rejestr=P&format=json');

        if ($status === 404) {
            $this->write($number, null);

            return null;
        }

        if ($status === 429) {
            throw new RegistryException(\__('registry.error.krs_rate_limited'));
        }

        if ($status !== 200) {
            throw new RegistryException(\__('registry.error.krs_unavailable', ['status' => $status]));
        }

        /** @var mixed $data */
        $data = json_decode($body, true);
        $section = is_array($data) ? ($data['odpis']['dane']['dzial1'] ?? null) : null;

        if (!is_array($section)) {
            throw new RegistryException(\__('registry.error.krs_unreadable'));
        }

        $record = self::record($number, $section);
        $this->write($number, $record);

        return $record;
    }

    /**
     * @param array<string, mixed> $section zawartość „dzial1” odpisu
     * @return array{krs: string, name: ?string, legal_form: ?string, address_line: ?string, postal_code: ?string, city: ?string}
     */
    public static function record(string $krs, array $section): array
    {
        $entity = is_array($section['danePodmiotu'] ?? null) ? $section['danePodmiotu'] : [];
        $address = is_array($section['siedzibaIAdres']['adres'] ?? null) ? $section['siedzibaIAdres']['adres'] : [];

        $text = static function (array $source, string $key): ?string {
            $value = $source[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $street = $text($address, 'ulica');
        $house = $text($address, 'nrDomu');
        $flat = $text($address, 'nrLokalu');

        $line = $street;
        if ($line !== null && $house !== null) {
            $line .= ' ' . $house . ($flat !== null ? '/' . $flat : '');
        }

        return [
            'krs'          => $krs,
            // Nazwa i forma prawna przez prettyName — poprawia spójniki i skróty form prawnych
            // („SPÓŁKA Z OGRANICZONĄ…” → „Spółka z Ograniczoną…”); adres zwykłym title case.
            'name'         => self::prettyName($text($entity, 'nazwa')),
            'legal_form'   => self::prettyName($text($entity, 'formaPrawna')),
            'address_line' => self::pretty($line),
            'postal_code'  => $text($address, 'kodPocztowy'),
            'city'         => self::pretty($text($address, 'miejscowosc')),
        ];
    }

    private static function pretty(?string $value): ?string
    {
        return $value === null ? null : WhiteListRegistry::titleCase($value);
    }

    private static function prettyName(?string $value): ?string
    {
        return $value === null ? null : WhiteListRegistry::prettyName($value);
    }

    /**
     * @return array{found: bool, record: array{krs: string, name: ?string, legal_form: ?string, address_line: ?string, postal_code: ?string, city: ?string}}|null
     */
    private function read(string $krs): ?array
    {
        $file = $this->file($krs);

        if ($file === null || !is_file($file) || time() - (int) @filemtime($file) > $this->ttlSeconds) {
            return null;
        }

        $raw = @file_get_contents($file);
        /** @var array<string, mixed>|null $data */
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data) || !isset($data['found'])) {
            return null;
        }

        /** @var array{krs: string, name: ?string, legal_form: ?string, address_line: ?string, postal_code: ?string, city: ?string} $record */
        $record = is_array($data['record'] ?? null) ? $data['record'] : self::record($krs, []);

        return ['found' => (bool) $data['found'], 'record' => $record];
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function write(string $krs, ?array $record): void
    {
        $file = $this->file($krs);
        $directory = $this->directory();

        if ($file === null || $directory === null) {
            return;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode(['found' => $record !== null, 'record' => $record ?? []]);

        if ($payload !== false) {
            @file_put_contents($file, $payload, LOCK_EX);
        }
    }

    private function file(string $krs): ?string
    {
        $directory = $this->directory();

        if ($directory === null || preg_match('/^\d{10}$/', $krs) !== 1) {
            return null;
        }

        return $directory . '/' . $krs . '.json';
    }

    private function directory(): ?string
    {
        if ($this->ttlSeconds <= 0) {
            return null;
        }

        return rtrim($this->cacheDirectory ?? dirname(__DIR__, 2) . '/storage/cache/krs', '/\\');
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
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_USERAGENT      => 'CertiSub-Assistant/1.0',
            ]);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
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

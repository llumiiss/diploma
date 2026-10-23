<?php

declare(strict_types=1);

namespace App\Intake;

use DateTimeImmutable;

/**
 * Pamięć pośrednia dla odpowiedzi rejestru firm.
 *
 * Dane w rejestrach zmieniają się rzadko (nazwa, adres, status VAT), a zapytań robimy dużo:
 * przy każdym wniosku z poczty, przy przycisku „pobierz po NIP” w formularzu firmy i przy imporcie
 * pliku z wieloma płatnikami. Bez pamięci pośredniej import 200 wierszy to 200 zapytań do
 * Ministerstwa Finansów — z limitem zapytań na dobę i czasem odpowiedzi liczonym w sekundach.
 *
 * Zasady:
 *  - zapisujemy też **brak firmy** (rejestr odpowiedział „nie znam tego NIP-u”) — to też wynik,
 *    a powtarzanie takiego pytania nic nie zmieni,
 *  - **nie zapisujemy błędów** (rejestr niedostępny, limit): awaria ma prawo minąć, więc następne
 *    zapytanie musi znów trafić do rejestru,
 *  - wpis starszy niż `ttlSeconds` jest pomijany,
 *  - zapytanie z inną datą (`$date`) omija pamięć — w Białej liście data jest częścią pytania
 *    o status VAT w danym dniu, a historii nie cache'ujemy.
 */
final class CachedCompanyRegistry implements CompanyRegistry
{
    public const DEFAULT_TTL_DAYS = 7;

    public function __construct(
        private readonly CompanyRegistry $registry,
        private readonly string $directory,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_DAYS * 86400,
    ) {
    }

    public function lookupNip(string $nip, ?DateTimeImmutable $date = null): ?CompanyRecord
    {
        if ($date !== null) {
            return $this->registry->lookupNip($nip, $date);
        }

        $digits = (string) preg_replace('/\D/', '', $nip);
        $cached = $this->read($digits);

        if ($cached !== null) {
            return $cached['found'] ? CompanyRecord::fromArray($cached['record']) : null;
        }

        $record = $this->registry->lookupNip($nip);
        $this->write($digits, $record);

        return $record;
    }

    /**
     * @return array{found: bool, record: array<string, mixed>}|null
     */
    private function read(string $nip): ?array
    {
        $file = $this->file($nip);

        if ($file === null || !is_file($file)) {
            return null;
        }

        $age = time() - (int) @filemtime($file);
        if ($age > $this->ttlSeconds) {
            return null;
        }

        $raw = @file_get_contents($file);
        /** @var array<string, mixed>|null $data */
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data) || !isset($data['found'])) {
            return null;
        }

        $record = isset($data['record']) && is_array($data['record']) ? $data['record'] : [];

        return ['found' => (bool) $data['found'], 'record' => $record];
    }

    private function write(string $nip, ?CompanyRecord $record): void
    {
        $file = $this->file($nip);

        if ($file === null) {
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            return;
        }

        $payload = json_encode([
            'found'  => $record !== null,
            'record' => $record?->toArray() ?? [],
        ]);

        if ($payload !== false) {
            @file_put_contents($file, $payload, LOCK_EX);
        }
    }

    private function file(string $nip): ?string
    {
        if (preg_match('/^\d{8,14}$/', $nip) !== 1) {
            return null;
        }

        return rtrim($this->directory, '/\\') . '/' . $nip . '.json';
    }
}

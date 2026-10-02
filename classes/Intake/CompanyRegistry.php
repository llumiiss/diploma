<?php

declare(strict_types=1);

namespace App\Intake;

use DateTimeImmutable;

/**
 * Źródło danych o firmach po NIP-ie. Interfejs pozwala w testach podstawić odpowiedzi bez sieci.
 */
interface CompanyRegistry
{
    /**
     * @return CompanyRecord|null null, gdy rejestr nie zna takiego NIP-u
     * @throws RegistryException gdy rejestr jest niedostępny albo odrzucił zapytanie
     */
    public function lookupNip(string $nip, ?DateTimeImmutable $date = null): ?CompanyRecord;
}

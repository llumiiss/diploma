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
     * Czy to źródło potrafi odpowiedzieć na taki numer (polski NIP, numer VAT z Unii …).
     * Pozwala trzymać kilka rejestrów za jednym interfejsem — patrz CompositeCompanyRegistry.
     */
    public function supports(string $taxId): bool;

    /**
     * @return CompanyRecord|null null, gdy rejestr nie zna takiego NIP-u
     * @throws RegistryException gdy rejestr jest niedostępny albo odrzucił zapytanie
     */
    public function lookupNip(string $nip, ?DateTimeImmutable $date = null): ?CompanyRecord;
}

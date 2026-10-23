<?php

declare(strict_types=1);

namespace App\Intake;

use DateTimeImmutable;

/**
 * Kilka rejestrów za jednym interfejsem: pierwszy, który obsługuje dany numer, odpowiada.
 *
 * Polski NIP obsługuje Biała lista (więcej danych: REGON, KRS, status VAT), numer VAT z innego
 * kraju Unii — VIES. Dzięki temu reszta aplikacji (formularz firmy, wnioski z poczty, import)
 * nie wie, ile jest źródeł ani które z nich odpowiedziało.
 *
 * Gdy źródło zgłosi awarię, próbujemy następnego obsługującego ten numer; jeśli żadne nie
 * odpowiedziało, zgłaszamy ostatni błąd — operator ma wiedzieć, że to awaria rejestru,
 * a nie brak firmy.
 */
final class CompositeCompanyRegistry implements CompanyRegistry
{
    /** @var list<CompanyRegistry> */
    private array $registries;

    public function __construct(CompanyRegistry ...$registries)
    {
        $this->registries = array_values($registries);
    }

    public function supports(string $taxId): bool
    {
        foreach ($this->registries as $registry) {
            if ($registry->supports($taxId)) {
                return true;
            }
        }

        return false;
    }

    public function lookupNip(string $taxId, ?DateTimeImmutable $date = null): ?CompanyRecord
    {
        $lastError = null;
        $asked = false;

        foreach ($this->registries as $registry) {
            if (!$registry->supports($taxId)) {
                continue;
            }

            $asked = true;

            try {
                $record = $registry->lookupNip($taxId, $date);
            } catch (RegistryException $e) {
                $lastError = $e;
                continue;
            }

            if ($record !== null) {
                return $record;
            }
        }

        if ($lastError !== null) {
            throw $lastError;
        }

        if (!$asked) {
            throw new RegistryException('Żaden rejestr nie obsługuje numeru ' . $taxId . ' — wpisz dane firmy ręcznie.');
        }

        return null;
    }
}

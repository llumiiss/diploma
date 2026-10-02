<?php

declare(strict_types=1);

namespace App\Intake;

use RuntimeException;

/**
 * Rejestr nie odpowiedział poprawnie (brak sieci, limit zapytań, błąd serwera). To nie to samo
 * co „firmy nie ma w rejestrze” — wtedy wyszukiwanie zwraca null, a wniosek da się dokończyć ręcznie.
 */
final class RegistryException extends RuntimeException
{
}

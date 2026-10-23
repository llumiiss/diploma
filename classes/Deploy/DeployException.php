<?php

declare(strict_types=1);

namespace App\Deploy;

use RuntimeException;

/**
 * Błąd wdrożenia kodu z paczki: nieczytelne archiwum, brak prawa zapisu, pusta paczka.
 */
final class DeployException extends RuntimeException
{
}

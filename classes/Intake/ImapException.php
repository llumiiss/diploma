<?php

declare(strict_types=1);

namespace App\Intake;

use RuntimeException;

/**
 * Błąd połączenia z serwerem IMAP albo odmowa serwera (komunikat nie zawiera hasła ani tokenu).
 */
final class ImapException extends RuntimeException
{
}

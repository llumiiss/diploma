<?php

declare(strict_types=1);

/**
 * Publiczna rejestracja jest wyłączona (decyzja D3): konta personelu zakłada administrator
 * w panelu „Konta”. Stary adres zostaje, żeby zapisane zakładki prowadziły do logowania.
 */

require __DIR__ . '/bootstrap.php';

use App\Translator;

$langQ = Translator::init()->querySuffix();
$separator = $langQ === '' ? '?' : '&';

header('Location: login.php' . $langQ . $separator . 'registration_closed=1', true, 302);
exit;

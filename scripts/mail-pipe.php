<?php

declare(strict_types=1);

/**
 * Odbiór wniosku z potoku serwera pocztowego (Etap 10) — wiadomość czytana jest ze standardowego wejścia.
 *
 * Przykład aliasu Postfix (/etc/aliases): wnioski: "|php /sciezka/do/scripts/mail-pipe.php"
 * Przykład ręczny: php scripts/mail-pipe.php < wniosek.eml
 *
 * Kod wyjścia 0 oznacza przyjęcie, duplikat albo pominięcie wiadomości niebędącej wnioskiem (serwer pocztowy nie
 * powinien jej odsyłać). Kod 75 (EX_TEMPFAIL) — błąd przejściowy, serwer ponowi dostarczenie; 65 — wiadomość
 * nieczytelna, dostarczenie nie ma sensu ponawiać.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Exchange\EmlParser;
use App\Service\RegistrationIntakeService;
use App\Service\ServiceException;

$raw = stream_get_contents(STDIN, EmlParser::MAX_BYTES + 1);
if ($raw === false || trim($raw) === '') {
    fwrite(STDERR, 'Brak wiadomości na standardowym wejściu.' . PHP_EOL);
    exit(65);
}

try {
    $result = (new RegistrationIntakeService(App\Database::getInstance()->getConnection()))->ingest($raw, 'cli');
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (ServiceException $e) {
    fwrite(STDERR, 'Wiadomość odrzucona: ' . ($e->errors['file'] ?? $e->getMessage()) . PHP_EOL);
    exit(65);
} catch (Throwable $e) {
    fwrite(STDERR, 'Błąd: ' . $e->getMessage() . PHP_EOL);
    exit(75);
}

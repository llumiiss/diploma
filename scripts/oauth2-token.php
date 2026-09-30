<?php

declare(strict_types=1);

/**
 * Zdobywa refresh_token do wysyłki poczty przez OAuth2 (driver „oauth2”).
 *
 * Uruchamiać RAZ, po założeniu klienta OAuth w panelu dostawcy poczty:
 *
 *   php scripts/oauth2-token.php --client-id=... --client-secret=...
 *   php scripts/oauth2-token.php                  (dane brane z config/mail.local.php)
 *   php scripts/oauth2-token.php --provider=microsoft --tenant=<id> --client-id=... --client-secret=...
 *
 * Co robi skrypt:
 *   1. nasłuchuje na http://127.0.0.1:8765 (adres przekierowania klienta typu „Desktop app”),
 *   2. wypisuje adres zgody — otwierasz go w przeglądarce i zatwierdzasz dostęp,
 *   3. odbiera kod autoryzacyjny z przekierowania i wymienia go na tokeny (RFC 6749 §4.1),
 *   4. wypisuje refresh_token, który wklejasz do config/mail.local.php.
 *
 * Skrypt niczego nie zapisuje — token wklejasz sam, żeby plik z sekretami pozostał tylko Twój.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\MailConfig;

const REDIRECT_PORT = 8765;
const REDIRECT_URI = 'http://127.0.0.1:' . REDIRECT_PORT;

$options = getopt('', ['client-id:', 'client-secret:', 'provider:', 'tenant:', 'port:']) ?: [];
$config = MailConfig::oauth2();

$provider = strtolower((string) ($options['provider'] ?? $config['provider'] ?? 'google'));
$clientId = (string) ($options['client-id'] ?? $config['client_id'] ?? '');
$clientSecret = (string) ($options['client-secret'] ?? $config['client_secret'] ?? '');
$tenant = (string) ($options['tenant'] ?? $config['tenant_id'] ?? 'common');

if ($clientId === '' || $clientSecret === '') {
    fwrite(STDERR, "Brak danych klienta OAuth.\n\n");
    fwrite(STDERR, "Podaj je w wierszu polecenia:\n");
    fwrite(STDERR, "  php scripts/oauth2-token.php --client-id=... --client-secret=...\n\n");
    fwrite(STDERR, "albo wpisz do config/mail.local.php w bloku 'oauth2'.\n");
    fwrite(STDERR, "Jak założyć klienta OAuth: config/mail.local.php.example\n");
    exit(1);
}

[$authorizeUrl, $tokenUrl, $scope] = match ($provider) {
    'microsoft' => [
        'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/authorize',
        'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token',
        'offline_access https://outlook.office.com/SMTP.Send',
    ],
    default => [
        'https://accounts.google.com/o/oauth2/v2/auth',
        'https://oauth2.googleapis.com/token',
        'https://mail.google.com/',
    ],
};

$state = bin2hex(random_bytes(16));
$consentUrl = $authorizeUrl . '?' . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => $scope,
    'access_type'   => 'offline',   // bez tego Google nie wyda refresh_token
    'prompt'        => 'consent',
    'state'         => $state,
]);

$server = @stream_socket_server('tcp://127.0.0.1:' . REDIRECT_PORT, $errNo, $errStr);
if ($server === false) {
    fwrite(STDERR, sprintf("Nie mogę nasłuchiwać na porcie %d (%s).\n", REDIRECT_PORT, $errStr));
    fwrite(STDERR, "Zamknij program zajmujący ten port i spróbuj ponownie.\n");
    exit(1);
}

echo "Otwórz ten adres w przeglądarce i zatwierdź dostęp:\n\n";
echo $consentUrl . "\n\n";
echo 'Czekam na przekierowanie na ' . REDIRECT_URI . " ...\n";

$connection = @stream_socket_accept($server, 300);
if ($connection === false) {
    fwrite(STDERR, "Upłynął czas oczekiwania (5 minut). Uruchom skrypt ponownie.\n");
    exit(1);
}

$requestLine = (string) fgets($connection, 8192);
$code = '';
$returnedState = '';

if (preg_match('#^GET\s+(\S+)#', $requestLine, $matches) === 1) {
    $query = (string) parse_url($matches[1], PHP_URL_QUERY);
    parse_str($query, $params);
    $code = is_string($params['code'] ?? null) ? $params['code'] : '';
    $returnedState = is_string($params['state'] ?? null) ? $params['state'] : '';
}

$page = $code !== ''
    ? 'Zgoda przyjęta. Wróć do terminala.'
    : 'Nie otrzymałem kodu autoryzacyjnego. Wróć do terminala.';

fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: close\r\n\r\n");
fwrite($connection, '<!DOCTYPE html><meta charset="utf-8"><p style="font:16px sans-serif">' . $page . '</p>');
fclose($connection);
fclose($server);

if ($code === '') {
    fwrite(STDERR, "Brak kodu autoryzacyjnego w przekierowaniu — zgoda nie została udzielona.\n");
    exit(1);
}

if (!hash_equals($state, $returnedState)) {
    fwrite(STDERR, "Niezgodny parametr state — przerwane dla bezpieczeństwa.\n");
    exit(1);
}

$response = @file_get_contents($tokenUrl, false, stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]),
        'timeout'       => 20,
        'ignore_errors' => true,
    ],
]));

/** @var array<string, mixed>|null $data */
$data = is_string($response) ? json_decode($response, true) : null;

if (!is_array($data) || !isset($data['refresh_token']) || !is_string($data['refresh_token'])) {
    $error = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? '') : '';
    fwrite(STDERR, 'Wymiana kodu na tokeny nie udała się. ' . $error . "\n");
    fwrite(STDERR, "Jeśli dostawca nie zwrócił refresh_token, odbierz aplikacji dostęp w ustawieniach konta i powtórz.\n");
    exit(1);
}

echo "\nGotowe. Wpisz do config/mail.local.php:\n\n";
echo "    'driver' => 'oauth2',\n";
echo "    'oauth2' => [\n";
echo "        'provider'      => '" . $provider . "',\n";
echo "        'user_email'    => '<adres skrzynki wysyłającej>',\n";
echo "        'client_id'     => '" . $clientId . "',\n";
echo "        'client_secret' => '<client secret>',\n";
echo "        'refresh_token' => '" . $data['refresh_token'] . "',\n";
if ($provider === 'microsoft') {
    echo "        'host'          => 'smtp.office365.com',\n";
    echo "        'tenant_id'     => '" . $tenant . "',\n";
}
echo "    ],\n\n";
echo "Potem sprawdź wysyłkę: php scripts/test-mail.php <twoj@adres>\n";

exit(0);

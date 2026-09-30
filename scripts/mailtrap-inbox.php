<?php

declare(strict_types=1);

/**
 * Podgląd skrzynki Mailtrap z wiersza poleceń — przy sterowniku „sandbox” właśnie tam lądują
 * wiadomości aplikacji (link potwierdzający adres, link „ustaw hasło”, zaproszenia do odnowienia).
 *
 *   php scripts/mailtrap-inbox.php                      # ostatnie wiadomości w skrzynce
 *   php scripts/mailtrap-inbox.php --link               # link z najnowszej wiadomości
 *   php scripts/mailtrap-inbox.php --link=adres@x.pl    # link z najnowszej wiadomości do tego adresu
 *   php scripts/mailtrap-inbox.php --show=<id>          # treść tekstowa wskazanej wiadomości
 *
 * Wymaga bloku „mailtrap” (api_token, account_id, inbox_id) w config/mail.local.php — tego pliku
 * nie ma w repozytorium, więc token nie wycieka. Bez niego skrypt tylko podpowiada, co uzupełnić.
 *
 * Przydatne na pokazie i przy testach: zamiast klikać w przeglądarce w mailtrap.io, bierzesz link
 * prosto z terminala. Skrypt tylko czyta — niczego w skrzynce nie zmienia ani nie usuwa.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\MailConfig;

$options = getopt('', ['list', 'link::', 'show:', 'limit:']) ?: [];
$config = MailConfig::all()['mailtrap'] ?? [];

if (!is_array($config) || trim((string) ($config['api_token'] ?? '')) === '') {
    fwrite(STDERR, "Brak danych Mailtrapa w config/mail.local.php.\n\n");
    fwrite(STDERR, "Dodaj blok:\n");
    fwrite(STDERR, "    'mailtrap' => ['api_token' => '...', 'account_id' => 0, 'inbox_id' => 0],\n\n");
    fwrite(STDERR, "Token: mailtrap.io → Settings → API Tokens. Identyfikatory widać w adresie skrzynki.\n");
    exit(1);
}

$token = (string) $config['api_token'];
$accountId = (int) ($config['account_id'] ?? 0);
$inboxId = (int) ($config['inbox_id'] ?? 0);
$base = sprintf('https://mailtrap.io/api/accounts/%d/inboxes/%d', $accountId, $inboxId);

/**
 * @return array{int, string}
 */
$request = static function (string $url) use ($token): array {
    $handle = curl_init($url);
    if ($handle === false) {
        fwrite(STDERR, "Nie udało się zainicjować połączenia HTTP.\n");
        exit(1);
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Api-Token: ' . $token, 'Accept: application/json'],
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false) {
        fwrite(STDERR, 'Połączenie z Mailtrapem nie udało się: ' . $error . PHP_EOL);
        exit(1);
    }

    return [$status, (string) $body];
};

[$status, $body] = $request($base . '/messages');

if ($status === 401 || $status === 403) {
    fwrite(STDERR, "Mailtrap odrzucił token (HTTP {$status}). Sprawdź api_token w config/mail.local.php.\n");
    exit(1);
}

/** @var list<array<string, mixed>>|null $messages */
$messages = json_decode($body, true);

if (!is_array($messages)) {
    fwrite(STDERR, "Nieczytelna odpowiedź Mailtrapa (HTTP {$status}).\n");
    exit(1);
}

// Najnowsze pierwsze — kolejność z API nie jest częścią jej umowy, a „--link” ma zwracać
// link z ostatniej wiadomości.
usort($messages, static function (array $a, array $b): int {
    return strtotime((string) ($b['sent_at'] ?? '')) <=> strtotime((string) ($a['sent_at'] ?? ''));
});

// Treść wskazanej wiadomości.
if (isset($options['show'])) {
    [$status, $text] = $request($base . '/messages/' . (int) $options['show'] . '/body.txt');

    if ($status !== 200) {
        fwrite(STDERR, "Nie ma wiadomości o tym numerze (HTTP {$status}).\n");
        exit(1);
    }

    echo $text, PHP_EOL;
    exit(0);
}

// Link z najnowszej wiadomości (opcjonalnie: do wskazanego adresu).
if (isset($options['link'])) {
    $wantedEmail = strtolower(trim((string) $options['link']));

    foreach ($messages as $message) {
        $to = strtolower((string) ($message['to_email'] ?? ''));
        if ($wantedEmail !== '' && $to !== $wantedEmail) {
            continue;
        }

        [, $text] = $request($base . '/messages/' . (int) $message['id'] . '/body.txt');

        if (preg_match('#https?://\S*(?:verify-email|set-password)\.php\?token=[0-9a-f]{64}#', $text, $match) === 1) {
            echo $match[0], PHP_EOL;
            exit(0);
        }
    }

    fwrite(STDERR, $wantedEmail === ''
        ? "Żadna z ostatnich wiadomości nie zawiera linku aplikacji.\n"
        : "Brak wiadomości z linkiem dla adresu {$wantedEmail}.\n");
    exit(1);
}

// Lista wiadomości.
$limit = max(1, (int) ($options['limit'] ?? 10));
printf("Skrzynka Mailtrap #%d — wiadomości: %d (pokazuję do %d)\n\n", $inboxId, count($messages), $limit);
printf("%-12s %-19s %-32s %s\n", 'ID', 'WYSŁANO', 'DO', 'TEMAT');

foreach (array_slice($messages, 0, $limit) as $message) {
    $sentAt = (string) ($message['sent_at'] ?? '');
    printf(
        "%-12s %-19s %-32s %s\n",
        (string) ($message['id'] ?? '?'),
        $sentAt !== '' ? date('Y-m-d H:i:s', (int) strtotime($sentAt)) : '—',
        (string) ($message['to_email'] ?? '—'),
        (string) ($message['subject'] ?? '—')
    );
}

echo "\nTreść wiadomości: php scripts/mailtrap-inbox.php --show=<ID>\n";
echo "Link z najnowszej:  php scripts/mailtrap-inbox.php --link\n";

exit(0);

<?php

declare(strict_types=1);

/**
 * Narzędzie awaryjne: ustawia hasło istniejącemu kontu albo wysyła na jego adres
 * link „ustaw hasło”. Normalnie robi to administrator w panelu „Konta”, a osoba
 * wybiera hasło sama — ten skrypt jest na sytuacje bez dostępu do aplikacji
 * (np. pierwsze konto administratora po migracji z logowania kodem).
 *
 *   php scripts/set-password.php --email=admin@firma.pl --send-link
 *   php scripts/set-password.php --email=admin@firma.pl --password='Tajne haslo 2026'
 *   php scripts/set-password.php --list
 *
 * Hasło podane w wierszu polecenia trafia do historii terminala — po pierwszym
 * zalogowaniu warto je zmienić linkiem „Nie pamiętasz hasła?”.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Auth\PasswordPolicy;
use App\AuthManager;
use App\Database;
use App\UserManager;

$options = getopt('', ['email:', 'password:', 'send-link', 'list']) ?: [];
$db = Database::getInstance()->getConnection();

if (isset($options['list']) || $options === []) {
    $rows = $db->query(
        'SELECT id, email, role, password_hash IS NOT NULL AS has_password,
                email_verified_at, last_login_at, deactivated_at
         FROM users ORDER BY id'
    )->fetchAll();

    printf("%-5s %-38s %-9s %-7s %-10s %s\n", 'ID', 'E-MAIL', 'ROLA', 'HASŁO', 'ADRES', 'OSTATNIE LOGOWANIE');
    foreach ($rows as $row) {
        printf(
            "%-5d %-38s %-9s %-7s %-10s %s%s\n",
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['role'],
            $row['has_password'] ? 'jest' : 'brak',
            $row['email_verified_at'] !== null ? 'potwierdz.' : 'NIEPOTW.',
            $row['last_login_at'] ?? '—',
            $row['deactivated_at'] !== null ? '  [konto wyłączone]' : ''
        );
    }

    if ($options === []) {
        echo "\nUżycie:\n";
        echo "  php scripts/set-password.php --email=<adres> --send-link\n";
        echo "  php scripts/set-password.php --email=<adres> --password='<hasło>'\n";
    }

    exit(0);
}

$email = strtolower(trim((string) ($options['email'] ?? '')));
if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Podaj prawidłowy adres: --email=<adres>\n");
    exit(1);
}

$users = new UserManager($db);
$user = $users->findByEmail($email);

if ($user === false) {
    fwrite(STDERR, "Nie ma konta o adresie {$email}. Lista kont: php scripts/set-password.php --list\n");
    exit(1);
}

if (isset($options['send-link'])) {
    $result = (new AuthManager($db, $users))->requestPasswordSetLink($email);

    if (!$result['ok']) {
        fwrite(STDERR, 'Nie udało się wysłać wiadomości: ' . ($result['error'] ?? 'nieznany błąd') . "\n");
        exit(1);
    }

    echo "Wiadomość z linkiem „ustaw hasło” wysłana na {$email} (link ważny 2 godziny).\n";
    echo 'Sterownik poczty: ' . App\MailConfig::driver() . "\n";
    exit(0);
}

$password = (string) ($options['password'] ?? '');
if ($password === '') {
    fwrite(STDERR, "Podaj --password='<hasło>' albo --send-link.\n");
    exit(1);
}

$error = PasswordPolicy::validate($password, $email);
if ($error !== null) {
    fwrite(STDERR, 'Hasło odrzucone (' . $error . '). Wymagania: co najmniej '
        . PasswordPolicy::MIN_LENGTH . " znaków, litera oraz cyfra lub znak specjalny.\n");
    exit(1);
}

if (!$users->setPasswordHash((int) $user['id'], PasswordPolicy::hash($password))) {
    fwrite(STDERR, "Zapis hasła nie udał się.\n");
    exit(1);
}

echo "Hasło konta {$email} zostało ustawione, adres e-mail jest potwierdzony.\n";
echo "Zaloguj się na http://localhost/assistent_subscription/login.php\n";

exit(0);

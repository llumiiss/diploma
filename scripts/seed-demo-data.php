<?php

declare(strict_types=1);

/**
 * Wypełnia bazę danymi demonstracyjnymi modelu z Etapu 1, żeby dało się pokazać wszystkie
 * panele i funkcje aplikacji: certyfikaty z użytkownikami i płatnikami, wszystkie priorytety
 * i statusy płatności, archiwum z łańcuchem odnowień, zadania ToDo w każdym statusie,
 * zaproszenia z załącznikiem i historię zdarzeń.
 *
 *   php scripts/seed-demo-data.php                      # tylko na pustej bazie
 *   php scripts/seed-demo-data.php --force              # odtwórz dane demo od zera
 *   php scripts/seed-demo-data.php --owner=ja@firma.pl  # konto opiekuna części rekordów
 *   php scripts/seed-demo-data.php --password=Tajne123   # hasło kont demo (domyślnie Demo2026!haslo)
 *
 * Konta demo mają ustawione hasło i potwierdzony adres e-mail, więc można się nimi zalogować
 * od razu — to hasło jest jawne i służy wyłącznie do pokazu na danych @example.com.
 *
 * Dane demo są rozpoznawalne: płatnicy mają nazwy z listy poniżej (razem z nimi znikają ich
 * certyfikaty, użytkownicy certyfikatów, zadania, zaproszenia i historia), a konta personelu
 * demo mają adresy @example.com. Usuwa je --force albo scripts/cleanup-demo-data.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Auth\PasswordPolicy;
use App\CertificateHelper;
use App\Database;
use App\Migrations\SchemaInspector;
use App\Rbac;

$args = array_slice($argv ?? [], 1);
$force = in_array('--force', $args, true);

$ownerEmail = '';
$demoPassword = 'Demo2026!haslo';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--owner=')) {
        $ownerEmail = substr($arg, strlen('--owner='));
    }
    if (str_starts_with($arg, '--password=')) {
        $demoPassword = substr($arg, strlen('--password='));
    }
}

$passwordError = PasswordPolicy::validate($demoPassword);
if ($passwordError !== null) {
    fwrite(STDERR, 'Hasło kont demo nie spełnia wymagań (' . $passwordError . ').' . PHP_EOL);
    exit(1);
}

$db = Database::getInstance()->getConnection();

if (!SchemaInspector::tableExists($db, 'beneficiaries')) {
    fwrite(STDERR, 'Brak modelu danych z Etapu 1 — uruchom najpierw: php scripts/migrate.php' . PHP_EOL);
    exit(1);
}

// Wszystkie daty liczymy od „dzisiaj” według bazy danych, bo cron i liczniki KPI
// pytają MySQL o CURDATE().
$today = new DateTimeImmutable((string) $db->query('SELECT CURDATE()')->fetchColumn());

$day = static function (int $offset) use ($today): string {
    $date = $today->modify(sprintf('%+d days', $offset));

    if ($date === false) {
        throw new RuntimeException("Nieprawidłowe przesunięcie daty: {$offset}");
    }

    return $date->format('Y-m-d');
};

$at = static function (int $offset, string $time = '09:00:00') use ($day): string {
    return $day($offset) . ' ' . $time;
};

// ── Definicje danych demo ─────────────────────────────────────────────────────

/** Płatnicy: [nazwa, osoba kontaktowa, NIP, e-mail, telefon, adres, kod pocztowy, miasto] */
$demoPayers = [
    'novatech' => ['NovaTech Sp. z o.o.', 'Katarzyna Zielińska', '6342851974', 'faktury@novatech.example.com', '+48 32 111 22 33', 'ul. Przemysłowa 12', '40-020', 'Katowice'],
    'wisla'    => ['Grupa Wisła S.A.', 'Marek Dąbrowski', '9876543210', 'ksiegowosc@grupawisla.example.com', '+48 33 444 55 66', 'ul. Nadrzeczna 5', '43-460', 'Wisła'],
    'fundacja' => ['Fundacja Cyfrowy Śląsk', 'Anna Nowacka', '5551112223', 'biuro@cyfrowyslask.example.org', '+48 32 777 88 99', 'ul. Bankowa 14', '40-007', 'Katowice'],
];

/** Konta personelu demo — opiekunowie rekordów: [imię, nazwisko, e-mail, rola] */
$demoStaff = [
    'ewa'    => ['Ewa', 'Pawlak', 'ewa.pawlak@example.com', Rbac::MANAGER],
    'tomasz' => ['Tomasz', 'Wróbel', 'tomasz.wrobel@example.com', Rbac::OPERATOR],
    // Role stanowisk (Etap 10): szef, księgowość, informatyk i pracownik powiązany z użytkownikiem certyfikatu.
    'robert' => ['Robert', 'Szymański', 'robert.szymanski@example.com', Rbac::DIRECTOR],
    'monika' => ['Monika', 'Zielińska', 'monika.zielinska@example.com', Rbac::ACCOUNTANT],
    'jakub'  => ['Jakub', 'Kaczmarek', 'jakub.kaczmarek@example.com', Rbac::IT],
    'jan_pracownik' => ['Jan', 'Kowalski', 'jan.kowalski@example.com', Rbac::EMPLOYEE],
];

/** Konto pracownika → klucz użytkownika certyfikatu, którego certyfikaty są jego własnymi. */
$demoEmployeeLinks = ['jan_pracownik' => 'jan'];

/** Konta wyłączone przez administratora (Etap 2, D3): [imię, nazwisko, e-mail, rola, wyłączone dni temu] */
$demoInactiveStaff = [
    'adam' => ['Adam', 'Nowicki', 'adam.nowicki@example.com', Rbac::OPERATOR, 45],
];

/** Użytkownicy certyfikatów (beneficjenci): [imię, nazwisko, e-mail, telefon, płatnik] */
$demoBeneficiaries = [
    'jan'       => ['Jan', 'Kowalski', 'jan.kowalski@novatech.example.com', '+48 600 100 200', 'novatech'],
    'maria'     => ['Maria', 'Wójcik', 'maria.wojcik@novatech.example.com', '+48 600 100 201', 'novatech'],
    'piotr'     => ['Piotr', 'Lewandowski', 'piotr.lewandowski@grupawisla.example.com', '+48 600 200 300', 'wisla'],
    'michal'    => ['Michał', 'Kamiński', 'michal.kaminski@grupawisla.example.com', null, 'wisla'],
    'agnieszka' => ['Agnieszka', 'Mazur', 'agnieszka.mazur@cyfrowyslask.example.org', '+48 600 300 400', 'fundacja'],
];

/**
 * Certyfikaty i usługi. Daty to przesunięcia w dniach od dziś. owner: 'owner' = konto,
 * na które się logujesz, albo klucz z $demoStaff. Rozkład aktywnych pozycji firmowych:
 * 1 wygasła, 2 krytyczne (≤ 7 dni), 3 ostrzeżenia (≤ 30 dni), 4 spokojne.
 * Pola pominięte w definicji dostają wartości z $certificateDefaults.
 */
$certificateDefaults = [
    'serial'       => null,
    'issuer'       => null,
    'valid_from'   => null,
    'lead'         => null,
    'beneficiary'  => null,
    'previous'     => null,
    'archived'     => null,
    'last_payment' => null,
    'notes'        => null,
];
$demoCertificates = array_map(static fn (array $certificate): array => array_merge($certificateDefaults, $certificate), [
    'jan_qes' => [
        'name' => 'Certyfikat kwalifikowany — Jan Kowalski', 'type' => 'QUALIFIED_SIGNATURE',
        'serial' => '5A3F9C21B7E04D18', 'issuer' => 'Certum QCA 2017', 'valid_from' => -742, 'expiry' => -12, 'lead' => 30,
        'owner' => 'tomasz', 'beneficiary' => 'jan', 'payer' => 'novatech',
        'status' => 'expired', 'discount' => 10, 'cycle' => 'multi_year', 'payment' => 'overdue', 'last_payment' => -742,
        'notes' => 'Wygasł — odnowienie wymaga ponownej weryfikacji tożsamości.',
    ],
    'novatech_seal' => [
        'name' => 'Pieczęć kwalifikowana — NovaTech', 'type' => 'QUALIFIED_SEAL',
        'serial' => '3B77E1A09F5C2D46', 'issuer' => 'Certum QCA 2017', 'valid_from' => -361, 'expiry' => 4, 'lead' => 30,
        'owner' => 'ewa', 'beneficiary' => 'maria', 'payer' => 'novatech',
        'status' => 'renewal_in_progress', 'discount' => 0, 'cycle' => 'annual', 'payment' => 'due_soon', 'last_payment' => -361,
        'notes' => 'Odnowienie w toku — czeka na potwierdzenie płatnika.',
    ],
    'novatech_ssl' => [
        'name' => 'SSL Wildcard *.novatech.pl', 'type' => 'SSL_CERTIFICATE',
        'serial' => '0F4A6B2C9D8E7F10', 'issuer' => 'DigiCert Global G2 TLS RSA SHA256 2020 CA1', 'valid_from' => -359, 'expiry' => 6, 'lead' => 14,
        'owner' => 'owner', 'payer' => 'novatech',
        'status' => 'active', 'discount' => 5, 'cycle' => 'annual', 'payment' => 'due_soon', 'last_payment' => -359,
        'notes' => 'Certyfikat produkcyjny — odnowić przed wygaśnięciem.',
    ],
    'novatech_domain' => [
        'name' => 'Domena novatech.pl', 'type' => 'DOMAIN',
        'issuer' => 'NASK (rejestr domen .pl)', 'valid_from' => -347, 'expiry' => 18, 'lead' => 30,
        'owner' => 'tomasz', 'payer' => 'novatech',
        'status' => 'active', 'discount' => 0, 'cycle' => 'annual', 'payment' => 'due_soon', 'last_payment' => -347,
    ],
    'wisla_m365' => [
        'name' => 'Microsoft 365 Business (25 stanowisk)', 'type' => 'SAAS',
        'issuer' => 'Microsoft', 'valid_from' => -341, 'expiry' => 24,
        'owner' => 'ewa', 'payer' => 'wisla',
        'status' => 'active', 'discount' => 10, 'cycle' => 'annual', 'payment' => 'paid', 'last_payment' => -60,
    ],
    'wisla_aws' => [
        'name' => 'Wsparcie AWS Business', 'type' => 'CLOUD_SUPPORT',
        'issuer' => 'Amazon Web Services', 'valid_from' => -338, 'expiry' => 27,
        'owner' => 'owner', 'payer' => 'wisla',
        'status' => 'active', 'discount' => 5, 'cycle' => 'annual', 'payment' => 'due_soon', 'last_payment' => -335,
        'notes' => 'Do decyzji: zmiana planu wsparcia.',
    ],
    'piotr_qes' => [
        'name' => 'Certyfikat kwalifikowany — Piotr Lewandowski', 'type' => 'QUALIFIED_SIGNATURE',
        'serial' => '7C19D4E2A6B83F05', 'issuer' => 'KIR — Szafir', 'valid_from' => -685, 'expiry' => 45, 'lead' => 30,
        'owner' => 'tomasz', 'beneficiary' => 'piotr', 'payer' => 'wisla',
        'status' => 'active', 'discount' => 3, 'cycle' => 'multi_year', 'payment' => 'paid', 'last_payment' => -685,
    ],
    'wisla_ssl_ev' => [
        'name' => 'SSL EV — sklep.grupawisla.pl', 'type' => 'SSL_CERTIFICATE',
        'serial' => '4E8D2A7B1C6F9053', 'issuer' => 'Sectigo Public Server Authentication CA EV R36', 'valid_from' => -269, 'expiry' => 96, 'lead' => 21,
        'owner' => 'tomasz', 'payer' => 'wisla', 'previous' => 'wisla_ssl_ev_old',
        'status' => 'active', 'discount' => 0, 'cycle' => 'annual', 'payment' => 'paid', 'last_payment' => -272,
        'notes' => 'Odnowienie certyfikatu z poprzedniego roku.',
    ],
    'agnieszka_qes' => [
        'name' => 'Certyfikat kwalifikowany — Agnieszka Mazur', 'type' => 'QUALIFIED_SIGNATURE',
        'serial' => '2D6A9E4F7B1C8035', 'issuer' => 'EuroCert QCA', 'valid_from' => -580, 'expiry' => 150, 'lead' => 30,
        'owner' => 'ewa', 'beneficiary' => 'agnieszka', 'payer' => 'fundacja',
        'status' => 'active', 'discount' => 5, 'cycle' => 'multi_year', 'payment' => 'paid', 'last_payment' => -580,
    ],
    'fundacja_jira' => [
        'name' => 'Jira + Confluence (zespół IT)', 'type' => 'SAAS',
        'issuer' => 'Atlassian', 'expiry' => 210,
        'owner' => 'ewa', 'payer' => 'fundacja',
        'status' => 'pending', 'discount' => 0, 'cycle' => 'annual', 'payment' => 'not_applicable',
        'notes' => 'Umowa w negocjacji.',
    ],
    // Archiwum — niewidoczne w panelu; pokazuje archiwizację i łańcuch odnowień
    'wisla_ssl_ev_old' => [
        'name' => 'SSL EV — sklep.grupawisla.pl (poprzedni)', 'type' => 'SSL_CERTIFICATE',
        'serial' => '9A1B3C5D7E2F4061', 'issuer' => 'Sectigo Public Server Authentication CA EV R36', 'valid_from' => -634, 'expiry' => -269, 'lead' => 21,
        'owner' => 'tomasz', 'payer' => 'wisla', 'archived' => -268,
        'status' => 'expired', 'discount' => 3, 'cycle' => 'annual', 'payment' => 'paid', 'last_payment' => -637,
        'notes' => 'Zastąpiony nowym certyfikatem.',
    ],
    'novatech_old_domain' => [
        'name' => 'Domena stara-novatech.com.pl', 'type' => 'DOMAIN',
        'issuer' => 'NASK (rejestr domen .pl)', 'valid_from' => -400, 'expiry' => -35, 'lead' => 30,
        'owner' => 'tomasz', 'payer' => 'novatech', 'archived' => -30,
        'status' => 'expired', 'discount' => 0, 'cycle' => 'annual', 'payment' => 'not_applicable', 'last_payment' => -400,
        'notes' => 'Klient zrezygnował z domeny.',
    ],
]);

/** Zadania ToDo — każdy status: [certyfikat => [status, priorytet, przypisany, utworzono, zamknięto, notatka]] */
$demoTasks = [
    'jan_qes'             => ['in_progress', 'expired', 'tomasz', -42, null, null],
    'novatech_seal'       => ['in_progress', 'critical', 'ewa', -26, null, null],
    'novatech_ssl'        => ['todo', 'critical', 'owner', -8, null, null],
    'novatech_domain'     => ['todo', 'warning', 'tomasz', -12, null, null],
    'wisla_aws'           => ['todo', 'warning', null, -3, null, null],
    'wisla_ssl_ev_old'    => ['done', 'warning', 'tomasz', -290, -270, 'Odnowiono — nowy certyfikat SSL EV zastąpił poprzedni.'],
    'novatech_old_domain' => ['abandoned', 'expired', 'tomasz', -60, -30, 'Klient zrezygnował z domeny — zadanie porzucone.'],
];

/**
 * Zaproszenia: [certyfikat, odbiorca, status, wysłano, liczba przypomnień, ostatnie przypomnienie,
 * następne przypomnienie, wysłał, błąd].
 */
$demoInvitations = [
    ['jan_qes', 'beneficiary', 'sent', -40, 2, -12, 2, 'tomasz', null],
    ['novatech_seal', 'payer', 'responded', -20, 0, null, null, 'ewa', null],
    ['novatech_ssl', 'payer', 'failed', null, 0, null, null, 'owner', 'SMTP 421: serwer odbiorcy chwilowo niedostępny — ponów wysyłkę'],
    ['wisla_ssl_ev_old', 'payer', 'closed', -285, 1, -278, null, 'tomasz', null],
];

$attachmentName = 'instrukcja-odnowienia-certyfikatu.txt';
$attachmentContent = "Instrukcja odnowienia certyfikatu kwalifikowanego (plik demonstracyjny CertiSub)\n\n"
    . "1. Sprawdź datę ważności i numer seryjny certyfikatu w zaproszeniu.\n"
    . "2. Przygotuj dokument tożsamości — przy wygasłym certyfikacie potrzebna jest ponowna weryfikacja.\n"
    . "3. Potwierdź zamówienie u płatnika wskazanego w wiadomości.\n"
    . "4. Po wydaniu nowego certyfikatu zainstaluj go i poinformuj opiekuna.\n";

// ── Sprawdzenie stanu bazy ────────────────────────────────────────────────────

$existing = (int) $db->query('SELECT COUNT(*) FROM certificates')->fetchColumn();

if ($existing > 0 && !$force) {
    fwrite(STDERR, "Baza zawiera już {$existing} certyfikatów. Uruchom z --force, aby odtworzyć dane demo." . PHP_EOL);
    exit(1);
}

// ── Konto, na które się logujesz (opiekun części rekordów) ────────────────────

$findOwner = 'SELECT id, first_name, last_name, email, role FROM users';
if ($ownerEmail !== '') {
    $stmt = $db->prepare($findOwner . ' WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute(['email' => $ownerEmail]);
    $owner = $stmt->fetch();
} else {
    $owner = $db->query($findOwner . " WHERE role = 'ADMIN' AND email NOT LIKE '%@example.com' ORDER BY id LIMIT 1")->fetch();
    if ($owner === false) {
        $owner = $db->query($findOwner . " WHERE email NOT LIKE '%@example.com' ORDER BY id LIMIT 1")->fetch();
    }
}

if ($owner === false) {
    fwrite(STDERR, 'Brak kont w bazie — zarejestruj się w aplikacji, potem uruchom ten skrypt.' . PHP_EOL);
    exit(1);
}

$ownerId = (int) $owner['id'];

// ── Czyszczenie poprzednich danych demo ───────────────────────────────────────

$placeholders = static fn (array $values): string => implode(',', array_fill(0, count($values), '?'));
$payerNames = array_column($demoPayers, 0);

if ($force) {
    $stmt = $db->prepare('SELECT id FROM payers WHERE company_name IN (' . $placeholders($payerNames) . ')');
    $stmt->execute($payerNames);
    $oldPayerIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($oldPayerIds !== []) {
        $payerList = implode(',', $oldPayerIds);
        $certificateList = implode(',', array_map('intval', $db->query("SELECT id FROM certificates WHERE payer_id IN ({$payerList})")->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
        $beneficiaryList = implode(',', array_map('intval', $db->query("SELECT id FROM beneficiaries WHERE payer_id IN ({$payerList})")->fetchAll(PDO::FETCH_COLUMN))) ?: '0';

        $db->exec("DELETE FROM events WHERE certificate_id IN ({$certificateList}) OR beneficiary_id IN ({$beneficiaryList}) OR payer_id IN ({$payerList})");
        $db->exec("DELETE ia FROM invitation_attachments ia INNER JOIN invitations i ON i.id = ia.invitation_id WHERE i.certificate_id IN ({$certificateList})");
        $db->exec("DELETE FROM invitations WHERE certificate_id IN ({$certificateList})");
        $db->exec("DELETE FROM renewal_tasks WHERE certificate_id IN ({$certificateList})");
        $db->exec("UPDATE certificates SET previous_certificate_id = NULL WHERE id IN ({$certificateList})");
        $db->exec("DELETE FROM certificates WHERE id IN ({$certificateList})");
        $db->exec("DELETE FROM beneficiaries WHERE id IN ({$beneficiaryList})");
    }

    $db->exec("DELETE FROM events WHERE entity_type = 'user' AND entity_id IN (SELECT id FROM users WHERE email LIKE '%@example.com')");
    $db->exec("DELETE FROM users WHERE email LIKE '%@example.com'");
    $db->prepare('DELETE FROM payers WHERE company_name IN (' . $placeholders($payerNames) . ')')->execute($payerNames);

    echo "Usunięto poprzednie dane demonstracyjne.\n";
}

// ── Dziennik zdarzeń ──────────────────────────────────────────────────────────

$insertEvent = $db->prepare(
    'INSERT INTO events (entity_type, entity_id, event_type, user_id, certificate_id, beneficiary_id, payer_id, payload, occurred_at)
     VALUES (:entity_type, :entity_id, :event_type, :user_id, :certificate_id, :beneficiary_id, :payer_id, :payload, :occurred_at)'
);

/**
 * @param array<string, mixed> $context certificate_id, beneficiary_id, payer_id
 * @param array<string, mixed> $payload
 */
$logEvent = static function (
    string $entityType,
    int $entityId,
    string $eventType,
    string $occurredAt,
    ?int $userId,
    array $context,
    array $payload = []
) use ($insertEvent): void {
    $insertEvent->execute([
        'entity_type'    => $entityType,
        'entity_id'      => $entityId,
        'event_type'     => $eventType,
        'user_id'        => $userId,
        'certificate_id' => $context['certificate_id'] ?? null,
        'beneficiary_id' => $context['beneficiary_id'] ?? null,
        'payer_id'       => $context['payer_id'] ?? null,
        'payload'        => $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        'occurred_at'    => $occurredAt,
    ]);
};

// ── Płatnicy ──────────────────────────────────────────────────────────────────

$payerIds = [];
$insertPayer = $db->prepare(
    'INSERT INTO payers (company_name, contact_person, tax_id, email, phone, address_line, postal_code, city, created_at)
     VALUES (:name, :person, :tax, :email, :phone, :address, :postal, :city, :created)'
);

foreach ($demoPayers as $key => $payer) {
    $insertPayer->execute([
        'name'    => $payer[0],
        'person'  => $payer[1],
        'tax'     => $payer[2],
        'email'   => $payer[3],
        'phone'   => $payer[4],
        'address' => $payer[5],
        'postal'  => $payer[6],
        'city'    => $payer[7],
        'created' => $at(-900),
    ]);
    $payerIds[$key] = (int) $db->lastInsertId();
    $logEvent('payer', $payerIds[$key], 'created', $at(-900), $ownerId, ['payer_id' => $payerIds[$key]]);
}

// ── Konta personelu demo ──────────────────────────────────────────────────────

$staffIds = ['owner' => $ownerId];
// Konta demo są od razu gotowe do logowania: hasło ustawione, adres potwierdzony (Etap 9).
$insertUser = $db->prepare(
    'INSERT INTO users (first_name, last_name, role, email, password_hash, email_verified_at)
     VALUES (:first, :last, :role, :email, :password_hash, NOW())'
);
$demoPasswordHash = PasswordPolicy::hash($demoPassword);

foreach ($demoStaff as $key => $staff) {
    $insertUser->execute([
        'first' => $staff[0], 'last' => $staff[1], 'role' => $staff[3], 'email' => $staff[2],
        'password_hash' => $demoPasswordHash,
    ]);
    $staffIds[$key] = (int) $db->lastInsertId();
    $logEvent('user', $staffIds[$key], 'account_created', $at(-1000), $ownerId, [], [
        'name' => $staff[0] . ' ' . $staff[1], 'email' => $staff[2], 'role' => $staff[3],
    ]);
}

$deactivateUser = $db->prepare('UPDATE users SET deactivated_at = :at WHERE id = :id');
foreach ($demoInactiveStaff as $staff) {
    $insertUser->execute([
        'first' => $staff[0], 'last' => $staff[1], 'role' => $staff[3], 'email' => $staff[2],
        'password_hash' => $demoPasswordHash,
    ]);
    $inactiveId = (int) $db->lastInsertId();
    $deactivateUser->execute(['at' => $at(-$staff[4], '17:00:00'), 'id' => $inactiveId]);
    $logEvent('user', $inactiveId, 'account_created', $at(-1000), $ownerId, [], [
        'name' => $staff[0] . ' ' . $staff[1], 'email' => $staff[2], 'role' => $staff[3],
    ]);
    $logEvent('user', $inactiveId, 'account_deactivated', $at(-$staff[4], '17:00:00'), $ownerId, [], [
        'name' => $staff[0] . ' ' . $staff[1], 'certificates' => 0, 'open_tasks' => 0,
    ]);
}

// ── Użytkownicy certyfikatów ──────────────────────────────────────────────────

$beneficiaryIds = [];
$insertBeneficiary = $db->prepare(
    'INSERT INTO beneficiaries (first_name, last_name, email, phone, payer_id, created_at)
     VALUES (:first, :last, :email, :phone, :payer_id, :created)'
);

foreach ($demoBeneficiaries as $key => $person) {
    $insertBeneficiary->execute([
        'first'    => $person[0],
        'last'     => $person[1],
        'email'    => $person[2],
        'phone'    => $person[3],
        'payer_id' => $payerIds[$person[4]],
        'created'  => $at(-800),
    ]);
    $beneficiaryIds[$key] = (int) $db->lastInsertId();
    $logEvent('beneficiary', $beneficiaryIds[$key], 'created', $at(-800), $ownerId, [
        'beneficiary_id' => $beneficiaryIds[$key],
        'payer_id'       => $payerIds[$person[4]],
    ]);
}

$linkEmployee = $db->prepare('UPDATE users SET beneficiary_id = :beneficiary_id WHERE id = :id');
foreach ($demoEmployeeLinks as $staffKey => $personKey) {
    $linkEmployee->execute(['beneficiary_id' => $beneficiaryIds[$personKey], 'id' => $staffIds[$staffKey]]);
}

// ── Certyfikaty i usługi ──────────────────────────────────────────────────────

$certificateIds = [];
$certificateContext = [];
$insertCertificate = $db->prepare(
    'INSERT INTO certificates
        (name, certificate_type, serial_number, issuer, valid_from, expiry_date, renewal_lead_days,
         user_id, beneficiary_id, payer_id, status, discount_percent, billing_cycle, payment_status,
         last_payment_date, auto_renew, notes, archived_at, created_at)
     VALUES
        (:name, :type, :serial, :issuer, :valid_from, :expiry, :lead,
         :user_id, :beneficiary_id, :payer_id, :status, :discount, :cycle, :payment,
         :last_payment, :auto_renew, :notes, :archived_at, :created_at)'
);

foreach ($demoCertificates as $key => $cert) {
    $createdOffset = (int) ($cert['valid_from'] ?? -30) - 7;
    $userId = $staffIds[$cert['owner']];
    $beneficiaryId = $cert['beneficiary'] !== null ? $beneficiaryIds[$cert['beneficiary']] : null;
    $payerId = $payerIds[$cert['payer']];

    $insertCertificate->execute([
        'name'           => $cert['name'],
        'type'           => $cert['type'],
        'serial'         => $cert['serial'],
        'issuer'         => $cert['issuer'],
        'valid_from'     => $cert['valid_from'] !== null ? $day($cert['valid_from']) : null,
        'expiry'         => $day($cert['expiry']),
        'lead'           => $cert['lead'],
        'user_id'        => $userId,
        'beneficiary_id' => $beneficiaryId,
        'payer_id'       => $payerId,
        'status'         => $cert['status'],
        'discount'       => $cert['discount'],
        'cycle'          => $cert['cycle'],
        'payment'        => $cert['payment'],
        'last_payment'   => $cert['last_payment'] !== null ? $day($cert['last_payment']) : null,
        'auto_renew'     => $cert['status'] === 'pending' ? 0 : 1,
        'notes'          => $cert['notes'],
        'archived_at'    => $cert['archived'] !== null ? $at($cert['archived'], '16:00:00') : null,
        'created_at'     => $at($createdOffset),
    ]);

    $certificateIds[$key] = (int) $db->lastInsertId();
    $certificateContext[$key] = [
        'certificate_id' => $certificateIds[$key],
        'beneficiary_id' => $beneficiaryId,
        'payer_id'       => $payerId,
    ];

    $logEvent('certificate', $certificateIds[$key], 'created', $at($createdOffset), $userId, $certificateContext[$key], [
        'name' => $cert['name'],
        'type' => $cert['type'],
    ]);

    if ($cert['archived'] !== null) {
        $logEvent('certificate', $certificateIds[$key], 'archived', $at($cert['archived'], '16:00:00'), $userId, $certificateContext[$key], [
            'reason' => $cert['notes'],
        ]);
    }
}

// Łańcuch odnowień: nowy certyfikat wskazuje poprzedni.
$linkPrevious = $db->prepare('UPDATE certificates SET previous_certificate_id = :previous WHERE id = :id');

foreach ($demoCertificates as $key => $cert) {
    if ($cert['previous'] === null) {
        continue;
    }

    $previousKey = $cert['previous'];
    $linkPrevious->execute(['previous' => $certificateIds[$previousKey], 'id' => $certificateIds[$key]]);
    $logEvent('certificate', $certificateIds[$previousKey], 'renewed', $at((int) $cert['valid_from'], '10:00:00'), $staffIds[$cert['owner']], $certificateContext[$previousKey], [
        'new_certificate_id' => $certificateIds[$key],
    ]);
}

// ── Zadania ToDo ──────────────────────────────────────────────────────────────

$taskIds = [];
$insertTask = $db->prepare(
    'INSERT INTO renewal_tasks (certificate_id, assigned_user_id, status, priority, due_date, resolution_note, closed_at, created_at)
     VALUES (:certificate_id, :user_id, :status, :priority, :due_date, :note, :closed_at, :created_at)'
);

foreach ($demoTasks as $certKey => [$status, $priority, $assignee, $createdOffset, $closedOffset, $note]) {
    $assignedId = $assignee !== null ? $staffIds[$assignee] : null;

    $insertTask->execute([
        'certificate_id' => $certificateIds[$certKey],
        'user_id'        => $assignedId,
        'status'         => $status,
        'priority'       => $priority,
        'due_date'       => $day($demoCertificates[$certKey]['expiry']),
        'note'           => $note,
        'closed_at'      => $closedOffset !== null ? $at($closedOffset, '15:00:00') : null,
        'created_at'     => $at($createdOffset, '06:00:00'),
    ]);
    $taskIds[$certKey] = (int) $db->lastInsertId();

    $context = $certificateContext[$certKey];
    $logEvent('renewal_task', $taskIds[$certKey], 'task_opened', $at($createdOffset, '06:00:00'), null, $context, ['priority' => $priority]);

    if ($status === 'in_progress' || $status === 'done') {
        $logEvent('renewal_task', $taskIds[$certKey], 'task_status_changed', $at($createdOffset + 1, '10:00:00'), $assignedId, $context, [
            'from' => 'todo',
            'to'   => 'in_progress',
        ]);
    }

    if ($closedOffset !== null) {
        $logEvent('renewal_task', $taskIds[$certKey], 'task_closed', $at($closedOffset, '15:00:00'), $assignedId, $context, [
            'status' => $status,
            'note'   => $note,
        ]);
    }
}

// ── Załącznik instrukcji (plik poza zasięgiem przeglądarki) ───────────────────

$attachmentDir = dirname(__DIR__) . '/storage/attachments';
if (!is_dir($attachmentDir) && !mkdir($attachmentDir, 0755, true) && !is_dir($attachmentDir)) {
    throw new RuntimeException("Nie można utworzyć katalogu {$attachmentDir}");
}

$sha256 = hash('sha256', $attachmentContent);
$stmt = $db->prepare('SELECT id, stored_name FROM attachments WHERE sha256 = :sha LIMIT 1');
$stmt->execute(['sha' => $sha256]);
$attachment = $stmt->fetch();

if ($attachment === false) {
    $storedName = bin2hex(random_bytes(16)) . '.txt';
    $db->prepare(
        'INSERT INTO attachments (original_name, stored_name, mime_type, size_bytes, sha256, uploaded_by_user_id)
         VALUES (:original, :stored, :mime, :size, :sha, :user_id)'
    )->execute([
        'original' => $attachmentName,
        'stored'   => $storedName,
        'mime'     => 'text/plain',
        'size'     => strlen($attachmentContent),
        'sha'      => $sha256,
        'user_id'  => $ownerId,
    ]);
    $attachmentId = (int) $db->lastInsertId();
} else {
    $attachmentId = (int) $attachment['id'];
    $storedName = (string) $attachment['stored_name'];
}

if (!is_file($attachmentDir . '/' . $storedName)) {
    file_put_contents($attachmentDir . '/' . $storedName, $attachmentContent);
}

$db->prepare(
    "INSERT IGNORE INTO email_template_attachments (template_id, attachment_id)
     SELECT id, :attachment_id FROM email_templates WHERE code = 'renewal_invitation'"
)->execute(['attachment_id' => $attachmentId]);

// ── Zaproszenia z treścią wygenerowaną z szablonu ─────────────────────────────

$template = $db->query("SELECT id, subject, body_html FROM email_templates WHERE code = 'renewal_invitation' AND locale = 'pl' LIMIT 1")->fetch();

if ($template === false) {
    fwrite(STDERR, 'Brak domyślnego szablonu renewal_invitation — uruchom: php scripts/migrate.php' . PHP_EOL);
    exit(1);
}

$insertInvitation = $db->prepare(
    'INSERT INTO invitations
        (certificate_id, renewal_task_id, template_id, recipient_type, recipient_email, recipient_name, subject, body_html,
         status, sent_at, last_error, reminder_count, last_reminder_at, next_reminder_at, sent_by_user_id, created_at)
     VALUES
        (:certificate_id, :task_id, :template_id, :recipient_type, :recipient_email, :recipient_name, :subject, :body_html,
         :status, :sent_at, :last_error, :reminder_count, :last_reminder_at, :next_reminder_at, :sent_by, :created_at)'
);
$attachToInvitation = $db->prepare('INSERT INTO invitation_attachments (invitation_id, attachment_id) VALUES (:invitation_id, :attachment_id)');

foreach ($demoInvitations as [$certKey, $recipientType, $status, $sentOffset, $reminders, $lastReminderOffset, $nextReminderOffset, $sender, $error]) {
    $cert = $demoCertificates[$certKey];
    $payer = $demoPayers[$cert['payer']];

    if ($recipientType === 'beneficiary') {
        $person = $demoBeneficiaries[$cert['beneficiary']];
        [$firstName, $lastName, $email] = [$person[0], $person[1], (string) $person[2]];
    } else {
        [$firstName, $lastName] = array_pad(explode(' ', $payer[1], 2), 2, '');
        $email = (string) $payer[3];
    }

    $referenceOffset = $sentOffset ?? -1;
    $values = [
        '{imie}'               => $firstName,
        '{nazwisko}'           => $lastName,
        '{typ_certyfikatu}'    => CertificateHelper::typeLabel($cert['type']),
        '{numer_seryjny}'      => $cert['serial'] ?? '—',
        '{data_waznosci}'      => (new DateTimeImmutable($day($cert['expiry'])))->format('d.m.Y'),
        '{dni_do_wygasniecia}' => (string) ($cert['expiry'] - $referenceOffset),
        '{platnik}'            => $payer[0],
    ];
    $escaped = array_map(static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $values);

    $insertInvitation->execute([
        'certificate_id'   => $certificateIds[$certKey],
        'task_id'          => $taskIds[$certKey] ?? null,
        'template_id'      => (int) $template['id'],
        'recipient_type'   => $recipientType,
        'recipient_email'  => $email,
        'recipient_name'   => trim($firstName . ' ' . $lastName),
        'subject'          => strtr((string) $template['subject'], $values),
        'body_html'        => strtr((string) $template['body_html'], $escaped),
        'status'           => $status,
        'sent_at'          => $sentOffset !== null ? $at($sentOffset, '08:30:00') : null,
        'last_error'       => $error,
        'reminder_count'   => $reminders,
        'last_reminder_at' => $lastReminderOffset !== null ? $at($lastReminderOffset, '08:30:00') : null,
        'next_reminder_at' => $nextReminderOffset !== null ? $at($nextReminderOffset, '08:30:00') : null,
        'sent_by'          => $staffIds[$sender],
        'created_at'       => $at($referenceOffset, '08:00:00'),
    ]);
    $invitationId = (int) $db->lastInsertId();
    $context = $certificateContext[$certKey];

    if ($status === 'failed') {
        $logEvent('invitation', $invitationId, 'invitation_failed', $at(-1, '08:30:00'), $staffIds[$sender], $context, ['error' => $error]);
        continue;
    }

    $attachToInvitation->execute(['invitation_id' => $invitationId, 'attachment_id' => $attachmentId]);
    $logEvent('invitation', $invitationId, 'invitation_sent', $at((int) $sentOffset, '08:30:00'), $staffIds[$sender], $context, [
        'recipient' => $email,
        'template'  => 'renewal_invitation',
    ]);

    for ($i = 1; $i <= $reminders; ++$i) {
        $reminderOffset = $lastReminderOffset !== null
            ? (int) round($sentOffset + ($lastReminderOffset - $sentOffset) * $i / $reminders)
            : (int) $sentOffset + 7 * $i;
        $logEvent('invitation', $invitationId, 'reminder_sent', $at($reminderOffset, '08:30:00'), null, $context, ['reminder' => $i]);
    }

    if ($status === 'responded') {
        $logEvent('invitation', $invitationId, 'invitation_responded', $at((int) $sentOffset + 5, '12:00:00'), null, $context);
    } elseif ($status === 'closed') {
        $logEvent('invitation', $invitationId, 'invitation_closed', $at((int) $sentOffset + 15, '12:00:00'), $staffIds[$sender], $context);
    }
}

// ── Podsumowanie ──────────────────────────────────────────────────────────────

$count = static fn (string $sql): int => (int) $db->query($sql)->fetchColumn();
$grouped = static function (string $sql) use ($db): string {
    $parts = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $value) {
        $parts[] = "{$key}={$value}";
    }

    return implode(', ', $parts);
};

echo "\nDane demonstracyjne gotowe.\n";
printf("  Płatnicy:                         %d\n", count($payerIds));
printf("  Konta personelu demo:             %d aktywne + %d wyłączone (@example.com)\n", count($demoStaff), count($demoInactiveStaff));
printf("  Hasło kont demo:                  %s\n", $demoPassword);
printf("  Użytkownicy certyfikatów:         %d\n", count($beneficiaryIds));
printf(
    "  Certyfikaty:                      %d aktywnych + %d w archiwum\n",
    $count('SELECT COUNT(*) FROM certificates WHERE archived_at IS NULL'),
    $count('SELECT COUNT(*) FROM certificates WHERE archived_at IS NOT NULL')
);
printf("  Zadania ToDo:                     %s\n", $grouped('SELECT status, COUNT(*) FROM renewal_tasks GROUP BY status ORDER BY FIELD(status, "todo", "in_progress", "done", "abandoned")'));
printf("  Zaproszenia:                      %s\n", $grouped('SELECT status, COUNT(*) FROM invitations GROUP BY status ORDER BY status'));
printf("  Zdarzenia w historii:             %d\n", $count('SELECT COUNT(*) FROM events'));
printf(
    "\n  Twoje konto: %s %s <%s>, rola %s — opiekun %d aktywnych certyfikatów\n",
    (string) $owner['first_name'],
    (string) $owner['last_name'],
    (string) $owner['email'],
    (string) $owner['role'],
    $count("SELECT COUNT(*) FROM certificates WHERE archived_at IS NULL AND user_id = {$ownerId}")
);
echo "\nUsuwanie: php scripts/seed-demo-data.php --force (odtworzenie) lub php scripts/cleanup-demo-data.php\n";

exit(0);

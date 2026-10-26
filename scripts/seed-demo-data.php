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
use App\Intake\CompanyRecord;
use App\Intake\CompanyRegistry;
use App\Migrations\SchemaInspector;
use App\Rbac;
use App\Service\Actor;
use App\Service\RegistrationIntakeService;

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

/** Ostatnie logowanie kont demo (przesunięcie w dniach od dziś) — kolumna na liście kont. */
$demoLastLogins = [
    'ewa' => 0, 'tomasz' => 0, 'robert' => -2, 'monika' => -1, 'jakub' => -5, 'jan_pracownik' => -11,
];

/** Konta wyłączone przez administratora (Etap 2, D3): [imię, nazwisko, e-mail, rola, wyłączone dni temu] */
$demoInactiveStaff = [
    'adam' => ['Adam', 'Nowicki', 'adam.nowicki@example.com', Rbac::OPERATOR, 45],
];

/**
 * Użytkownicy certyfikatów (beneficjenci): [imię, nazwisko, e-mail, telefon, płatnik, notatka,
 * konto, które dodało rekord].
 */
$demoBeneficiaries = [
    'jan'       => ['Jan', 'Kowalski', 'jan.kowalski@novatech.example.com', '+48 600 100 200', 'novatech', 'Prezes zarządu — podpisuje umowy i sprawozdania finansowe. Odnowienia uzgadniać z księgowością NovaTechu.', 'tomasz'],
    'maria'     => ['Maria', 'Wójcik', 'maria.wojcik@novatech.example.com', '+48 600 100 201', 'novatech', 'Dział handlowy. Pieczęć kwalifikowana firmy jest na jej stanowisku — przy odnowieniu potrzebne pełnomocnictwo.', 'ewa'],
    'piotr'     => ['Piotr', 'Lewandowski', 'piotr.lewandowski@grupawisla.example.com', '+48 600 200 300', 'wisla', 'Dyrektor finansowy. Certyfikat dwuletni, odnowienie planowane na półtora miesiąca przed terminem.', 'tomasz'],
    'michal'    => ['Michał', 'Kamiński', 'michal.kaminski@grupawisla.example.com', '+48 600 200 301', 'wisla', 'Administrator systemów — odbiorca spraw technicznych (SSL, domeny) po stronie Grupy Wisła.', 'tomasz'],
    'agnieszka' => ['Agnieszka', 'Mazur', 'agnieszka.mazur@cyfrowyslask.example.org', '+48 600 300 400', 'fundacja', 'Prezes fundacji. Faktury wystawiane na fundację, korespondencja na adres prywatny odrzucana.', 'ewa'],
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

/**
 * Ustawienia procesu odnowień zapisane w bazie. Wartości są takie, jak domyślne w kodzie — wpis
 * w tabeli pokazuje na ekranie „Ustawienia”, kto i kiedy je zatwierdził, a nie zmienia rozkładu
 * priorytetów, pod który dobrane są daty certyfikatów wyżej.
 */
$demoSettings = [
    'renewal.warning_days'    => 30,
    'renewal.critical_days'   => 7,
    'reminders.interval_days' => 7,
    'reminders.max_count'     => 3,
];

/**
 * Powiadomienia wewnętrzne (Etap 10). Pokrywają wszystkie cztery rodzaje, wątek z odpowiedzią,
 * prośbę załatwioną i niezałatwioną oraz wiadomość w archiwum. Przesunięcia dni jak wyżej:
 * `created`, `read`, `resolved` i `archived` liczone od dziś.
 */
$notificationDefaults = [
    'from'     => null,
    'related'  => null,
    'read'     => null,
    'resolved' => null,
    'archived' => null,
    'reply'    => null,
];
$demoNotifications = array_map(static fn (array $notification): array => array_merge($notificationDefaults, $notification), [
    [
        'from' => 'ewa', 'to' => 'owner', 'type' => 'message',
        'subject' => 'Pieczęć kwalifikowana NovaTech — czekam na płatnika',
        'body' => "Dzień dobry,\n\nzaproszenie do odnowienia pieczęci poszło do NovaTechu dwadzieścia dni temu. Płatnik odpowiedział, ale nie potwierdził jeszcze zamówienia. Zostawiam zadanie w toku i pilnuję terminu — pieczęć wygasa za cztery dni.\n\nEwa",
        'related' => ['certificate', 'novatech_seal'], 'created' => -3, 'read' => -3,
        'reply' => [
            'from' => 'owner',
            'body' => 'Dziękuję. Jeśli do poniedziałku nie potwierdzą, wyślij przypomnienie i dopisz mnie do zadania.',
            'created' => -2, 'read' => -2,
        ],
    ],
    [
        'from' => 'tomasz', 'to' => 'owner', 'type' => 'request',
        'subject' => 'Prośba o zgodę na rabat 10% dla Grupy Wisła',
        'body' => 'Grupa Wisła odnawia Microsoft 365 na 25 stanowisk i prosi o utrzymanie rabatu 10% na kolejny rok. Proszę o zgodę albo o wskazanie innej stawki.',
        'related' => ['certificate', 'wisla_m365'], 'created' => -1,
    ],
    [
        'from' => 'monika', 'to' => 'owner', 'type' => 'request',
        'subject' => 'Prośba o fakturę zbiorczą dla Fundacji Cyfrowy Śląsk',
        'body' => 'Fundacja prosi o jedną fakturę za certyfikat kwalifikowany i za Jirę, zamiast dwóch osobnych. Czy mogę tak wystawić?',
        'related' => ['payer', 'fundacja'], 'created' => -6, 'read' => -6, 'resolved' => -5,
    ],
    [
        'from' => null, 'to' => 'owner', 'type' => 'error',
        'subject' => 'Nie udało się wysłać zaproszenia — SSL Wildcard *.novatech.pl',
        'body' => 'Serwer odbiorcy odrzucił wiadomość: SMTP 421 — serwer odbiorcy chwilowo niedostępny. Zaproszenie czeka w stanie „błąd wysyłki”, wysyłkę można ponowić z ekranu certyfikatu.',
        'related' => ['certificate', 'novatech_ssl'], 'created' => -1,
    ],
    [
        'from' => null, 'to' => 'tomasz', 'type' => 'system',
        'subject' => 'Skan odnowień: 2 nowe zadania o priorytecie krytycznym',
        'body' => 'Nocny skan oznaczył dwa certyfikaty jako krytyczne (wygasają w ciągu siedmiu dni) i utworzył dla nich zadania na liście ToDo.',
        'related' => null, 'created' => -8, 'read' => -8,
    ],
    [
        'from' => 'robert', 'to' => 'owner', 'type' => 'message',
        'subject' => 'Budżet na odnowienia w 2027 roku',
        'body' => 'Potrzebuję do końca miesiąca zestawienia kosztów odnowień z podziałem na firmy — najlepiej z raportu „Płatnicy”. Wystarczy eksport do CSV.',
        'related' => null, 'created' => -14, 'read' => -13, 'archived' => -10,
    ],
]);

/**
 * Odpowiedzi rejestru dla danych demo — ten sam układ pól, co Biała Lista podatników VAT, ale bez
 * sieci, żeby skrypt działał offline i zawsze tak samo. Dane Orlenu są prawdziwe (tak odpowiada
 * rejestr na żywo), pozostałe opisują firmy demonstracyjne.
 */
$demoCompanyRecords = [
    '6342851974' => [
        'nip' => '6342851974', 'official_name' => 'NOVATECH SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ',
        'name' => 'NovaTech Spółka z Ograniczoną Odpowiedzialnością', 'status_vat' => 'Czynny',
        'regon' => '243617285', 'krs' => '0000412765', 'address_line' => 'ul. Przemysłowa 12',
        'postal_code' => '40-020', 'city' => 'Katowice', 'raw_address' => 'ul. Przemysłowa 12, 40-020 Katowice',
        'registration_date' => '2012-03-14',
    ],
    '9876543210' => [
        'nip' => '9876543210', 'official_name' => 'GRUPA WISŁA SPÓŁKA AKCYJNA',
        'name' => 'Grupa Wisła Spółka Akcyjna', 'status_vat' => 'Czynny',
        'regon' => '070123456', 'krs' => '0000198734', 'address_line' => 'ul. Nadrzeczna 5',
        'postal_code' => '43-460', 'city' => 'Wisła', 'raw_address' => 'ul. Nadrzeczna 5, 43-460 Wisła',
        'registration_date' => '2004-06-01',
    ],
    '5551112223' => [
        'nip' => '5551112223', 'official_name' => 'FUNDACJA CYFROWY ŚLĄSK',
        'name' => 'Fundacja Cyfrowy Śląsk', 'status_vat' => 'Zwolniony',
        'regon' => '365214789', 'krs' => '0000612345', 'address_line' => 'ul. Bankowa 14',
        'postal_code' => '40-007', 'city' => 'Katowice', 'raw_address' => 'ul. Bankowa 14, 40-007 Katowice',
        'registration_date' => '2016-09-22',
    ],
    '7740001454' => [
        'nip' => '7740001454', 'official_name' => 'ORLEN SPÓŁKA AKCYJNA',
        'name' => 'Orlen Spółka Akcyjna', 'status_vat' => 'Czynny',
        'regon' => '610188201', 'krs' => '0000028860', 'address_line' => 'Chemików 7',
        'postal_code' => '09-411', 'city' => 'Płock', 'raw_address' => 'Chemików 7, 09-411 Płock',
        'registration_date' => '1993-07-01',
    ],
];

/**
 * Wnioski o certyfikat z poczty — po jednym z każdej drogi wejścia i w każdym stanie przeglądu.
 * Treść listów ma układ, który czyta `RegistrationExtractor` (etykiety „Imię:”, „NIP:” i dalsze),
 * a same wnioski powstają przez `RegistrationIntakeService::ingest()`, żeby zapisane wyliczenia
 * (`extracted`, `form`, `company_lookup`, `warnings`) miały dokładnie taki układ jak w aplikacji.
 */
$registrationDefaults = [
    'assigned' => null,
    'reviewer' => null,
    'reviewed' => null,
    'reason'   => null,
    'result'   => null,
];
$demoRegistrations = array_map(static fn (array $registration): array => array_merge($registrationDefaults, $registration), [
    'novatech_nowa_osoba' => [
        'source' => 'imap', 'offset' => -2, 'status' => 'pending', 'assigned' => 'tomasz',
        'from' => ['Łukasz Adamczyk', 'lukasz.adamczyk@novatech.example.com'],
        'subject' => 'Wniosek o certyfikat kwalifikowany - Łukasz Adamczyk',
        'id' => 'wniosek-novatech-014',
        'body' => "Dzień dobry,\n\nproszę o wystawienie certyfikatu kwalifikowanego do podpisu elektronicznego dla nowego pracownika.\n\nDane wnioskodawcy:\nImię: Łukasz\nNazwisko: Adamczyk\nE-mail: lukasz.adamczyk@novatech.example.com\nTelefon: 601 455 788\n\nDane firmy:\nNIP: 634-285-19-74\n\nDane certyfikatu:\nRodzaj certyfikatu: Certyfikat kwalifikowany\nWystawca: Certum\nOkres ważności: 2 lata\nRabat: -10%\nUwagi: Odbiór w siedzibie firmy\n\nPozdrawiam\nŁukasz Adamczyk\n",
    ],
    'orlen_nowa_firma' => [
        'source' => 'upload', 'offset' => -4, 'status' => 'pending', 'assigned' => null,
        'from' => ['Anna Nowak', 'anna.nowak@orlen.example.com'],
        'subject' => 'Wniosek o certyfikat kwalifikowany - Anna Nowak',
        'id' => 'wniosek-orlen-001',
        'body' => "Dzień dobry,\n\nproszę o rejestrację certyfikatu kwalifikowanego do podpisu elektronicznego.\n\nDane wnioskodawcy:\nImię: Anna\nNazwisko: Nowak\nE-mail: anna.nowak@orlen.example.com\nTelefon: 601 202 303\n\nDane firmy:\nNIP: 774-000-14-54\n\nDane certyfikatu:\nRodzaj certyfikatu: Certyfikat kwalifikowany\nWystawca: Certum\nOkres ważności: 2 lata\nRabat: -5%\nUwagi: Odbiór osobisty w siedzibie\n\nPozdrawiam\nAnna Nowak\n",
    ],
    'wisla_zatwierdzony' => [
        'source' => 'webhook', 'offset' => -690, 'status' => 'approved', 'reviewer' => 'tomasz',
        'reviewed' => -686, 'result' => ['wisla', 'piotr', 'piotr_qes'],
        'from' => ['Piotr Lewandowski', 'piotr.lewandowski@grupawisla.example.com'],
        'subject' => 'Wniosek o certyfikat kwalifikowany - Piotr Lewandowski',
        'id' => 'wniosek-wisla-002',
        'body' => "Dzień dobry,\n\nproszę o certyfikat kwalifikowany do podpisywania umów.\n\nDane wnioskodawcy:\nImię: Piotr\nNazwisko: Lewandowski\nE-mail: piotr.lewandowski@grupawisla.example.com\nTelefon: 600 200 300\n\nDane firmy:\nNIP: 987-654-32-10\n\nDane certyfikatu:\nRodzaj certyfikatu: Certyfikat kwalifikowany\nWystawca: KIR\nOkres ważności: 2 lata\nRabat: -3%\n\nPozdrawiam\nPiotr Lewandowski\n",
    ],
    'brak_nipu_odrzucony' => [
        'source' => 'upload', 'offset' => -9, 'status' => 'rejected', 'reviewer' => 'ewa',
        'reviewed' => -7,
        'reason' => 'Wniosek bez NIP-u i bez nazwy firmy — poprosiliśmy wnioskodawcę o uzupełnienie danych, nowy wniosek założymy po odpowiedzi.',
        'from' => ['Krzysztof Malinowski', 'k.malinowski@example.net'],
        'subject' => 'Wniosek o certyfikat kwalifikowany',
        'id' => 'wniosek-brak-nipu-007',
        'body' => "Dzień dobry,\n\nchciałbym zamówić certyfikat kwalifikowany do podpisu elektronicznego.\n\nDane wnioskodawcy:\nImię: Krzysztof\nNazwisko: Malinowski\nE-mail: k.malinowski@example.net\n\nDane certyfikatu:\nRodzaj certyfikatu: Certyfikat kwalifikowany\n\nPozdrawiam\nKrzysztof Malinowski\n",
    ],
]);

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

    // Wnioski z poczty poznajemy po identyfikatorze wiadomości, powiadomienia po przedrostku klucza
    // paczki; powiadomienia o wnioskach tworzy sama usługa, więc idą po powiązanym wniosku.
    $demoDrafts = $db->query("SELECT id, raw_file FROM registration_drafts WHERE message_id LIKE '%@demo.certisub.example'")->fetchAll();
    $draftIdList = implode(',', array_map(static fn (array $row): int => (int) $row['id'], $demoDrafts)) ?: '0';

    $db->exec("DELETE FROM notifications WHERE batch_key LIKE 'demo%' OR (related_type = 'registration' AND related_id IN ({$draftIdList}))");
    $db->exec("DELETE FROM registration_drafts WHERE id IN ({$draftIdList})");

    foreach ($demoDrafts as $draft) {
        $rawFile = (string) ($draft['raw_file'] ?? '');
        if ($rawFile !== '' && is_file(dirname(__DIR__) . '/storage/intake/' . $rawFile)) {
            unlink(dirname(__DIR__) . '/storage/intake/' . $rawFile);
        }
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
    'INSERT INTO payers (company_name, contact_person, tax_id, email, phone, address_line, postal_code, city,
         created_by_user_id, created_at)
     VALUES (:name, :person, :tax, :email, :phone, :address, :postal, :city, :created_by, :created)'
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
        // Firmy zakłada konto, na które się logujesz — konta personelu demo powstają dopiero niżej.
        'created_by' => $ownerId,
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

// Ostatnie logowanie — inaczej lista kont pokazuje przy każdym „nigdy”.
$markLogin = $db->prepare('UPDATE users SET last_login_at = :at WHERE id = :id');
foreach ($demoLastLogins as $staffKey => $offset) {
    $markLogin->execute(['at' => $at($offset, '08:12:00'), 'id' => $staffIds[$staffKey]]);
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
    'INSERT INTO beneficiaries (first_name, last_name, email, phone, payer_id, notes, created_by_user_id, created_at)
     VALUES (:first, :last, :email, :phone, :payer_id, :notes, :created_by, :created)'
);

foreach ($demoBeneficiaries as $key => $person) {
    $insertBeneficiary->execute([
        'first'      => $person[0],
        'last'       => $person[1],
        'email'      => $person[2],
        'phone'      => $person[3],
        'payer_id'   => $payerIds[$person[4]],
        'notes'      => $person[5],
        'created_by' => $staffIds[$person[6]],
        'created'    => $at(-800),
    ]);
    $beneficiaryIds[$key] = (int) $db->lastInsertId();
    $logEvent('beneficiary', $beneficiaryIds[$key], 'created', $at(-800), $ownerId, [
        'beneficiary_id' => $beneficiaryIds[$key],
        'payer_id'       => $payerIds[$person[4]],
    ]);
}

// Filtr firm z ustawień konta (Etap 10): księgowość demo prowadzi tylko jedną firmę. Zawęża listy
// i wskaźniki, nie zmienia uprawnień — dlatego warto to pokazać na osobnym koncie.
$db->prepare('UPDATE users SET company_filter = :filter WHERE id = :id')->execute([
    'filter' => json_encode(['mode' => 'one', 'ids' => [$payerIds['fundacja']]], JSON_THROW_ON_ERROR),
    'id'     => $staffIds['monika'],
]);

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

// ── Ustawienia procesu odnowień ───────────────────────────────────────────────

$insertSetting = $db->prepare(
    'INSERT INTO settings (setting_key, setting_value, updated_by_user_id, updated_at)
     VALUES (:key, :value, :user_id, :at)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
        updated_by_user_id = VALUES(updated_by_user_id), updated_at = VALUES(updated_at)'
);

foreach ($demoSettings as $settingKey => $settingValue) {
    $insertSetting->execute([
        'key'     => $settingKey,
        'value'   => (string) $settingValue,
        'user_id' => $ownerId,
        'at'      => $at(-21, '11:20:00'),
    ]);
}

// ── Powiadomienia wewnętrzne ──────────────────────────────────────────────────

$insertNotification = $db->prepare(
    'INSERT INTO notifications
        (batch_key, thread_id, parent_id, sender_user_id, recipient_user_id, type, subject, body,
         related_type, related_id, read_at, resolved_at, archived_at, created_at)
     VALUES
        (:batch, :thread, :parent, :sender, :recipient, :type, :subject, :body,
         :related_type, :related_id, :read_at, :resolved_at, :archived_at, :created_at)'
);
$closeThread = $db->prepare('UPDATE notifications SET thread_id = id WHERE id = :id');

// Klucz paczki z przedrostkiem „demo” — po nim poznaje je ponowne uruchomienie z --force.
$demoBatchKey = static fn (): string => 'demo' . bin2hex(random_bytes(6));

/** Zamienia parę [rodzaj, klucz] na identyfikator rekordu, do którego odsyła powiadomienie. */
$relatedId = static function (?array $related) use ($certificateIds, $payerIds, $beneficiaryIds): array {
    if ($related === null) {
        return [null, null];
    }

    [$type, $key] = $related;
    $id = match ($type) {
        'certificate'  => $certificateIds[$key] ?? null,
        'payer'        => $payerIds[$key] ?? null,
        'beneficiary'  => $beneficiaryIds[$key] ?? null,
        default        => null,
    };

    return $id === null ? [null, null] : [$type, $id];
};

$notificationCount = 0;

foreach ($demoNotifications as $notification) {
    [$relatedType, $related] = $relatedId($notification['related'] ?? null);
    $batch = $demoBatchKey();

    $insertNotification->execute([
        'batch'        => $batch,
        'thread'       => null,
        'parent'       => null,
        'sender'       => $notification['from'] !== null ? $staffIds[$notification['from']] : null,
        'recipient'    => $staffIds[$notification['to']],
        'type'         => $notification['type'],
        'subject'      => $notification['subject'],
        'body'         => $notification['body'],
        'related_type' => $relatedType,
        'related_id'   => $related,
        'read_at'      => $notification['read'] !== null ? $at($notification['read'], '15:40:00') : null,
        'resolved_at'  => $notification['resolved'] !== null ? $at($notification['resolved'], '16:10:00') : null,
        'archived_at'  => $notification['archived'] !== null ? $at($notification['archived'], '17:30:00') : null,
        'created_at'   => $at($notification['created'], '09:20:00'),
    ]);
    $notificationId = (int) $db->lastInsertId();
    // Wiadomość zaczynająca wątek jest swoim własnym wątkiem — tak samo robi NotificationService.
    $closeThread->execute(['id' => $notificationId]);
    ++$notificationCount;

    if ($notification['reply'] === null) {
        continue;
    }

    $reply = $notification['reply'];
    $insertNotification->execute([
        'batch'        => $demoBatchKey(),
        'thread'       => $notificationId,
        'parent'       => $notificationId,
        'sender'       => $staffIds[$reply['from']],
        'recipient'    => $notification['from'] !== null ? $staffIds[$notification['from']] : $staffIds[$notification['to']],
        'type'         => 'message',
        'subject'      => 'Re: ' . $notification['subject'],
        'body'         => $reply['body'],
        'related_type' => $relatedType,
        'related_id'   => $related,
        'read_at'      => $reply['read'] !== null ? $at($reply['read'], '15:40:00') : null,
        'resolved_at'  => null,
        'archived_at'  => null,
        'created_at'   => $at($reply['created'], '10:05:00'),
    ]);
    ++$notificationCount;
}

// ── Wnioski o certyfikat z poczty ─────────────────────────────────────────────

// Rejestr bez sieci: te same pola, co Biała Lista, ale odpowiedzi z $demoCompanyRecords. Dzięki temu
// wnioski demo powstają przez prawdziwą usługę i mają autentyczne wyliczenia w kolumnach JSON.
$demoRegistry = new class ($demoCompanyRecords) implements CompanyRegistry {
    /** @param array<int|string, array<string, string>> $records */
    public function __construct(private readonly array $records)
    {
    }

    public function supports(string $taxId): bool
    {
        return preg_match('/^\d{10}$/', $taxId) === 1;
    }

    public function lookupNip(string $nip, ?DateTimeImmutable $date = null): ?CompanyRecord
    {
        return isset($this->records[$nip]) ? CompanyRecord::fromArray($this->records[$nip]) : null;
    }
};

$intake = new RegistrationIntakeService($db, $demoRegistry);
// Wczytanie pliku w panelu wymaga uprawnienia; drogi automatyczne (imap, webhook) nie mają operatora.
$uploader = Rbac::can((string) $owner['role'], 'registrations.intake') ? Actor::fromUser($owner) : null;

$demoMail = static function (array $registration) use ($day): string {
    [$fromName, $fromEmail] = $registration['from'];
    $date = (new DateTimeImmutable($day($registration['offset']) . ' 09:30:00'))->format('D, d M Y H:i:s O');

    return "From: {$fromName} <{$fromEmail}>\r\n"
        . "To: rejestracja@certisub.example.com\r\n"
        . "Subject: {$registration['subject']}\r\n"
        . "Message-ID: <{$registration['id']}@demo.certisub.example>\r\n"
        . "Date: {$date}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "\r\n"
        . str_replace("\n", "\r\n", $registration['body']);
};

$reviewDraft = $db->prepare(
    'UPDATE registration_drafts
        SET status = :status, created_at = :created_at, assigned_user_id = :assigned,
            reviewed_by_user_id = :reviewer, reviewed_at = :reviewed_at, rejection_reason = :reason,
            result_payer_id = :result_payer, result_beneficiary_id = :result_beneficiary,
            result_certificate_id = :result_certificate
      WHERE id = :id'
);
// Powiadomienie o wniosku tworzy sama usługa (announce) — tu zostaje tylko ustawienie daty i odczytu.
$datedDraftNotification = $db->prepare(
    'UPDATE notifications SET created_at = :created_at, read_at = :read_at
      WHERE related_type = \'registration\' AND related_id = :id'
);

$draftIds = [];

foreach ($demoRegistrations as $key => $registration) {
    $result = $intake->ingest(
        $demoMail($registration),
        $registration['source'],
        $registration['source'] === 'upload' ? $uploader : null
    );

    if ($result['draft_id'] === null) {
        fwrite(STDERR, "Wniosek demo {$key} został pominięty ({$result['status']}).\n");
        continue;
    }

    $draftId = (int) $result['draft_id'];
    $draftIds[$key] = $draftId;
    $resultKeys = $registration['result'];

    $reviewDraft->execute([
        'status'             => $registration['status'],
        'created_at'         => $at($registration['offset'], '09:31:00'),
        'assigned'           => $registration['assigned'] !== null ? $staffIds[$registration['assigned']] : null,
        'reviewer'           => $registration['reviewer'] !== null ? $staffIds[$registration['reviewer']] : null,
        'reviewed_at'        => $registration['reviewed'] !== null ? $at($registration['reviewed'], '12:45:00') : null,
        'reason'             => $registration['reason'],
        'result_payer'       => $resultKeys !== null ? $payerIds[$resultKeys[0]] : null,
        'result_beneficiary' => $resultKeys !== null ? $beneficiaryIds[$resultKeys[1]] : null,
        'result_certificate' => $resultKeys !== null ? $certificateIds[$resultKeys[2]] : null,
        'id'                 => $draftId,
    ]);

    // Najnowszy wniosek zostaje nieprzeczytany — po to, żeby licznik przy dzwonku nie był pusty.
    $datedDraftNotification->execute([
        'created_at' => $at($registration['offset'], '09:31:00'),
        'read_at'    => $registration['offset'] <= -4 ? $at($registration['offset'], '10:15:00') : null,
        'id'         => $draftId,
    ]);
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
printf("  Wnioski z poczty:                 %s\n", $grouped('SELECT status, COUNT(*) FROM registration_drafts GROUP BY status ORDER BY FIELD(status, "pending", "approved", "rejected")'));
printf(
    "  Powiadomienia:                    %s (%d nieprzeczytanych)\n",
    $grouped('SELECT type, COUNT(*) FROM notifications GROUP BY type ORDER BY FIELD(type, "message", "request", "error", "system")'),
    $count('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL AND archived_at IS NULL')
);
printf("  Ustawienia odnowień w bazie:      %d\n", $count('SELECT COUNT(*) FROM settings'));
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

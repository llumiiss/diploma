<?php

declare(strict_types=1);

/**
 * Duży zestaw danych testowych dla Etapu 12 (historia firm, płatnicy rok do roku) — DOPISYWANY do bazy.
 *
 * Skrypt niczego nie usuwa i nie nadpisuje: każdy wiersz jest wstawiany tylko wtedy, gdy jeszcze go nie ma
 * (firma po NIP-ie, osoba i konto po adresie e-mail, certyfikat po wystawcy i numerze seryjnym, okresy po
 * osobie, firmie i dacie). Ponowne uruchomienie nie tworzy duplikatów.
 *
 *   php scripts/seed-history-data.php                       # dopisz dane do bazy z config/database.php
 *   php scripts/seed-history-data.php --sql=plik.sql        # zamiast tego zapisz skrypt SQL (np. do phpMyAdmin na hostingu)
 *   php scripts/seed-history-data.php --password=Haslo123!   # hasło kont testowych (domyślnie jak w danych demo)
 *   php scripts/seed-history-data.php --anchor=2026-11-26     # dzień, od którego liczone są daty (domyślnie 2026-11-26)
 *
 * Zawartość: firmy z publicznego Wykazu podatników VAT (scripts/data/firmy-publiczne.php), ok. 150 wymyślonych
 * osób (@example.com) z historią firm (jedna firma, przeniesienie, dwie firmy naraz, archiwum), ok. 300
 * certyfikatów z łańcuchami odnowień i historią płatników, konta wszystkich ról, zadania ToDo we wszystkich
 * statusach, zaproszenia (bez wysyłki — tylko wiersze w bazie), powiadomienia, wnioski e-mail, szablony
 * wiadomości i odczytu oraz zdarzenia na osiach czasu. Przykład wzorcowy: osoba w dwóch firmach naraz,
 * której certyfikat co rok opłaca inna firma.
 *
 * Konto ADMIN z tego zestawu nie ma hasła (nie da się na nie zalogować). Pozostałe konta mają jawne hasło
 * danych demo i służą wyłącznie do pokazu. Wymaga migracji company_history (php scripts/migrate.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden — CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Auth\PasswordPolicy;
use App\Database;
use App\Intake\CompanyRecord;
use App\Intake\CompanyRegistry;
use App\Migrations\SchemaInspector;
use App\Service\RegistrationIntakeService;

$args = array_slice($argv ?? [], 1);
$sqlPath = null;
$password = 'Demo2026!haslo';
$anchor = '2026-11-26';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--sql=')) {
        $sqlPath = substr($arg, strlen('--sql='));
    } elseif (str_starts_with($arg, '--anchor=')) {
        $anchor = substr($arg, strlen('--anchor='));
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, strlen('--password='));
    }
}
if (PasswordPolicy::validate($password) !== null) {
    fwrite(STDERR, 'Hasło kont testowych nie spełnia wymagań.' . PHP_EOL);
    exit(1);
}

$db = Database::getInstance()->getConnection();
if (!SchemaInspector::tableExists($db, 'beneficiary_employments')) {
    fwrite(STDERR, 'Brak tabel Etapu 12 — uruchom najpierw: php scripts/migrate.php' . PHP_EOL);
    exit(1);
}

mt_srand(20261126);
// Stały dzień odniesienia: te same daty przy każdym uruchomieniu, więc ponowne uruchomienie nie tworzy duplikatów.
$today = new DateTimeImmutable($anchor);
$day = static fn (int $offset): string => $today->modify(sprintf('%+d days', $offset))->format('Y-m-d');
$rand = static fn (int $min, int $max): int => mt_rand($min, $max);
$pick = static function (array $items): mixed {
    return $items[mt_rand(0, count($items) - 1)];
};

// ─────────────────────────────────────────────────────────── budowanie skryptu SQL

/** Wartość wstawiana do SQL bez cudzysłowów (zmienna @x, podzapytanie, NOW()). */
final class Raw
{
    public function __construct(public readonly string $sql)
    {
    }
}

$statements = [];
$q = static function (mixed $value): string {
    if ($value instanceof Raw) {
        return $value->sql;
    }
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . strtr((string) $value, ['\\' => '\\\\', "'" => "''", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z']) . "'";
};
/**
 * INSERT … SELECT … WHERE NOT EXISTS — wiersz powstaje tylko raz. Opcjonalnie zapamiętuje id w zmiennej @var.
 *
 * @param array<string, mixed> $row
 */
$insert = static function (string $table, array $row, string $guard, ?string $var = null) use (&$statements, $q): void {
    $columns = implode(', ', array_keys($row));
    $values = implode(', ', array_map($q, array_values($row)));
    $statements[] = "INSERT INTO {$table} ({$columns}) SELECT {$values} FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM {$table} WHERE {$guard});";
    if ($var !== null) {
        $statements[] = "SET @{$var} = (SELECT id FROM {$table} WHERE {$guard} ORDER BY id LIMIT 1);";
    }
};
$event = static function (string $entity, string $entityVar, string $type, ?string $userVar, array $context, array $payload, string $at) use ($insert, $q): void {
    $row = [
        'entity_type'    => $entity,
        'entity_id'      => new Raw('@' . $entityVar),
        'event_type'     => $type,
        'user_id'        => $userVar !== null ? new Raw('@' . $userVar) : null,
        'certificate_id' => isset($context['certificate']) ? new Raw('@' . $context['certificate']) : null,
        'beneficiary_id' => isset($context['beneficiary']) ? new Raw('@' . $context['beneficiary']) : null,
        'payer_id'       => isset($context['payer']) ? new Raw('@' . $context['payer']) : null,
        'payload'        => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'occurred_at'    => $at,
    ];
    $insert('events', $row, "entity_type = {$q($entity)} AND entity_id = @{$entityVar} AND event_type = {$q($type)} AND occurred_at = {$q($at)}");
};
$ascii = static function (string $text): string {
    $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z'];

    return strtolower(preg_replace('/[^a-z0-9]+/i', '', strtr($text, $map)) ?? '');
};

$statements[] = 'SET NAMES utf8mb4;';
$statements[] = "SET @seed_note = 'Etap 12 — dane testowe (dopisane)';";

// ─────────────────────────────────────────────────────────── konta personelu (wszystkie role)

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$staff = [
    'admin'    => ['Halina', 'Sobczak', 'ADMIN', false],
    'director' => ['Leszek', 'Mazur', 'DIRECTOR', true],
    'manager1' => ['Karolina', 'Duda', 'MANAGER', true],
    'manager2' => ['Paweł', 'Sikora', 'MANAGER', true],
    'account'  => ['Beata', 'Kalinowska', 'ACCOUNTANT', true],
    'it1'      => ['Michał', 'Wieczorek', 'IT', true],
    'it2'      => ['Damian', 'Kubiak', 'IT', true],
    'op1'      => ['Natalia', 'Krawczyk', 'OPERATOR', true],
    'op2'      => ['Sebastian', 'Jabłoński', 'OPERATOR', true],
    'op3'      => ['Weronika', 'Michalska', 'OPERATOR', true],
    'emp1'     => ['Oskar', 'Pietrzak', 'EMPLOYEE', true],
    'emp2'     => ['Julia', 'Wróblewska', 'EMPLOYEE', true],
    'retired'  => ['Rafał', 'Zając', 'OPERATOR', true],
];
foreach ($staff as $key => [$first, $last, $role, $withPassword]) {
    $email = $ascii($first) . '.' . $ascii($last) . '@example.com';
    $staff[$key][] = $email;
    $insert('users', [
        'first_name'        => $first,
        'last_name'         => $last,
        'role'              => $role,
        'email'             => $email,
        'password_hash'     => $withPassword ? $passwordHash : null,
        'email_verified_at' => $withPassword ? $day(-200) . ' 10:00:00' : null,
        'deactivated_at'    => $key === 'retired' ? $day(-40) . ' 16:00:00' : null,
        'created_at'        => $day(-400) . ' 09:00:00',
    ], 'email = ' . $q($email), 'u_' . $key);
}
$operators = ['op1', 'op2', 'op3'];
$owners = ['op1', 'op2', 'op3', 'manager1', 'manager2'];

// ─────────────────────────────────────────────────────────── firmy (publiczne dane rejestrowe)

$registry = require __DIR__ . '/data/firmy-publiczne.php';
$draftOnly = ['5272526109', '8992296521', '5260300517', '8790017162'];
$archivedCompanies = ['5272468122', '5260211713'];
$contacts = [['Anna', 'Kowalczyk'], ['Marek', 'Wójtowicz'], ['Ewelina', 'Baran'], ['Grzegorz', 'Szulc'], ['Magdalena', 'Laskowska'],
    ['Tadeusz', 'Ostrowski'], ['Renata', 'Górecka'], ['Wojciech', 'Kozłowski'], ['Izabela', 'Majewska'], ['Kamil', 'Adamczyk']];
$companies = [];
foreach ($registry as $i => $company) {
    if (in_array($company['nip'], $draftOnly, true)) {
        continue;
    }
    $var = 'p_' . $company['nip'];
    $slug = substr($ascii(strtok($company['name'], ' ') ?: 'firma'), 0, 20);
    [$cf, $cl] = $contacts[$i % count($contacts)];
    $insert('payers', [
        'company_name'       => $company['name'],
        'contact_person'     => $cf . ' ' . $cl . ' (dane fikcyjne)',
        'tax_id'             => $company['nip'],
        'email'              => 'ksiegowosc.' . $slug . '@example.com',
        'phone'              => null,
        'address_line'       => $company['street'],
        'postal_code'        => $company['postal_code'],
        'city'               => $company['city'],
        'created_by_user_id' => new Raw('@u_' . $pick(['manager1', 'manager2', 'op1', 'op2'])),
        'created_at'         => $day(-$rand(500, 900)) . ' 10:00:00',
    ], 'tax_id = ' . $q($company['nip']), $var);
    $event('payer', $var, 'created', 'u_manager1', ['payer' => $var], ['company_name' => $company['name']], $day(-$rand(500, 900)) . ' 10:00:00');
    $companies[$company['nip']] = ['var' => $var, 'name' => $company['name']];
}
// NIP jako klucz tablicy PHP zamienia się na liczbę — tu wracamy do tekstu.
$active = array_values(array_diff(array_map('strval', array_keys($companies)), $archivedCompanies));

// ─────────────────────────────────────────────────────────── osoby i historia firm

$femaleFirst = ['Anna', 'Maria', 'Katarzyna', 'Małgorzata', 'Agnieszka', 'Barbara', 'Ewa', 'Krystyna', 'Elżbieta', 'Zofia', 'Joanna',
    'Aleksandra', 'Monika', 'Dorota', 'Magdalena', 'Karolina', 'Paulina', 'Justyna', 'Marta', 'Natalia', 'Agata', 'Sylwia', 'Iwona', 'Kinga'];
$maleFirst = ['Piotr', 'Krzysztof', 'Andrzej', 'Tomasz', 'Paweł', 'Michał', 'Marcin', 'Jakub', 'Adam', 'Łukasz', 'Mateusz', 'Grzegorz',
    'Rafał', 'Dariusz', 'Bartosz', 'Kamil', 'Szymon', 'Wojciech', 'Maciej', 'Filip', 'Dawid', 'Patryk', 'Hubert', 'Konrad'];
$surnames = [['Nowak', 'Nowak'], ['Kowalski', 'Kowalska'], ['Wiśniewski', 'Wiśniewska'], ['Wójcik', 'Wójcik'], ['Kamiński', 'Kamińska'],
    ['Lewandowski', 'Lewandowska'], ['Zieliński', 'Zielińska'], ['Szymański', 'Szymańska'], ['Woźniak', 'Woźniak'], ['Dąbrowski', 'Dąbrowska'],
    ['Kozłowski', 'Kozłowska'], ['Jankowski', 'Jankowska'], ['Mazur', 'Mazur'], ['Kwiatkowski', 'Kwiatkowska'], ['Krawczyk', 'Krawczyk'],
    ['Piotrowski', 'Piotrowska'], ['Grabowski', 'Grabowska'], ['Nowakowski', 'Nowakowska'], ['Pawłowski', 'Pawłowska'], ['Michalski', 'Michalska'],
    ['Nowicki', 'Nowicka'], ['Adamczyk', 'Adamczyk'], ['Dudek', 'Dudek'], ['Zając', 'Zając'], ['Wieczorek', 'Wieczorek'], ['Jabłoński', 'Jabłońska'],
    ['Król', 'Król'], ['Majewski', 'Majewska'], ['Olszewski', 'Olszewska'], ['Jaworski', 'Jaworska'], ['Wróbel', 'Wróbel'], ['Malinowski', 'Malinowska'],
    ['Pawlak', 'Pawlak'], ['Witkowski', 'Witkowska'], ['Walczak', 'Walczak'], ['Stępień', 'Stępień'], ['Górski', 'Górska'], ['Rutkowski', 'Rutkowska'],
    ['Michalak', 'Michalak'], ['Sikora', 'Sikora'], ['Ostrowski', 'Ostrowska'], ['Baran', 'Baran'], ['Duda', 'Duda'], ['Szewczyk', 'Szewczyk']];
$moveNotes = ['przeniesienie służbowe w grupie kapitałowej', 'zmiana pracodawcy', 'nowa umowa od początku miesiąca', 'przejście do spółki zależnej', null, null];
$addNotes = ['umowa zlecenie (część etatu)', 'rada nadzorcza', 'projekt wspólny — druga umowa', 'pełnomocnik do podpisywania umów', null];

$people = [];
$usedEmails = [];
$personCount = 150;
for ($i = 0; $i < $personCount; $i++) {
    $female = $i % 2 === 0;
    $first = $female ? $pick($femaleFirst) : $pick($maleFirst);
    $last = $pick($surnames)[$female ? 1 : 0];
    $base = $ascii($first) . '.' . $ascii($last);
    $email = $base . '@example.com';
    $n = 2;
    while (isset($usedEmails[$email])) {
        $email = $base . $n++ . '@example.com';
    }
    $usedEmails[$email] = true;

    $kind = match (true) {
        $i % 20 < 12 => 'single',
        $i % 20 < 16 => 'moved',
        $i % 20 < 19 => 'double',
        default      => 'archived',
    };
    $start = $day(-$rand(300, 3000));
    $a = $pick($active);
    do {
        $b = $pick($active);
    } while ($b === $a);
    // Dawni pracownicy firm z archiwum: zaczynali tam, a potem przeszli dalej.
    if ($kind === 'moved' && $i % 40 === 13) {
        $a = $archivedCompanies[$i % 2];
    }

    $periods = [];
    $primary = $a;
    $archivedAt = null;
    if ($kind === 'single') {
        $periods[] = [$a, $start, null, null];
    } elseif ($kind === 'moved') {
        $switch = $day(-$rand(30, 700));
        if ($switch <= $start) {
            $start = $day(-$rand(800, 2400));
        }
        $periods[] = [$a, $start, $switch, null];
        $periods[] = [$b, $switch, null, $pick($moveNotes)];
        $primary = $b;
    } elseif ($kind === 'double') {
        $second = $day(-$rand(20, 600));
        $periods[] = [$a, $start, null, null];
        $periods[] = [$b, max($start, $second), null, $pick($addNotes)];
    } else {
        $end = $day(-$rand(20, 250));
        if ($end <= $start) {
            $start = $day(-$rand(400, 1500));
        }
        $periods[] = [$a, $start, $end, 'koniec współpracy'];
        $archivedAt = $end . ' 15:00:00';
    }

    $var = 'b_' . $i;
    $creator = $pick($owners);
    $insert('beneficiaries', [
        'first_name'         => $first,
        'last_name'          => $last,
        'email'              => $email,
        'phone'              => null,
        'payer_id'           => new Raw('@' . $companies[$primary]['var']),
        'notes'              => $kind === 'double' ? 'Pracuje równolegle w dwóch firmach.' : null,
        'created_by_user_id' => new Raw('@u_' . $creator),
        'archived_at'        => $archivedAt,
        'created_at'         => $start . ' 09:00:00',
    ], 'email = ' . $q($email), $var);
    $event('beneficiary', $var, 'created', 'u_' . $creator, ['beneficiary' => $var, 'payer' => $companies[$periods[0][0]]['var']], ['name' => $first . ' ' . $last], $start . ' 09:00:00');

    foreach ($periods as [$company, $from, $to, $note]) {
        $cv = $companies[$company]['var'];
        $insert('beneficiary_employments', [
            'beneficiary_id'     => new Raw('@' . $var),
            'payer_id'           => new Raw('@' . $cv),
            'started_on'         => $from,
            'ended_on'           => $to,
            'note'               => $note,
            'created_by_user_id' => new Raw('@u_' . $creator),
        ], "beneficiary_id = @{$var} AND payer_id = @{$cv} AND started_on = " . $q($from));
    }
    if ($kind === 'moved') {
        $event('beneficiary', $var, 'company_moved', 'u_' . $creator, ['beneficiary' => $var, 'payer' => $companies[$b]['var']], array_filter([
            'name' => $first . ' ' . $last, 'from' => $companies[$a]['name'], 'to' => $companies[$b]['name'], 'date' => $periods[1][1], 'note' => $periods[1][3],
        ]), $periods[1][1] . ' 11:00:00');
    } elseif ($kind === 'double') {
        $event('beneficiary', $var, 'company_added', 'u_' . $creator, ['beneficiary' => $var, 'payer' => $companies[$b]['var']], array_filter([
            'name' => $first . ' ' . $last, 'to' => $companies[$b]['name'], 'date' => $periods[1][1], 'note' => $periods[1][3],
        ]), $periods[1][1] . ' 11:00:00');
    } elseif ($kind === 'archived') {
        $event('beneficiary', $var, 'archived', 'u_' . $creator, ['beneficiary' => $var, 'payer' => $companies[$a]['var']], ['name' => $first . ' ' . $last], $archivedAt);
    }

    $people[$i] = ['var' => $var, 'name' => $first . ' ' . $last, 'email' => $email, 'kind' => $kind, 'periods' => $periods, 'primary' => $primary, 'archived' => $archivedAt];
}

// Wzorcowy przypadek: dwie firmy naraz, płatnik certyfikatu zmienia się co rok.
$comarch = '6770065406';
$asseco = '5220003782';
$insert('beneficiaries', [
    'first_name'         => 'Natalia',
    'last_name'          => 'Przybylska',
    'email'              => 'natalia.przybylska@example.com',
    'phone'              => null,
    'payer_id'           => new Raw('@' . $companies[$comarch]['var']),
    'notes'              => 'Przykład: pracuje w dwóch firmach naraz, a jej podpis kwalifikowany co rok opłaca inna firma.',
    'created_by_user_id' => new Raw('@u_op1'),
    'archived_at'        => null,
    'created_at'         => $day(-1200) . ' 09:00:00',
], "email = 'natalia.przybylska@example.com'", 'b_example');
$insert(
    'beneficiary_employments',
    ['beneficiary_id' => new Raw('@b_example'), 'payer_id' => new Raw('@' . $companies[$comarch]['var']), 'started_on' => $day(-1200), 'ended_on' => null, 'note' => 'etat', 'created_by_user_id' => new Raw('@u_op1')],
    "beneficiary_id = @b_example AND payer_id = @{$companies[$comarch]['var']} AND started_on = " . $q($day(-1200))
);
$insert(
    'beneficiary_employments',
    ['beneficiary_id' => new Raw('@b_example'), 'payer_id' => new Raw('@' . $companies[$asseco]['var']), 'started_on' => $day(-900), 'ended_on' => null, 'note' => 'druga umowa (część etatu)', 'created_by_user_id' => new Raw('@u_op1')],
    "beneficiary_id = @b_example AND payer_id = @{$companies[$asseco]['var']} AND started_on = " . $q($day(-900))
);
$event('beneficiary', 'b_example', 'company_added', 'u_op1', ['beneficiary' => 'b_example', 'payer' => $companies[$asseco]['var']], ['name' => 'Natalia Przybylska', 'to' => $companies[$asseco]['name'], 'date' => $day(-900)], $day(-900) . ' 11:00:00');
$people['example'] = ['var' => 'b_example', 'name' => 'Natalia Przybylska', 'email' => 'natalia.przybylska@example.com', 'kind' => 'example',
    'periods' => [[$comarch, $day(-1200), null, null], [$asseco, $day(-900), null, null]], 'primary' => $comarch, 'archived' => null];

// Pracownicy z kontem EMPLOYEE widzą własne certyfikaty.
$statements[] = 'UPDATE users SET beneficiary_id = @b_3 WHERE email = ' . $q($staff['emp1'][4]) . ' AND beneficiary_id IS NULL;';
$statements[] = 'UPDATE users SET beneficiary_id = @b_example WHERE email = ' . $q($staff['emp2'][4]) . ' AND beneficiary_id IS NULL;';

// ─────────────────────────────────────────────────────────── certyfikaty, płatnicy, łańcuchy odnowień

$qualifiedIssuers = ['Certum (Asseco Data Systems)', 'KIR — Szafir', 'EuroCert', 'Sigillum (PWPW)'];
$technical = [
    'SSL_CERTIFICATE' => [['Certyfikat SSL www', 'DigiCert'], ['Certyfikat SSL wildcard', 'Sectigo'], ['Certyfikat SSL sklepu', 'Certum (Asseco Data Systems)']],
    'DOMAIN'          => [['Domena firmowa .pl', 'NASK (rejestr .pl)'], ['Domena .com.pl', 'NASK (rejestr .pl)']],
    'SAAS'            => [['Licencje pakietu biurowego', 'Microsoft 365'], ['Poczta i dokumenty w chmurze', 'Google Workspace'], ['System zgłoszeń', 'Atlassian Cloud']],
    'CODE_SIGNING'    => [['Podpis kodu aplikacji', 'Certum (Asseco Data Systems)'], ['Podpis sterowników', 'DigiCert']],
    'CLOUD_SUPPORT'   => [['Wsparcie serwerów w chmurze', 'Azure Support'], ['Kopie zapasowe w chmurze', 'AWS Business Support']],
    'OTHER'           => [['Znacznik czasu (pakiet)', 'Certum (Asseco Data Systems)'], ['Pieczęć elektroniczna — usługa walidacji', 'KIR — Szafir']],
];
$serial = static fn (): string => strtoupper(sprintf('%04X%04X%04X%04X', mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF)));
$certificates = [];
$certificateSeq = 0;

/**
 * @param list<array{0: string, 1: string, 2: ?string, 3?: ?string}> $payerPeriods [nip, od, do, notatka]
 */
$addCertificate = static function (array $c, array $payerPeriods) use (&$certificates, &$certificateSeq, $insert, $event, $companies, $q, $today): string {
    $var = 'c_' . (++$certificateSeq);
    $current = end($payerPeriods);
    $payerVar = $companies[$current[0]]['var'];
    $expiry = $c['expiry_date'];
    $days = (int) $today->diff(new DateTimeImmutable($expiry))->format('%r%a');
    $status = $c['archived_at'] !== null ? ($c['status'] ?? 'active') : ($days < 0 ? 'expired' : ($c['in_progress'] ?? false ? 'renewal_in_progress' : 'active'));
    $insert('certificates', [
        'name'                    => $c['name'],
        'certificate_type'        => $c['type'],
        'serial_number'           => $c['serial'],
        'issuer'                  => $c['issuer'],
        'valid_from'              => $c['valid_from'],
        'expiry_date'             => $expiry,
        'renewal_lead_days'       => $c['lead'] ?? null,
        'user_id'                 => new Raw('@u_' . $c['owner']),
        'beneficiary_id'          => isset($c['person']) ? new Raw('@' . $c['person']) : null,
        'payer_id'                => new Raw('@' . $payerVar),
        'previous_certificate_id' => isset($c['previous']) ? new Raw('@' . $c['previous']) : null,
        'status'                  => $status,
        'discount_percent'        => $c['discount'] ?? '0.00',
        'billing_cycle'           => $c['billing'] ?? 'annual',
        'payment_status'          => $c['payment'] ?? 'paid',
        'last_payment_date'       => $c['paid_on'] ?? null,
        'auto_renew'              => $c['auto_renew'] ?? 1,
        'notes'                   => $c['notes'] ?? null,
        'archived_at'             => $c['archived_at'],
        'created_at'              => $c['valid_from'] . ' 08:30:00',
    ], 'issuer = ' . $q($c['issuer']) . ' AND serial_number = ' . $q($c['serial']), $var);
    foreach ($payerPeriods as $period) {
        $pv = $companies[$period[0]]['var'];
        $insert('certificate_payers', [
            'certificate_id'     => new Raw('@' . $var),
            'payer_id'           => new Raw('@' . $pv),
            'started_on'         => $period[1],
            'ended_on'           => $period[2],
            'note'               => $period[3] ?? null,
            'created_by_user_id' => new Raw('@u_' . $c['owner']),
        ], "certificate_id = @{$var} AND payer_id = @{$pv} AND started_on = " . $q($period[1]));
    }
    $context = ['certificate' => $var, 'payer' => $payerVar] + (isset($c['person']) ? ['beneficiary' => $c['person']] : []);
    $event('certificate', $var, 'created', 'u_' . $c['owner'], $context, array_filter([
        'name' => $c['name'], 'certificate_type' => $c['type'], 'expiry_date' => $expiry,
        'previous_certificate_id' => null, 'source' => isset($c['previous']) ? 'renewal' : null,
    ]), $c['valid_from'] . ' 08:30:00');
    if (count($payerPeriods) > 1) {
        $prev = $payerPeriods[count($payerPeriods) - 2];
        $event('certificate', $var, 'payer_changed', 'u_account', $context, array_filter([
            'from' => $companies[$prev[0]]['name'], 'to' => $companies[$current[0]]['name'], 'date' => $current[1], 'note' => $current[3] ?? null,
        ]), $current[1] . ' 12:00:00');
    }
    if ($c['archived_at'] !== null) {
        $event('certificate', $var, 'archived', 'u_' . $c['owner'], $context, ['name' => $c['name'], 'reason' => isset($c['renewed']) ? 'renewed' : null], $c['archived_at']);
    }
    $certificates[$var] = $c + ['var' => $var, 'payer' => $current[0], 'days' => $days];

    return $var;
};

/** Firma osoby w danym dniu (pierwsza z okresów obejmujących datę), a gdy brak — firma główna. */
$companyAt = static function (array $person, string $date): string {
    foreach ($person['periods'] as [$company, $from, $to]) {
        if ($from <= $date && ($to === null || $to >= $date)) {
            return (string) $company;
        }
    }

    return (string) $person['primary'];
};

$discounts = ['0.00', '0.00', '0.00', '3.00', '5.00', '10.00', '15.00'];
$payments = ['paid', 'paid', 'paid', 'due_soon', 'overdue', 'not_applicable'];
foreach ($people as $key => $person) {
    if ($key === 'example') {
        continue;
    }
    $count = $person['kind'] === 'double' ? 2 : ($key % 7 === 0 ? 2 : 1);
    for ($k = 0; $k < $count; $k++) {
        $type = $k === 1 && $key % 3 === 0 ? 'QUALIFIED_SEAL' : 'QUALIFIED_SIGNATURE';
        $issuer = $pick($qualifiedIssuers);
        $owner = $pick($operators);
        if ($person['archived'] !== null) {
            $expiry = substr($person['archived'], 0, 10) < $day(-30) ? $day(-$rand(1, 30)) : $day($rand(10, 200));
            $from = (new DateTimeImmutable($expiry))->modify('-1 year')->format('Y-m-d');
            $addCertificate([
                'name' => ($type === 'QUALIFIED_SEAL' ? 'Pieczęć kwalifikowana — ' : 'Podpis kwalifikowany — ') . $person['name'],
                'type' => $type, 'serial' => $serial(), 'issuer' => $issuer, 'valid_from' => $from, 'expiry_date' => $expiry,
                'owner' => $owner, 'person' => $person['var'], 'archived_at' => $person['archived'], 'notes' => 'Zarchiwizowany razem z osobą.',
                'payment' => 'paid', 'paid_on' => $from,
            ], [[$companyAt($person, $from), $from, substr($person['archived'], 0, 10)]]);
            continue;
        }

        // Bieżący certyfikat: termin od 60 dni po terminie (część już nie do odnowienia) do ok. 2 lat naprzód.
        $offset = $key % 11 === 0 ? -$rand(5, 60) : $rand(3, 700);
        $years = $key % 9 === 0 ? 2 : 1;
        // Okres ważności zaczyna się najpóźniej dziś (nie ma certyfikatów „ważnych od” w przyszłości).
        if ($offset > $years * 365 - 10) {
            $offset = 3 + $offset % ($years * 365 - 13);
        }
        $expiry = $day($offset);
        $from = (new DateTimeImmutable($expiry))->modify("-{$years} year")->format('Y-m-d');
        $employer = $companyAt($person, $from);
        $payerPeriods = [[$employer, $from, null]];
        // Płatnik inny niż pracodawca: osoba w dwóch firmach — za podpis płaci druga z nich.
        if ($person['kind'] === 'double' && $k === 1) {
            $payerPeriods = [[$person['periods'][1][0], max($from, $person['periods'][1][1]), null, 'płaci druga firma osoby']];
        }
        // Po przeniesieniu część certyfikatów dalej opłaca dawna firma, a część zmienia płatnika w trakcie.
        if ($person['kind'] === 'moved' && $key % 3 === 1 && $from < $person['periods'][1][1]) {
            $payerPeriods = [[$person['periods'][0][0], $from, $person['periods'][1][1]], [$person['periods'][1][0], $person['periods'][1][1], null, 'po przeniesieniu płaci nowa firma']];
        }

        $previous = null;
        // Co trzeci certyfikat ma poprzednika w archiwum (łańcuch odnowień, poprzednik opłacany przez firmę z tamtego okresu).
        if ($key % 3 === 0 && $k === 0) {
            $prevExpiry = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
            $prevFrom = (new DateTimeImmutable($from))->modify('-1 year')->format('Y-m-d');
            $previous = $addCertificate([
                'name' => 'Podpis kwalifikowany — ' . $person['name'], 'type' => 'QUALIFIED_SIGNATURE', 'serial' => $serial(), 'issuer' => $issuer,
                'valid_from' => $prevFrom, 'expiry_date' => $prevExpiry, 'owner' => $owner, 'person' => $person['var'],
                'archived_at' => $from . ' 10:00:00', 'renewed' => true, 'payment' => 'paid', 'paid_on' => $prevFrom,
            ], [[$companyAt($person, $prevFrom), $prevFrom, $from]]);
            $certificates[$previous]['task'] = 'done';
        }

        $var = $addCertificate([
            'name' => ($type === 'QUALIFIED_SEAL' ? 'Pieczęć kwalifikowana — ' : 'Podpis kwalifikowany — ') . $person['name'],
            'type' => $type, 'serial' => $serial(), 'issuer' => $issuer, 'valid_from' => $from, 'expiry_date' => $expiry,
            'owner' => $owner, 'person' => $person['var'], 'previous' => $previous, 'archived_at' => null,
            'discount' => $pick($discounts), 'billing' => $years === 2 ? 'multi_year' : 'annual',
            'payment' => $offset < 30 ? $pick(['due_soon', 'overdue', 'paid']) : $pick($payments),
            'paid_on' => $from, 'lead' => $key % 13 === 0 ? 45 : null, 'in_progress' => $offset >= 0 && $offset < 30 && $key % 2 === 0,
        ], $payerPeriods);
    }
}

// Wzorcowa osoba: 2024 płaci Comarch, 2025 Asseco, 2026 znów Comarch (odnowienie z wyborem płatnika).
$cycle = [[$day(-1060), $day(-696), $comarch], [$day(-695), $day(-331), $asseco], [$day(-330), $day(19), $comarch]];
$previous = null;
foreach ($cycle as $n => [$from, $to, $payer]) {
    $last = $n === count($cycle) - 1;
    $previous = $addCertificate([
        'name' => 'Podpis kwalifikowany — Natalia Przybylska', 'type' => 'QUALIFIED_SIGNATURE', 'serial' => 'NP-' . (2024 + $n) . '-0001',
        'issuer' => 'Certum (Asseco Data Systems)', 'valid_from' => $from, 'expiry_date' => $to, 'owner' => 'op1', 'person' => 'b_example',
        'previous' => $previous, 'archived_at' => $last ? null : (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 10:00:00',
        'renewed' => !$last, 'payment' => $last ? 'due_soon' : 'paid', 'paid_on' => $from, 'in_progress' => $last,
        'notes' => $last ? 'Przy odnowieniu wybierz płatnika — w tym roku płaci Asseco.' : null,
    ], [[$payer, $from, $last ? null : (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'), $n > 0 ? 'w tym roku płaci ' . ($payer === $comarch ? 'Comarch' : 'Asseco') : null]]);
    if (!$last) {
        $certificates[$previous]['task'] = 'done';
    }
}

// Certyfikaty techniczne firm (bez osoby) — obsługuje je dział IT.
foreach ($companies as $nip => $company) {
    $nip = (string) $nip;
    if (in_array($nip, $archivedCompanies, true)) {
        continue;
    }
    $n = 1 + ($rand(0, 9) < 6 ? 1 : 0) + ($rand(0, 9) < 3 ? 1 : 0);
    for ($k = 0; $k < $n; $k++) {
        $type = $pick(array_keys($technical));
        [$name, $issuer] = $pick($technical[$type]);
        $offset = $rand(-20, 540);
        if ($offset > 355) {
            $offset -= 300;
        }
        $expiry = $day($offset);
        $from = (new DateTimeImmutable($expiry))->modify('-1 year')->format('Y-m-d');
        $addCertificate([
            'name' => $name . ' — ' . mb_convert_case(mb_substr($company['name'], 0, 40), MB_CASE_TITLE), 'type' => $type, 'serial' => $serial(), 'issuer' => $issuer,
            'valid_from' => $from, 'expiry_date' => $expiry, 'owner' => $pick(['it1', 'it2']), 'archived_at' => null,
            'billing' => $type === 'SAAS' ? $pick(['monthly', 'annual']) : 'annual', 'payment' => $pick($payments), 'paid_on' => $from,
            'discount' => $pick($discounts), 'auto_renew' => $type === 'DOMAIN' ? 1 : $rand(0, 1), 'in_progress' => $offset >= 0 && $offset < 30 && $k === 0,
        ], [[$nip, $from, null]]);
    }
}

// Firmy w archiwum: dawne certyfikaty techniczne i firma zarchiwizowana.
foreach ($archivedCompanies as $nip) {
    $expiry = $day(-$rand(60, 200));
    $from = (new DateTimeImmutable($expiry))->modify('-1 year')->format('Y-m-d');
    $addCertificate([
        'name' => 'Certyfikat SSL www — ' . mb_convert_case(mb_substr($companies[$nip]['name'], 0, 40), MB_CASE_TITLE), 'type' => 'SSL_CERTIFICATE',
        'serial' => $serial(), 'issuer' => 'DigiCert', 'valid_from' => $from, 'expiry_date' => $expiry, 'owner' => 'it1',
        'archived_at' => $expiry . ' 17:00:00', 'payment' => 'paid', 'paid_on' => $from,
    ], [[$nip, $from, $expiry]]);
    // Okresy osób w tej firmie są zakończone przed archiwizacją firmy (osoby przeszły dalej).
    $statements[] = 'UPDATE payers SET archived_at = ' . $q($day(-20) . ' 16:00:00') . ' WHERE id = @' . $companies[$nip]['var'] . ' AND archived_at IS NULL'
        . ' AND NOT EXISTS (SELECT 1 FROM beneficiaries x WHERE x.payer_id = @' . $companies[$nip]['var'] . ' AND x.archived_at IS NULL)'
        . ' AND NOT EXISTS (SELECT 1 FROM certificates y WHERE y.payer_id = @' . $companies[$nip]['var'] . ' AND y.archived_at IS NULL)'
        . ' AND NOT EXISTS (SELECT 1 FROM beneficiary_employments z WHERE z.payer_id = @' . $companies[$nip]['var'] . ' AND z.ended_on IS NULL);';
    $event('payer', $companies[$nip]['var'], 'archived', 'u_manager1', ['payer' => $companies[$nip]['var']], ['company_name' => $companies[$nip]['name']], $day(-20) . ' 16:00:00');
}

// ─────────────────────────────────────────────────────────── zadania ToDo i zaproszenia

$priority = static fn (int $days): string => $days < 0 ? 'expired' : ($days <= 14 ? 'critical' : ($days <= 60 ? 'warning' : 'ok'));
$taskSeq = 0;
$invitationSeq = 0;
$templateFor = static fn (string $code): Raw => new Raw("(SELECT id FROM email_templates WHERE code = '{$code}' AND locale = 'pl' LIMIT 1)");
foreach ($certificates as $var => $c) {
    $open = $c['archived_at'] === null && $c['days'] <= 60;
    $closed = $c['task'] ?? ($c['archived_at'] !== null && isset($c['person']) && !isset($c['renewed']) ? 'abandoned' : null);
    if (!$open && $closed === null) {
        continue;
    }
    $tv = 't_' . (++$taskSeq);
    if ($open) {
        $status = ($c['in_progress'] ?? false) ? 'in_progress' : ($taskSeq % 3 === 0 ? 'in_progress' : 'todo');
        $assignee = $taskSeq % 4 === 0 ? null : $pick($operators);
        $insert('renewal_tasks', [
            'certificate_id'   => new Raw('@' . $var),
            'assigned_user_id' => $assignee !== null ? new Raw('@u_' . $assignee) : null,
            'status'           => $status,
            'priority'         => $priority($c['days']),
            'due_date'         => $c['expiry_date'],
            'created_at'       => $day(-$rand(1, 50)) . ' 06:00:00',
        ], "certificate_id = @{$var} AND status IN ('todo', 'in_progress')", $tv);
        $event('renewal_task', $tv, 'task_opened', null, ['certificate' => $var, 'payer' => $companies[$c['payer']]['var']], ['priority' => $priority($c['days'])], $day(-$rand(1, 50)) . ' 06:00:00');
    } else {
        $closedAt = $c['archived_at'];
        $insert('renewal_tasks', [
            'certificate_id'   => new Raw('@' . $var),
            'assigned_user_id' => new Raw('@u_' . $pick($operators)),
            'status'           => $closed,
            'priority'         => 'warning',
            'due_date'         => $c['expiry_date'],
            'resolution_note'  => $closed === 'done' ? 'Odnowiono — nowy certyfikat w łańcuchu.' : 'Osoba odeszła z firmy — bez odnowienia.',
            'closed_at'        => $closedAt,
            'created_at'       => (new DateTimeImmutable($c['expiry_date']))->modify('-45 days')->format('Y-m-d') . ' 06:00:00',
        ], "certificate_id = @{$var} AND due_date = " . $q($c['expiry_date']) . ' AND status = ' . $q($closed), $tv);
    }

    // Zaproszenia do odnowienia (tylko wiersze w bazie — nic nie jest wysyłane).
    if (!isset($c['person']) || $taskSeq % 2 === 1) {
        continue;
    }
    $person = null;
    foreach ($people as $p) {
        if ($p['var'] === $c['person']) {
            $person = $p;
            break;
        }
    }
    if ($person === null) {
        continue;
    }
    $iv = 'i_' . (++$invitationSeq);
    $invStatus = $open ? $pick(['sent', 'sent', 'responded', 'queued', 'failed']) : 'closed';
    $sentAt = $invStatus === 'queued' ? null : $day($open ? -$rand(1, 20) : -$rand(60, 400)) . ' 09:15:00';
    $subject = 'Odnowienie certyfikatu: ' . $c['name'];
    $insert('invitations', [
        'certificate_id'   => new Raw('@' . $var),
        'renewal_task_id'  => new Raw('@' . $tv),
        'template_id'      => $templateFor('renewal_invitation'),
        'recipient_type'   => 'beneficiary',
        'recipient_email'  => $person['email'],
        'recipient_name'   => $person['name'],
        'subject'          => $subject,
        'body_html'        => '<p>Dzień dobry,</p><p>certyfikat „' . htmlspecialchars($c['name']) . '” wygasa ' . $c['expiry_date'] . '. Prosimy o kontakt w sprawie odnowienia.</p><p>(wiadomość z danych testowych — nie została wysłana)</p>',
        'status'           => $invStatus,
        'sent_at'          => $sentAt,
        'last_error'       => $invStatus === 'failed' ? '550 5.1.1 Mailbox unavailable (przykład z danych testowych)' : null,
        'reminder_count'   => $invStatus === 'sent' ? $rand(0, 2) : 0,
        'next_reminder_at' => $invStatus === 'sent' ? $day($rand(1, 7)) . ' 09:00:00' : null,
        'sent_by_user_id'  => new Raw('@u_' . $pick($operators)),
        'created_at'       => $sentAt ?? $day(0) . ' 08:00:00',
    ], "certificate_id = @{$var} AND subject = " . $q($subject) . ' AND recipient_email = ' . $q($person['email']), $iv);
    if ($sentAt !== null) {
        $event(
            'invitation',
            $iv,
            $invStatus === 'failed' ? 'invitation_failed' : 'invitation_sent',
            'u_op1',
            ['certificate' => $var, 'beneficiary' => $c['person'], 'payer' => $companies[$c['payer']]['var']],
            ['recipient_email' => $person['email']],
            $sentAt
        );
    }
}

// ─────────────────────────────────────────────────────────── szablony wiadomości i odczytu

$insert('email_templates', [
    'code' => 'payer_change_notice', 'locale' => 'pl', 'name' => 'Informacja o zmianie płatnika',
    'subject' => 'Zmiana płatnika certyfikatu {{certificate_name}}',
    'body_html' => '<p>Dzień dobry,</p><p>od kolejnego okresu certyfikat <strong>{{certificate_name}}</strong> opłaca firma {{payer_name}}.</p><p>Pozdrawiamy</p>',
    'body_text' => "Dzień dobry,\nod kolejnego okresu certyfikat {{certificate_name}} opłaca firma {{payer_name}}.\nPozdrawiamy",
    'is_active' => 1,
], "code = 'payer_change_notice' AND locale = 'pl'");
$insert('email_templates', [
    'code' => 'company_moved_notice', 'locale' => 'pl', 'name' => 'Potwierdzenie przeniesienia do innej firmy',
    'subject' => 'Certyfikat {{certificate_name}} po zmianie firmy',
    'body_html' => '<p>Dzień dobry {{beneficiary_name}},</p><p>Twój certyfikat <strong>{{certificate_name}}</strong> pozostaje ważny do {{expiry_date}} także po zmianie firmy.</p>',
    'body_text' => "Dzień dobry {{beneficiary_name}},\nTwój certyfikat {{certificate_name}} pozostaje ważny do {{expiry_date}} także po zmianie firmy.",
    'is_active' => 1,
], "code = 'company_moved_notice' AND locale = 'pl'");
foreach ([['company_name', 'Nazwa pracodawcy'], ['tax_id', 'NIP pracodawcy'], ['email', 'Adres e-mail pracownika']] as [$field, $label]) {
    $insert('reading_rules', [
        'name' => 'Formularz kadrowy (dane testowe)', 'field' => $field, 'label' => $label, 'sender_match' => 'kadry', 'subject_match' => null,
        'is_active' => 1, 'created_by_user_id' => new Raw('@u_manager1'),
    ], "name = 'Formularz kadrowy (dane testowe)' AND field = " . $q($field) . ' AND label = ' . $q($label));
}

// ─────────────────────────────────────────────────────────── powiadomienia

$notices = [
    ['op1', 'manager1', 'request', 'Proszę o zmianę płatnika podpisu', 'W tym roku podpis opłaca druga firma osoby — proszę o zmianę płatnika przy odnowieniu.', 'beneficiary', 'b_example'],
    ['manager1', 'op1', 'message', 'Re: zmiana płatnika', 'Zmienione w oknie odnowienia, historia płatników jest w szczegółach certyfikatu.', 'beneficiary', 'b_example'],
    ['op2', 'account', 'request', 'Faktura dla nowej firmy', 'Osoba została przeniesiona do innej firmy — od następnego okresu faktura na nowego płatnika.', 'beneficiary', 'b_12'],
    ['account', 'op2', 'message', 'Re: faktura', 'Przyjęłam, zmienię płatnika w płatnościach.', 'beneficiary', 'b_12'],
    ['it1', 'manager2', 'message', 'Domeny do przedłużenia', 'Kilka domen firmowych wygasa w ciągu miesiąca — zadania są na liście ToDo.', null, null],
    ['manager2', 'op3', 'request', 'Sprawdź archiwum firm', 'Proszę sprawdzić w Archiwum → Historia firm, kto pracował w firmach zarchiwizowanych.', null, null],
    ['director', 'manager1', 'message', 'Raport roczny', 'Proszę o eksport historii firm za ostatni rok (CSV).', null, null],
    ['op3', 'admin', 'error', 'Nie mogę zakończyć ostatniej firmy', 'System nie pozwala zakończyć jedynej firmy osoby — czy mam ją zarchiwizować?', 'beneficiary', 'b_19'],
    [null, 'op1', 'system', 'Nowe zadanie odnowienia', 'Przydzielono Ci zadanie odnowienia podpisu kwalifikowanego.', 'beneficiary', 'b_example'],
    [null, 'op2', 'system', 'Nowe zadanie odnowienia', 'Przydzielono Ci zadanie odnowienia certyfikatu.', null, null],
    [null, 'it2', 'system', 'Certyfikat SSL wygasa', 'Certyfikat SSL jednej z firm wygasa w ciągu 14 dni.', null, null],
    ['emp1', 'op1', 'request', 'Mój podpis', 'Czy mój podpis kwalifikowany zostanie odnowiony automatycznie?', 'beneficiary', 'b_3'],
];
foreach ($notices as $n => [$from, $to, $type, $subject, $body, $relatedType, $relatedVar]) {
    $nv = 'n_' . $n;
    $created = $day(-$rand(0, 30)) . ' ' . sprintf('%02d:%02d:00', $rand(8, 17), $rand(0, 59));
    $insert('notifications', [
        'batch_key'         => substr(hash('sha256', 'etap12-' . $n), 0, 16),
        'thread_id'         => null,
        'parent_id'         => null,
        'sender_user_id'    => $from !== null ? new Raw('@u_' . $from) : null,
        'recipient_user_id' => new Raw('@u_' . $to),
        'type'              => $type,
        'subject'           => $subject,
        'body'              => $body,
        'related_type'      => $relatedType,
        'related_id'        => $relatedVar !== null ? new Raw('@' . $relatedVar) : null,
        'read_at'           => $n % 3 === 0 ? null : $created,
        'resolved_at'       => $type === 'request' && $n % 2 === 0 ? $created : null,
        'created_at'        => $created,
    ], 'batch_key = ' . $q(substr(hash('sha256', 'etap12-' . $n), 0, 16)) . ' AND recipient_user_id = @u_' . $to, $nv);
}

// ─────────────────────────────────────────────────────────── wnioski e-mail (przez prawdziwy czytnik, bez zapisu)

$draftCompanies = array_values(array_filter($registry, static fn (array $c): bool => in_array($c['nip'], $draftOnly, true)));
$records = [];
foreach ($draftCompanies as $company) {
    $records[$company['nip']] = [
        'nip' => $company['nip'], 'name' => $company['name'], 'regon' => $company['regon'], 'krs' => $company['krs'],
        'statusVat' => 'Czynny', 'workingAddress' => $company['street'] . ', ' . $company['postal_code'] . ' ' . $company['city'],
    ];
}
$registryStub = new class ($records) implements CompanyRegistry {
    /** @param array<string, array<string, ?string>> $records */
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
$draftPeople = [['Zuzanna', 'Lis'], ['Igor', 'Sadowski'], ['Laura', 'Kaczmarczyk'], ['Wiktor', 'Bielecki'], ['Hanna', 'Czarnecka'], ['Tymon', 'Urbański']];
foreach ($draftPeople as $n => [$first, $last]) {
    $company = $draftCompanies[$n % count($draftCompanies)];
    $email = $ascii($first) . '.' . $ascii($last) . '@example.com';
    $received = $day(-$rand(0, 12));
    $raw = "From: {$first} {$last} <{$email}>\r\nTo: rejestracja@certisub.example.com\r\nSubject: Wniosek o podpis kwalifikowany — {$first} {$last}\r\n"
        . "Message-ID: <etap12-{$n}@dane-testowe.example>\r\nDate: " . (new DateTimeImmutable($received . ' 10:00:00'))->format('D, d M Y H:i:s O') . "\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
        . "Dzień dobry,\r\nproszę o wydanie podpisu kwalifikowanego.\r\n\r\nImię: {$first}\r\nNazwisko: {$last}\r\nE-mail: {$email}\r\n"
        . "Firma: {$company['name']}\r\nNIP: {$company['nip']}\r\nTyp certyfikatu: podpis kwalifikowany\r\nOkres: 2 lata\r\n\r\n(wiadomość z danych testowych)\r\n";

    $db->beginTransaction();
    try {
        $result = (new RegistrationIntakeService($db, $registryStub))->ingest($raw, $n % 2 === 0 ? 'imap' : 'webhook', null);
        $row = $result['draft_id'] !== null
            ? $db->query('SELECT * FROM registration_drafts WHERE id = ' . (int) $result['draft_id'])->fetch()
            : false;
    } finally {
        $db->rollBack();
    }
    if ($row === false) {
        continue;
    }
    // Czytnik zapisał kopię wiadomości — dane testowe jej nie potrzebują.
    if (is_string($row['raw_file'] ?? null) && $row['raw_file'] !== '') {
        @unlink(dirname(__DIR__) . '/storage/intake/' . basename($row['raw_file']));
    }
    $status = $n === 5 ? 'rejected' : 'pending';
    $insert('registration_drafts', [
        'status' => $status, 'source' => $row['source'], 'message_id' => $row['message_id'], 'content_hash' => $row['content_hash'],
        'sender_email' => $row['sender_email'], 'sender_name' => $row['sender_name'], 'subject' => $row['subject'], 'received_at' => $row['received_at'],
        'body_text' => $row['body_text'], 'raw_file' => null, 'extracted' => $row['extracted'], 'form' => $row['form'],
        'company_lookup' => $row['company_lookup'], 'warnings' => $row['warnings'],
        'assigned_user_id' => $n % 3 === 0 ? new Raw('@u_op2') : null,
        'reviewed_by_user_id' => $status === 'rejected' ? new Raw('@u_manager1') : null,
        'reviewed_at' => $status === 'rejected' ? $received . ' 15:00:00' : null,
        'rejection_reason' => $status === 'rejected' ? 'Wniosek zdublowany (dane testowe).' : null,
        'created_at' => $received . ' 10:01:00',
    ], 'content_hash = ' . $q($row['content_hash']), 'r_' . $n);
}

// ─────────────────────────────────────────────────────────── wynik

$statements[] = "SELECT 'Etap 12 — dane testowe dopisane' AS info;";
$sql = '-- CertiSub — dane testowe Etapu 12 (historia firm, płatnicy rok do roku). Wygenerowano ' . date('Y-m-d H:i') . ".\n"
    . "-- Skrypt tylko DOPISUJE wiersze, których jeszcze nie ma; niczego nie usuwa ani nie zmienia (poza powiązaniem\n"
    . "-- dwóch nowych kont EMPLOYEE z osobami i archiwizacją dwóch nowych firm bez aktywnych rekordów).\n"
    . "-- Wymaga wcześniejszej migracji company_history (deploy-migrate.php). Dane osób są fikcyjne (@example.com).\n\n"
    . implode("\n", $statements) . "\n";

if ($sqlPath !== null) {
    file_put_contents($sqlPath, $sql);
    printf("Zapisano %d instrukcji SQL do %s\n", count($statements), $sqlPath);
    exit(0);
}

$before = [];
foreach (['payers', 'users', 'beneficiaries', 'beneficiary_employments', 'certificates', 'certificate_payers', 'renewal_tasks', 'invitations', 'notifications', 'registration_drafts', 'events'] as $table) {
    $before[$table] = (int) $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}
$db->beginTransaction();
try {
    foreach ($statements as $statement) {
        $result = $db->query($statement);
        if ($result !== false) {
            $result->closeCursor();
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Błąd: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
App\Cache::invalidate();

echo "Dane testowe Etapu 12 dopisane (przyrost):\n";
foreach ($before as $table => $count) {
    $after = (int) $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    printf("  %-26s +%d (razem %d)\n", $table, $after - $count, $after);
}
echo "Hasło kont testowych (@example.com, bez ADMIN): {$password}\n";
exit(0);

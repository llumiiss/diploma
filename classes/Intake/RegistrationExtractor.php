<?php

declare(strict_types=1);

namespace App\Intake;

use App\Exchange\EmlMessage;
use App\Exchange\Normalizer;
use App\Service\EmlImportService;
use App\Service\Validator;

/**
 * Wyciąga z wiadomości e-mail z wnioskiem dane użytkownika, firmy (NIP) i certyfikatu (Etap 10).
 *
 * Wniosek bywa formularzem „Etykieta: wartość”, tabelą HTML, listą wypunktowaną albo zwykłym zdaniem,
 * dlatego ekstraktor jest odporny na zapis: rozpoznaje etykiety po polsku i angielsku (bez znaków
 * diakrytycznych i interpunkcji), rozumie nagłówki sekcji („Dane firmy”, „Dane wnioskodawcy”), czyta
 * wiersze tabel HTML i pary rozdzielone tabulatorem albo kreską pionową. Braki uzupełnia z nagłówków
 * wiadomości (nadawca) i z treści (poprawny NIP z sumą kontrolną, telefon, nazwa wystawcy).
 *
 * Wynik to propozycja do sprawdzenia przez operatora, a nie dane zaufane: każde pole ma zapisane
 * źródło (etykieta, nagłówek, treść, domyślna), a wątpliwości trafiają do listy ostrzeżeń.
 * Ekstraktor nie zna bazy i sieci — jest czystą funkcją wiadomości, więc łatwo go testować.
 */
final class RegistrationExtractor
{
    /** Etykiety (klucz Normalizer::key) → pole. Pola ogólne (email, phone, name) rozstrzyga sekcja. */
    private const LABELS = [
        'person.first_name' => ['imie', 'imiona', 'firstname', 'givenname', 'imieuzytkownika', 'imiewnioskodawcy'],
        'person.last_name'  => ['nazwisko', 'lastname', 'surname', 'familyname', 'nazwiskouzytkownika', 'nazwiskowniskodawcy', 'nazwiskownioskodawcy'],
        'person.full_name'  => ['imieinazwisko', 'imienazwisko', 'wnioskodawca', 'subskrybent', 'uzytkownikcertyfikatu', 'uzytkownik', 'osoba', 'fullname', 'applicant', 'subscriber', 'osobakontaktowa', 'kontakt'],
        'person.full_name_reversed' => ['nazwiskoiimie'],
        'person.email'      => ['emailosoby', 'emailuzytkownika', 'emailwnioskodawcy', 'emailsubskrybenta', 'emailkontaktowy', 'emailosobykontaktowej'],
        'person.phone'      => ['telefonosoby', 'telefonuzytkownika', 'telefonwnioskodawcy', 'telefonkontaktowy', 'telefonkomorkowy', 'komorka', 'mobile', 'nrtelefonukomorkowego'],
        'company.name'      => ['firma', 'nazwafirmy', 'nazwapodmiotu', 'podmiot', 'organizacja', 'company', 'companyname', 'organization', 'organisation', 'pracodawca', 'nazwaorganizacji', 'nazwapracodawcy', 'nazwaplatnika', 'platnik'],
        'company.nip'       => ['nip', 'nipfirmy', 'nippodmiotu', 'numernip', 'vat', 'vatid', 'vatnumber', 'taxid', 'nipplatnika', 'nippracodawcy', 'nipwnioskodawcy'],
        'company.email'     => ['emailfirmy', 'emailpodmiotu', 'emailfirmowy', 'emailorganizacji', 'emailplatnika', 'emaildofaktur'],
        'company.phone'     => ['telefonfirmy', 'telefonpodmiotu', 'telefonfirmowy', 'telefonorganizacji'],
        'company.address'   => ['adres', 'adresfirmy', 'adrespodmiotu', 'ulica', 'adresdokorespondencji', 'address', 'street', 'ulicaanumer', 'adressiedziby', 'siedziba'],
        'company.postal_code' => ['kodpocztowy', 'kod', 'zip', 'postalcode', 'zipcode', 'kodpocztowyfirmy'],
        'company.city'      => ['miasto', 'miejscowosc', 'city', 'town', 'miastofirmy'],
        'cert.type'         => ['rodzajcertyfikatu', 'typcertyfikatu', 'certyfikat', 'produkt', 'usluga', 'certificatetype', 'product', 'rodzaj', 'typ', 'zamawianyprodukt', 'przedmiotzamowienia', 'zamawianycertyfikat', 'rodzajuslugi'],
        'cert.serial'       => ['numerseryjny', 'nrseryjny', 'serial', 'serialnumber', 'sn', 'numercertyfikatu', 'nrcertyfikatu'],
        'cert.issuer'       => ['wystawca', 'dostawca', 'urzadcertyfikacji', 'ca', 'issuer', 'provider', 'centrumcertyfikacji', 'dostawcauslugzaufania'],
        'cert.valid_from'   => ['waznyod', 'datawydania', 'poczatekwaznosci', 'validfrom', 'datarozpoczecia', 'waznoscod', 'datawaznosciod', 'startdate'],
        'cert.expiry'       => ['waznydo', 'datawaznosci', 'datawygasniecia', 'koniecwaznosci', 'validto', 'validuntil', 'expires', 'expiry', 'expirydate', 'waznoscdo', 'datawaznoscido', 'dataumowy'],
        'cert.validity'     => ['okreswaznosci', 'waznosc', 'okres', 'validity', 'period', 'okreswaznoscicertyfikatu', 'czastrwania', 'liczbalat'],
        'cert.discount'     => ['rabat', 'upust', 'discount', 'rabatprocentowy', 'wysokoscrabatu'],
        'cert.notes'        => ['uwagi', 'komentarz', 'dodatkoweinformacje', 'notatki', 'notes', 'comment', 'comments', 'uwagidozamowienia', 'dodatkoweuwagi'],
        'cert.name'         => ['nazwacertyfikatu', 'nazwauslugi'],
        // Ogólne — sekcja albo wcześniejsze pola rozstrzygają, czyje to dane.
        'generic.email'     => ['email', 'mail', 'adresemail', 'emailadres', 'eadres', 'adresemailowy', 'poczta'],
        'generic.phone'     => ['telefon', 'tel', 'nrtelefonu', 'numertelefonu', 'phone', 'telephone', 'numerkontaktowy'],
        'generic.name'      => ['nazwa', 'name'],
    ];

    /** Nagłówki sekcji (klucz Normalizer::key) → sekcja. */
    private const SECTIONS = [
        'company'     => ['danefirmy', 'danepodmiotu', 'firma', 'danepracodawcy', 'pracodawca', 'daneorganizacji', 'organizacja', 'companydetails', 'company', 'daneplatnika', 'platnik', 'danedofaktury', 'danefirmowe', 'organization'],
        'person'      => ['daneosobowe', 'danewnioskodawcy', 'daneuzytkownika', 'wnioskodawca', 'uzytkownikcertyfikatu', 'danesubskrybenta', 'subskrybent', 'applicant', 'userdetails', 'user', 'osoba', 'daneosoby', 'daneuzytkownikacertyfikatu', 'danekontaktowe'],
        'certificate' => ['danecertyfikatu', 'certyfikat', 'zamowienie', 'produkt', 'certificatedetails', 'order', 'zamawianycertyfikat', 'danezamowienia', 'certificate', 'parametrycertyfikatu'],
    ];

    /** Wartości oznaczające „brak danych”. */
    private const EMPTY_VALUES = ['', '-', '--', '—', '–', 'brak', 'nd', 'n/d', 'none', 'null', 'nie dotyczy', 'x', '...', '…'];

    /** Rozpoznawani wystawcy: fragment tekstu → nazwa do zapisania. */
    private const KNOWN_ISSUERS = [
        'certum'    => 'Certum',
        'szafir'    => 'KIR Szafir',
        'sigillum'  => 'PWPW Sigillum',
        'eurocert'  => 'EuroCert',
    ];

    /** Typy certyfikatów rozpoznawane po słowach kluczowych (klucz Normalizer::key zawiera fragment). */
    private const TYPE_KEYWORDS = [
        'QUALIFIED_SEAL'      => ['pieczec', 'pieczeci', 'seal'],
        'QUALIFIED_SIGNATURE' => ['podpis', 'signature', 'kwalifikowan', 'qualified'],
        'CODE_SIGNING'        => ['podpiskodu', 'codesigning', 'codesign'],
        'SSL_CERTIFICATE'     => ['ssl', 'tls', 'https'],
        'DOMAIN'              => ['domen', 'domain'],
    ];

    /**
     * @return array{
     *     person: array{first_name: ?string, last_name: ?string, email: ?string, phone: ?string},
     *     company: array{name: ?string, nip: ?string, email: ?string, phone: ?string, address_line: ?string, postal_code: ?string, city: ?string},
     *     certificate: array{certificate_type: string, serial_number: ?string, issuer: ?string, valid_from: ?string, expiry_date: ?string, validity_months: ?int, discount_percent: ?float, notes: ?string, name: ?string},
     *     sources: array<string, string>,
     *     warnings: list<array{code: string, field?: string, params?: array<string, string>}>,
     *     recognized: int,
     *     looks_like_registration: bool
     * }
     */
    public static function extract(EmlMessage $message): array
    {
        $found = [];
        $warnings = [];
        $lines = array_merge(self::lines($message->text), self::tableLines($message->html));
        $section = null;

        foreach ($lines as $line) {
            $heading = self::heading($line);
            if ($heading !== null) {
                $section = $heading;
                continue;
            }

            $pair = self::pair($line);
            if ($pair === null) {
                continue;
            }

            [$label, $value] = $pair;
            $field = self::resolveField(Normalizer::key($label), $section);
            if ($field === null || isset($found[$field]) || self::isEmptyValue($value)) {
                continue;
            }
            $found[$field] = $value;
        }

        $sources = [];
        $recognized = count($found);

        $person = self::person($found, $message, $sources, $warnings);
        $company = self::company($found, $message, $sources, $warnings);
        $certificate = self::certificate($found, $message, $sources, $warnings);

        $subject = Normalizer::key((string) $message->subject);
        $keywordInSubject = preg_match('/wniosek|rejestrac|certyfikat|podpis|pieczec|certificate|signature|registration/', $subject) === 1;

        return [
            'person'      => $person,
            'company'     => $company,
            'certificate' => $certificate,
            'sources'     => $sources,
            'warnings'    => $warnings,
            'recognized'  => $recognized,
            'looks_like_registration' => $company['nip'] !== null || $recognized >= 3 || ($keywordInSubject && $recognized >= 1),
        ];
    }

    // --------------------------------------------------------------- składanie pól

    /**
     * @param array<string, string>                                  $found
     * @param array<string, string>                                  $sources
     * @param list<array{code: string, field?: string, params?: array<string, string>}> $warnings
     * @return array{first_name: ?string, last_name: ?string, email: ?string, phone: ?string}
     */
    private static function person(array $found, EmlMessage $message, array &$sources, array &$warnings): array
    {
        $first = $found['person.first_name'] ?? null;
        $last = $found['person.last_name'] ?? null;
        if ($first !== null || $last !== null) {
            $sources['person.first_name'] = $sources['person.last_name'] = 'label';
        } else {
            [$first, $last, $origin] = self::names($found, $message);
            if ($first !== null || $last !== null) {
                $sources['person.first_name'] = $sources['person.last_name'] = $origin;
            }
        }
        if ($first !== null && $last === null) {
            // Samo imię — nazwisko bywa w tym samym polu („Jan Kowalski”).
            $split = self::splitName($first);
            [$first, $last] = [$split[0], $split[1]];
        }

        $email = self::email($found['person.email'] ?? null);
        if ($email !== null) {
            $sources['person.email'] = 'label';
        } else {
            $email = $message->from['email'];
            if ($email !== null) {
                $sources['person.email'] = 'header';
            }
        }

        $phone = self::phone($found['person.phone'] ?? null);
        if ($phone !== null) {
            $sources['person.phone'] = 'label';
        } else {
            $phones = EmlImportService::phones($message->text);
            if ($phones !== []) {
                $phone = $phones[0];
                $sources['person.phone'] = 'text';
            }
        }

        if ($first === null && $last === null) {
            $warnings[] = ['code' => 'person_missing', 'field' => 'person'];
        }

        return ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone];
    }

    /**
     * @param array<string, string> $found
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private static function names(array $found, EmlMessage $message): array
    {
        if (isset($found['person.full_name'])) {
            $split = self::splitName($found['person.full_name']);

            return [$split[0], $split[1], 'label'];
        }
        if (isset($found['person.full_name_reversed'])) {
            $split = self::splitName($found['person.full_name_reversed']);

            return [$split[1], $split[0], 'label'];
        }

        $fromName = trim((string) ($message->from['name'] ?? ''));
        if ($fromName !== '' && !str_contains($fromName, '@')) {
            if (str_contains($fromName, ',')) {
                [$last, $first] = array_map('trim', explode(',', $fromName, 2));

                return [$first !== '' ? $first : null, $last !== '' ? $last : null, 'header'];
            }
            $split = self::splitName($fromName);

            return [$split[0], $split[1], 'header'];
        }

        return [null, null, 'default'];
    }

    /**
     * @param array<string, string>                                  $found
     * @param array<string, string>                                  $sources
     * @param list<array{code: string, field?: string, params?: array<string, string>}> $warnings
     * @return array{name: ?string, nip: ?string, email: ?string, phone: ?string, address_line: ?string, postal_code: ?string, city: ?string}
     */
    private static function company(array $found, EmlMessage $message, array &$sources, array &$warnings): array
    {
        $nip = null;
        if (isset($found['company.nip'])) {
            $normalized = Validator::normalizeTaxId($found['company.nip']);
            if (Validator::isValidTaxId($normalized)) {
                $nip = $normalized;
                $sources['company.nip'] = 'label';
            } else {
                $warnings[] = ['code' => 'nip_invalid', 'field' => 'company.nip', 'params' => ['value' => $found['company.nip']]];
            }
        }

        if ($nip === null) {
            $candidates = EmlImportService::taxIds(($message->subject ?? '') . "\n" . $message->text);
            if ($candidates !== []) {
                $nip = $candidates[0];
                $sources['company.nip'] = 'text';
                if (count($candidates) > 1) {
                    $warnings[] = ['code' => 'nip_multiple', 'field' => 'company.nip', 'params' => ['value' => implode(', ', $candidates)]];
                }
            }
        }

        if ($nip === null && !isset($found['company.nip'])) {
            $warnings[] = ['code' => 'nip_missing', 'field' => 'company.nip'];
        }

        $email = self::email($found['company.email'] ?? null);
        $phone = self::phone($found['company.phone'] ?? null);

        $address = [
            'address_line' => $found['company.address'] ?? null,
            'postal_code'  => isset($found['company.postal_code']) ? self::postalCode($found['company.postal_code']) : null,
            'city'         => $found['company.city'] ?? null,
        ];

        // Adres w jednej linii: „ul. Przemysłowa 12, 40-020 Katowice”.
        if ($address['address_line'] !== null && $address['postal_code'] === null
            && preg_match('/^(.*?),?\s*(\d{2}-\d{3})\s+(.+)$/u', $address['address_line'], $m) === 1) {
            $address = ['address_line' => trim($m[1], ' ,'), 'postal_code' => $m[2], 'city' => $address['city'] ?? trim($m[3])];
        }

        foreach (['name' => $found['company.name'] ?? null, 'email' => $email, 'phone' => $phone] + $address as $key => $value) {
            if ($value !== null && $value !== '') {
                $sources['company.' . $key] = 'label';
            }
        }

        return [
            'name'         => $found['company.name'] ?? null,
            'nip'          => $nip,
            'email'        => $email,
            'phone'        => $phone,
            'address_line' => $address['address_line'],
            'postal_code'  => $address['postal_code'],
            'city'         => $address['city'],
        ];
    }

    /**
     * @param array<string, string>                                  $found
     * @param array<string, string>                                  $sources
     * @param list<array{code: string, field?: string, params?: array<string, string>}> $warnings
     * @return array{certificate_type: string, serial_number: ?string, issuer: ?string, valid_from: ?string, expiry_date: ?string, validity_months: ?int, discount_percent: ?float, notes: ?string, name: ?string}
     */
    private static function certificate(array $found, EmlMessage $message, array &$sources, array &$warnings): array
    {
        [$type, $typeSource] = self::certificateType($found['cert.type'] ?? null, $message);
        $sources['certificate.certificate_type'] = $typeSource;
        if ($typeSource === 'default') {
            $warnings[] = ['code' => 'type_default', 'field' => 'certificate.certificate_type'];
        }

        $dates = [];
        foreach (['valid_from' => 'cert.valid_from', 'expiry_date' => 'cert.expiry'] as $field => $key) {
            $dates[$field] = null;
            if (!isset($found[$key])) {
                continue;
            }
            $date = Normalizer::date($found[$key]);
            if (Validator::isValidDate($date)) {
                $dates[$field] = $date;
                $sources['certificate.' . $field] = 'label';
            } else {
                $warnings[] = ['code' => 'date_invalid', 'field' => 'certificate.' . $field, 'params' => ['value' => $found[$key]]];
            }
        }

        $months = isset($found['cert.validity']) ? self::months($found['cert.validity']) : null;
        if ($months !== null) {
            $sources['certificate.validity_months'] = 'label';
        } elseif (isset($found['cert.validity'])) {
            $warnings[] = ['code' => 'validity_unreadable', 'field' => 'certificate.validity_months', 'params' => ['value' => $found['cert.validity']]];
        }

        $discount = null;
        if (isset($found['cert.discount'])) {
            $check = new Validator(['discount' => $found['cert.discount']]);
            $parsed = $check->discountPercent('discount');
            if ($check->fails()) {
                $warnings[] = ['code' => 'discount_invalid', 'field' => 'certificate.discount_percent', 'params' => ['value' => $found['cert.discount']]];
            } else {
                $discount = $parsed;
                $sources['certificate.discount_percent'] = 'label';
            }
        }

        $issuer = isset($found['cert.issuer']) ? $found['cert.issuer'] : null;
        if ($issuer !== null) {
            $sources['certificate.issuer'] = 'label';
        } else {
            $issuer = self::knownIssuer(($message->subject ?? '') . "\n" . $message->text);
            if ($issuer !== null) {
                $sources['certificate.issuer'] = 'text';
            }
        }

        foreach (['serial_number' => 'cert.serial', 'notes' => 'cert.notes', 'name' => 'cert.name'] as $field => $key) {
            if (isset($found[$key])) {
                $sources['certificate.' . $field] = 'label';
            }
        }

        return [
            'certificate_type' => $type,
            'serial_number'    => $found['cert.serial'] ?? null,
            'issuer'           => $issuer,
            'valid_from'       => $dates['valid_from'],
            'expiry_date'      => $dates['expiry_date'],
            'validity_months'  => $months,
            'discount_percent' => $discount,
            'notes'            => $found['cert.notes'] ?? null,
            'name'             => $found['cert.name'] ?? null,
        ];
    }

    /**
     * @return array{0: string, 1: string} typ i źródło (label | keyword | default)
     */
    private static function certificateType(?string $value, EmlMessage $message): array
    {
        if ($value !== null) {
            $type = self::typeFromKeywords(Normalizer::key($value));
            if ($type !== null) {
                return [$type, 'label'];
            }
        }

        $type = self::typeFromKeywords(Normalizer::key(($message->subject ?? '') . ' ' . mb_substr($message->text, 0, 2000)));

        return $type !== null ? [$type, 'keyword'] : ['QUALIFIED_SIGNATURE', 'default'];
    }

    private static function typeFromKeywords(string $key): ?string
    {
        foreach (self::TYPE_KEYWORDS as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($key, $keyword)) {
                    return $type;
                }
            }
        }

        return null;
    }

    private static function knownIssuer(string $text): ?string
    {
        $key = Normalizer::key($text);
        $matches = [];
        foreach (self::KNOWN_ISSUERS as $fragment => $name) {
            if (str_contains($key, $fragment)) {
                $matches[] = $name;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Okres ważności w miesiącach: „2 lata”, „1 rok”, „36 miesięcy”, „3 years”.
     */
    public static function months(string $value): ?int
    {
        if (preg_match('/(\d{1,3})\s*(?:-\s*)?(lat\w*|rok\w*|r\b|year\w*|yr\w*|y\b|miesi\w*|mies\b|month\w*|m\b)/iu', $value, $m) !== 1) {
            return null;
        }

        $number = (int) $m[1];
        $unit = mb_strtolower($m[2]);
        $months = preg_match('/^(lat|rok|r$|year|yr|y$)/u', $unit) === 1 ? $number * 12 : $number;

        return $months >= 1 && $months <= 120 ? $months : null;
    }

    // ------------------------------------------------------------- czytanie linii

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Wiersze tabel HTML jako „Etykieta: wartość” (dwie komórki) albo kolejne pary komórek (cztery i więcej).
     *
     * @return list<string>
     */
    private static function tableLines(string $html): array
    {
        if ($html === '' || stripos($html, '<tr') === false) {
            return [];
        }

        $lines = [];
        preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $html, $rows);
        foreach ($rows[1] as $row) {
            preg_match_all('#<t[dh]\b[^>]*>(.*?)</t[dh]>#is', $row, $cells);
            $texts = array_map(
                static fn (string $cell): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                $cells[1]
            );
            if (count($texts) === 1 && $texts[0] !== '') {
                $lines[] = $texts[0];
                continue;
            }
            for ($i = 0; $i + 1 < count($texts); $i += 2) {
                if ($texts[$i] !== '') {
                    $lines[] = rtrim($texts[$i], ': ') . ': ' . $texts[$i + 1];
                }
            }
        }

        return $lines;
    }

    /**
     * Nagłówek sekcji: krótka linia bez wartości („Dane firmy:”, „=== Dane wnioskodawcy ===”).
     */
    private static function heading(string $line): ?string
    {
        $stripped = trim($line, " \t:-=*#_>|");
        if ($stripped === '' || mb_strlen($stripped) > 40 || str_contains($stripped, ':')) {
            return null;
        }
        // Linia z wartością po dwukropku nie jest nagłówkiem — tu już jej nie ma, bo dwukropek obcięto z brzegu.
        if (preg_match('/:\s*\S/u', $line) === 1) {
            return null;
        }

        $key = Normalizer::key($stripped);
        foreach (self::SECTIONS as $section => $keys) {
            if (in_array($key, $keys, true)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null etykieta i wartość
     */
    private static function pair(string $line): ?array
    {
        $line = trim($line);
        $line = (string) preg_replace('/^[\s>\-\*\x{2022}\x{25CF}\x{25AA}]+/u', '', $line);

        if (str_starts_with($line, '|')) {
            $cells = array_values(array_filter(array_map('trim', explode('|', $line)), static fn (string $cell): bool => $cell !== ''));

            return count($cells) >= 2 && !preg_match('/^[-: ]+$/', $cells[0]) ? [$cells[0], $cells[1]] : null;
        }

        if (preg_match('/^([^:=\t|]{1,60}?)\s*(?::|=|\t+|\|)\s*(.*)$/u', $line, $m) !== 1) {
            return null;
        }

        $label = trim($m[1]);
        $value = trim($m[2], " \t\"'");
        if ($label === '' || preg_match('/^\d+$/', $label) === 1) {
            return null;
        }

        return [$label, $value];
    }

    private static function resolveField(string $labelKey, ?string $section): ?string
    {
        if ($labelKey === '') {
            return null;
        }

        foreach (self::LABELS as $field => $keys) {
            if (in_array($labelKey, $keys, true)) {
                return self::applySection($field, $section);
            }
        }

        return null;
    }

    private static function applySection(string $field, ?string $section): ?string
    {
        return match ($field) {
            'generic.email' => $section === 'company' ? 'company.email' : 'person.email',
            'generic.phone' => $section === 'company' ? 'company.phone' : 'person.phone',
            'generic.name'  => match ($section) {
                'company'     => 'company.name',
                'certificate' => 'cert.name',
                'person'      => 'person.full_name',
                default       => null,
            },
            // „Certyfikat” albo „Produkt” w sekcji firmy to nie rodzaj certyfikatu, a „Firma” w sekcji osoby — nie nazwa firmy.
            'cert.type'    => $section === 'company' || $section === 'person' ? null : $field,
            default        => $field,
        };
    }

    private static function isEmptyValue(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::EMPTY_VALUES, true);
    }

    // ----------------------------------------------------------- normalizacja wartości

    private static function email(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $value, $m) === 1 ? mb_strtolower($m[0]) : null;
    }

    private static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $phone = trim((string) preg_replace('/[^0-9+()\/.\- ]+/', '', $value));

        return strlen((string) preg_replace('/\D/', '', $phone)) >= 7 ? $phone : null;
    }

    private static function postalCode(string $value): string
    {
        return preg_match('/\d{2}-\d{3}/', $value, $m) === 1 ? $m[0] : (preg_match('/^(\d{2})(\d{3})$/', trim($value), $n) === 1 ? $n[1] . '-' . $n[2] : trim($value));
    }

    /**
     * „dr inż. Jan Maria Kowalski” → [Jan Maria, Kowalski]; pojedyncze słowo to samo imię.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function splitName(string $fullName): array
    {
        $fullName = trim((string) preg_replace('/\b(?:dr|mgr|inż|inz|prof|lek|hab)\.?\s+/iu', '', $fullName));
        $parts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return [null, null];
        }
        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }
}

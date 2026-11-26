<?php

declare(strict_types=1);

namespace App\Intake;

use App\Exchange\EmlMessage;
use App\Exchange\Normalizer;
use App\Service\EmlImportService;
use App\Service\TemplateRenderer;
use App\Service\Validator;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Wyciąga z wiadomości e-mail z wnioskiem dane użytkownika, firmy (NIP) i certyfikatu (Etap 10).
 *
 * Wniosek bywa formularzem „Etykieta: wartość”, tabelą HTML, listą wypunktowaną albo zwykłym zdaniem,
 * dlatego ekstraktor jest odporny na zapis: rozpoznaje etykiety po polsku i angielsku (bez znaków
 * diakrytycznych i interpunkcji), rozumie nagłówki sekcji („Dane firmy”, „Dane wnioskodawcy”), czyta
 * wiersze tabel HTML, pary rozdzielone tabulatorem albo kreską pionową i wartości stojące linijkę pod
 * etykietą. Braki uzupełnia z nagłówków wiadomości (nadawca) i z treści (poprawny NIP z sumą kontrolną,
 * telefon, nazwa wystawcy). Własne etykiety można dodać szablonami odczytu (Etap 11).
 *
 * Powiadomienia dostawcy certyfikatów („podpis czeka na instalację”, „podpis wygaśnie za 60 dni”) opisują
 * klienta, ale wysyła je automat dostawcy: nadawca nie jest wtedy wnioskodawcą, a stopka z NIP-em dostawcy
 * nie jest danymi firmy klienta.
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
        'person.full_name'  => ['imieinazwisko', 'imienazwisko', 'wnioskodawca', 'subskrybent', 'uzytkownikcertyfikatu', 'uzytkownik', 'osoba', 'fullname', 'applicant', 'subscriber', 'osobakontaktowa', 'kontakt', 'wydanydla', 'wystawionydla', 'certyfikatwydanydla', 'posiadaczcertyfikatu', 'issuedto'],
        'person.full_name_reversed' => ['nazwiskoiimie'],
        'person.email'      => ['emailosoby', 'emailuzytkownika', 'emailwnioskodawcy', 'emailsubskrybenta', 'emailkontaktowy', 'emailosobykontaktowej', 'emailklienta'],
        'person.phone'      => ['telefonosoby', 'telefonuzytkownika', 'telefonwnioskodawcy', 'telefonkontaktowy', 'telefonkomorkowy', 'komorka', 'mobile', 'nrtelefonukomorkowego', 'telefonklienta'],
        'company.name'      => ['firma', 'nazwafirmy', 'nazwapodmiotu', 'podmiot', 'organizacja', 'company', 'companyname', 'organization', 'organisation', 'pracodawca', 'nazwaorganizacji', 'nazwapracodawcy', 'nazwaplatnika', 'platnik'],
        'company.nip'       => ['nip', 'nipfirmy', 'nippodmiotu', 'numernip', 'vat', 'vatid', 'vatnumber', 'taxid', 'nipplatnika', 'nippracodawcy', 'nipwnioskodawcy'],
        'company.email'     => ['emailfirmy', 'emailpodmiotu', 'emailfirmowy', 'emailorganizacji', 'emailplatnika', 'emaildofaktur'],
        'company.phone'     => ['telefonfirmy', 'telefonpodmiotu', 'telefonfirmowy', 'telefonorganizacji'],
        'company.address'   => ['adres', 'adresfirmy', 'adrespodmiotu', 'ulica', 'adresdokorespondencji', 'address', 'street', 'ulicaanumer', 'adressiedziby', 'siedziba'],
        'company.postal_code' => ['kodpocztowy', 'kod', 'zip', 'postalcode', 'zipcode', 'kodpocztowyfirmy'],
        'company.city'      => ['miasto', 'miejscowosc', 'city', 'town', 'miastofirmy'],
        'cert.type'         => ['rodzajcertyfikatu', 'typcertyfikatu', 'certyfikat', 'produkt', 'usluga', 'certificatetype', 'product', 'rodzaj', 'typ', 'zamawianyprodukt', 'przedmiotzamowienia', 'zamawianycertyfikat', 'rodzajuslugi'],
        'cert.product'      => ['ekod', 'nazwaproduktu', 'kodproduktu'],
        'cert.serial'       => ['numerseryjny', 'nrseryjny', 'serial', 'serialnumber', 'sn', 'numercertyfikatu', 'nrcertyfikatu', 'numerseryjnycertyfikatu', 'nrseryjnycertyfikatu'],
        'cert.card'         => ['numerkarty', 'nrkarty', 'cardnumber', 'numerseryjnykarty'],
        'cert.issuer'       => ['wystawca', 'dostawca', 'urzadcertyfikacji', 'ca', 'issuer', 'provider', 'centrumcertyfikacji', 'dostawcauslugzaufania'],
        'cert.valid_from'   => ['waznyod', 'datawydania', 'poczatekwaznosci', 'validfrom', 'datarozpoczecia', 'waznoscod', 'datawaznosciod', 'startdate', 'poczatekwaznoscicertyfikatu', 'datawydaniacertyfikatu'],
        'cert.expiry'       => ['waznydo', 'datawaznosci', 'datawygasniecia', 'koniecwaznosci', 'validto', 'validuntil', 'expires', 'expiry', 'expirydate', 'waznoscdo', 'datawaznoscido', 'dataumowy', 'koniecwaznoscicertyfikatu', 'datawygasnieciacertyfikatu'],
        'cert.validity'     => ['okreswaznosci', 'waznosc', 'okres', 'validity', 'period', 'okreswaznoscicertyfikatu', 'czastrwania', 'liczbalat'],
        'cert.discount'     => ['rabat', 'upust', 'discount', 'rabatprocentowy', 'wysokoscrabatu'],
        'cert.notes'        => ['uwagi', 'komentarz', 'dodatkoweinformacje', 'notatki', 'notes', 'comment', 'comments', 'uwagidozamowienia', 'dodatkoweuwagi'],
        'cert.name'         => ['nazwacertyfikatu', 'nazwauslugi'],
        // Ogólne — sekcja albo wcześniejsze pola rozstrzygają, czyje to dane.
        'generic.email'     => ['email', 'mail', 'adresemail', 'emailadres', 'eadres', 'adresemailowy', 'poczta'],
        'generic.phone'     => ['telefon', 'tel', 'nrtelefonu', 'numertelefonu', 'phone', 'telephone', 'numerkontaktowy'],
        'generic.name'      => ['nazwa', 'name'],
    ];

    /** Etykiety, które bywają samotną linią (bez dwukropka), a wartość stoi pod nimi. */
    private const NEXT_LINE_LABELS = ['wydanydla', 'wystawionydla', 'certyfikatwydanydla', 'posiadaczcertyfikatu', 'issuedto'];

    /** Nagłówki sekcji (klucz Normalizer::key) → sekcja. */
    private const SECTIONS = [
        'company'     => ['danefirmy', 'danepodmiotu', 'firma', 'danepracodawcy', 'pracodawca', 'daneorganizacji', 'organizacja', 'companydetails', 'company', 'daneplatnika', 'platnik', 'danedofaktury', 'danefirmowe', 'organization'],
        'person'      => ['daneosobowe', 'danewnioskodawcy', 'daneuzytkownika', 'wnioskodawca', 'uzytkownikcertyfikatu', 'danesubskrybenta', 'subskrybent', 'applicant', 'userdetails', 'user', 'osoba', 'daneosoby', 'daneuzytkownikacertyfikatu', 'danekontaktowe'],
        'certificate' => ['danecertyfikatu', 'certyfikat', 'zamowienie', 'produkt', 'certificatedetails', 'order', 'zamawianycertyfikat', 'danezamowienia', 'certificate', 'parametrycertyfikatu'],
    ];

    /**
     * Limity wejścia: wiadomość ze skrzynki może pochodzić od obcego nadawcy, a prawdziwy wniosek mieści się
     * w kilkunastu liniach. Dłuższa treść jest obcinana, zanim ekstraktor ją przeczyta.
     */
    private const MAX_TEXT_BYTES = 200_000;
    private const MAX_HTML_BYTES = 300_000;
    private const MAX_LINES = 4000;
    private const MAX_LINE_CHARS = 1000;
    private const MAX_TABLE_ROWS = 500;

    /** Wartości oznaczające „brak danych”. */
    private const EMPTY_VALUES = ['', '-', '--', '—', '–', 'brak', 'nd', 'n/d', 'none', 'null', 'nie dotyczy', 'x', '...', '…'];

    /** Rozpoznawani wystawcy: fragment tekstu → nazwa do zapisania. */
    private const KNOWN_ISSUERS = [
        'certum'    => 'Certum',
        'szafir'    => 'KIR Szafir',
        'sigillum'  => 'PWPW Sigillum',
        'eurocert'  => 'EuroCert',
    ];

    /** Domeny, z których dostawcy certyfikatów wysyłają automatyczne powiadomienia o klientach. */
    private const VENDOR_DOMAINS = ['certum.pl', 'certum.eu'];

    /** NIP-y dostawców z ich stopek — to nigdy nie jest firma klienta (Asseco Data Systems / Certum). */
    private const VENDOR_TAX_IDS = ['5170359458'];

    /** Skrzynki automatów: ich adres i nazwa nie opisują osoby z wniosku. */
    private const AUTOMATED_MAILBOX = '/^(?:no[-_.]?reply|do[-_.]?not[-_.]?reply|autoresponder\d*|mailer-daemon|postmaster|notifications?|powiadomienia|automat)@/i';

    /** Typy certyfikatów rozpoznawane po słowach kluczowych (klucz Normalizer::key zawiera fragment). */
    private const TYPE_KEYWORDS = [
        'QUALIFIED_SEAL'      => ['pieczec', 'pieczeci', 'seal'],
        'QUALIFIED_SIGNATURE' => ['podpis', 'signature', 'kwalifikowan', 'qualified'],
        'CODE_SIGNING'        => ['podpiskodu', 'codesigning', 'codesign'],
        'SSL_CERTIFICATE'     => ['ssl', 'tls', 'https'],
        'DOMAIN'              => ['domen', 'domain'],
    ];

    /**
     * Pola, które można wskazać w szablonie odczytu (własna etykieta → pole).
     *
     * @return list<string>
     */
    public static function readableFields(): array
    {
        return array_values(array_filter(
            array_keys(self::LABELS),
            static fn (string $field): bool => !str_starts_with($field, 'generic.')
        ));
    }

    /**
     * @param list<array{field: string, label: string}> $rules własne etykiety z szablonów odczytu — mają
     *                                                         pierwszeństwo przed wbudowanymi
     * @return array{
     *     person: array{first_name: ?string, last_name: ?string, email: ?string, phone: ?string},
     *     company: array{name: ?string, nip: ?string, email: ?string, phone: ?string, address_line: ?string, postal_code: ?string, city: ?string},
     *     certificate: array{certificate_type: string, serial_number: ?string, issuer: ?string, valid_from: ?string, expiry_date: ?string, validity_months: ?int, discount_percent: ?float, notes: ?string, name: ?string},
     *     sources: array<string, string>,
     *     warnings: list<array{code: string, field?: string, params?: array<string, string>}>,
     *     recognized: int,
     *     notification: bool,
     *     looks_like_registration: bool
     * }
     */
    public static function extract(EmlMessage $message, array $rules = []): array
    {
        $message = self::limited($message);
        $notification = self::isNotification($message);
        $body = $notification ? self::withoutFooter($message->text) : $message->text;

        $custom = [];
        foreach ($rules as $rule) {
            $key = Normalizer::key($rule['label']);
            if ($key !== '' && in_array($rule['field'], self::readableFields(), true)) {
                $custom[$key] = $rule['field'];
            }
        }

        $tableLines = self::tableLines($message->html);
        if ($notification) {
            $tableLines = array_values(array_filter($tableLines, static fn (string $line): bool => !self::isFooterLine($line)));
        }
        $found = self::read(self::lines($body), $custom) + self::read($tableLines, $custom);

        $warnings = [];
        $sources = [];
        $recognized = count($found);

        $person = self::person($found, $message, $body, $notification, $sources, $warnings);
        $company = self::company($found, $message, $body, $notification, $sources, $warnings);
        $certificate = self::certificate($found, $message, $body, $sources, $warnings);

        if ($notification && preg_match('/(?:wygaśnie|straci\s+ważność|wygasa)\s+za\s+\d+\s+dni/iu', ($message->subject ?? '') . "\n" . $body) === 1) {
            $warnings[] = ['code' => 'renewal_notice', 'field' => 'certificate'];
        }

        $subject = Normalizer::key((string) $message->subject);
        $keywordInSubject = preg_match('/wniosek|rejestrac|certyfikat|podpis|pieczec|certificate|signature|registration/', $subject) === 1;

        return [
            'person'       => $person,
            'company'      => $company,
            'certificate'  => $certificate,
            'sources'      => $sources,
            'warnings'     => $warnings,
            'recognized'   => $recognized,
            'notification' => $notification,
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
    private static function person(array $found, EmlMessage $message, string $body, bool $notification, array &$sources, array &$warnings): array
    {
        $first = $found['person.first_name'] ?? null;
        $last = $found['person.last_name'] ?? null;
        if ($first !== null || $last !== null) {
            $sources['person.first_name'] = $sources['person.last_name'] = 'label';
        } else {
            [$first, $last, $origin] = self::names($found, $message, !$notification);
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
        } elseif ($notification) {
            $email = self::bodyEmail($body, $message, (string) $last, (string) $first);
            if ($email !== null) {
                $sources['person.email'] = 'text';
            }
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
            $phones = EmlImportService::phones($body);
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
    private static function names(array $found, EmlMessage $message, bool $useSender): array
    {
        if (isset($found['person.full_name'])) {
            $split = self::splitName($found['person.full_name']);

            return [$split[0], $split[1], 'label'];
        }
        if (isset($found['person.full_name_reversed'])) {
            $split = self::splitName($found['person.full_name_reversed']);

            return [$split[1], $split[0], 'label'];
        }

        $fromName = $useSender ? trim((string) ($message->from['name'] ?? '')) : '';
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
     * Adres klienta z treści powiadomienia: bez adresów dostawcy, nadawcy i odbiorców; pierwszeństwo ma ten,
     * który zawiera nazwisko albo imię z wiadomości.
     */
    private static function bodyEmail(string $body, EmlMessage $message, string $lastName, string $firstName): ?string
    {
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $body, $matches);

        $excluded = array_filter(array_merge(
            [$message->from['email']],
            array_map(static fn (array $address): ?string => $address['email'], $message->to)
        ));
        $candidates = [];
        foreach ($matches[0] as $match) {
            $email = mb_strtolower($match);
            if (!in_array($email, $excluded, true) && !self::isVendorAddress($email) && !in_array($email, $candidates, true)) {
                $candidates[] = $email;
            }
        }

        foreach ([Normalizer::key($lastName), Normalizer::key($firstName)] as $part) {
            if (strlen($part) < 3) {
                continue;
            }
            foreach ($candidates as $email) {
                if (str_contains(Normalizer::key(strstr($email, '@', true) ?: ''), $part)) {
                    return $email;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param array<string, string>                                  $found
     * @param array<string, string>                                  $sources
     * @param list<array{code: string, field?: string, params?: array<string, string>}> $warnings
     * @return array{name: ?string, nip: ?string, email: ?string, phone: ?string, address_line: ?string, postal_code: ?string, city: ?string}
     */
    private static function company(array $found, EmlMessage $message, string $body, bool $notification, array &$sources, array &$warnings): array
    {
        $nip = null;
        if (isset($found['company.nip'])) {
            $normalized = Validator::normalizeTaxId($found['company.nip']);
            if (Validator::isValidTaxId($normalized) && !in_array($normalized, self::VENDOR_TAX_IDS, true)) {
                $nip = $normalized;
                $sources['company.nip'] = 'label';
            } elseif (!Validator::isValidTaxId($normalized)) {
                $warnings[] = ['code' => 'nip_invalid', 'field' => 'company.nip', 'params' => ['value' => $found['company.nip']]];
            }
        }

        if ($nip === null) {
            $candidates = array_values(array_diff(
                EmlImportService::taxIds(($message->subject ?? '') . "\n" . $body),
                self::VENDOR_TAX_IDS
            ));
            if ($candidates !== []) {
                $nip = $candidates[0];
                $sources['company.nip'] = 'text';
                if (count($candidates) > 1) {
                    $warnings[] = ['code' => 'nip_multiple', 'field' => 'company.nip', 'params' => ['value' => implode(', ', $candidates)]];
                }
            }
        }

        if ($nip === null && $notification) {
            $warnings[] = ['code' => 'company_not_in_message', 'field' => 'company'];
        } elseif ($nip === null && !isset($found['company.nip'])) {
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
    private static function certificate(array $found, EmlMessage $message, string $body, array &$sources, array &$warnings): array
    {
        $product = $found['cert.product'] ?? null;
        [$type, $typeSource] = self::certificateType($found['cert.type'] ?? $product, $message, $body);
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
            $date = self::date($found[$key]);
            if ($date !== null) {
                $dates[$field] = $date;
                $sources['certificate.' . $field] = 'label';
            } else {
                $warnings[] = ['code' => 'date_invalid', 'field' => 'certificate.' . $field, 'params' => ['value' => $found[$key]]];
            }
        }
        if ($dates['expiry_date'] === null && !isset($found['cert.expiry'])) {
            $dates['expiry_date'] = self::expiryFromSentence($body);
            if ($dates['expiry_date'] !== null) {
                $sources['certificate.expiry_date'] = 'text';
            }
        }

        $months = isset($found['cert.validity']) ? self::months($found['cert.validity']) : null;
        if ($months !== null) {
            $sources['certificate.validity_months'] = 'label';
        } elseif (isset($found['cert.validity'])) {
            $warnings[] = ['code' => 'validity_unreadable', 'field' => 'certificate.validity_months', 'params' => ['value' => $found['cert.validity']]];
        } elseif ($product !== null && ($months = self::months($product)) !== null) {
            $sources['certificate.validity_months'] = 'label';
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
            $issuer = self::knownIssuer(($message->subject ?? '') . "\n" . $body);
            if ($issuer !== null) {
                $sources['certificate.issuer'] = 'text';
            } elseif (self::isVendorAddress((string) ($message->from['email'] ?? ''))) {
                // Domeny z VENDOR_DOMAINS należą do Certum — powiadomienie od nich dotyczy ich certyfikatu.
                $issuer = 'Certum';
                $sources['certificate.issuer'] = 'header';
            }
        }

        foreach (['serial_number' => 'cert.serial', 'name' => 'cert.name'] as $field => $key) {
            if (isset($found[$key])) {
                $sources['certificate.' . $field] = 'label';
            }
        }

        $notes = self::notes($found, $body);
        if ($notes !== null) {
            $sources['certificate.notes'] = 'label';
        }

        return [
            'certificate_type' => $type,
            'serial_number'    => $found['cert.serial'] ?? null,
            'issuer'           => $issuer,
            'valid_from'       => $dates['valid_from'],
            'expiry_date'      => $dates['expiry_date'],
            'validity_months'  => $months,
            'discount_percent' => $discount,
            'notes'            => $notes,
            'name'             => $found['cert.name'] ?? null,
        ];
    }

    /**
     * Uwagi z wiadomości i identyfikatory, dla których formularz nie ma osobnego pola (numer wniosku, karty, produkt).
     *
     * @param array<string, string> $found
     */
    private static function notes(array $found, string $body): ?string
    {
        $lines = isset($found['cert.notes']) ? [$found['cert.notes']] : [];
        if (preg_match('/wniosk\w*\s+(?:o\s+)?(?:numerze|numer|nr\.?)\s*:?\s*([A-Z]{0,3}\d{4,12})\b/iu', $body, $m) === 1) {
            $lines[] = \__('registration.note.application', ['value' => $m[1]]);
        }
        if (isset($found['cert.card'])) {
            $lines[] = \__('registration.note.card', ['value' => $found['cert.card']]);
        }
        if (isset($found['cert.product'])) {
            $lines[] = \__('registration.note.product', ['value' => $found['cert.product']]);
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @return array{0: string, 1: string} typ i źródło (label | keyword | default)
     */
    private static function certificateType(?string $value, EmlMessage $message, string $body): array
    {
        if ($value !== null) {
            $type = self::typeFromKeywords(Normalizer::key($value));
            if ($type !== null) {
                return [$type, 'label'];
            }
        }

        $type = self::typeFromKeywords(Normalizer::key(($message->subject ?? '') . ' ' . mb_substr($body, 0, 2000)));

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
     * Data z wartości pola: „17.09.2026”, „2026-09-17”, także z godziną i strefą („14.12.2029 22:59:59 UTC”).
     * Czas UTC przeliczamy na lokalny, bo w ewidencji liczy się dzień w Polsce.
     */
    private static function date(string $value): ?string
    {
        if (preg_match('/(?<!\d)(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4})(?:(?:[ T,]+(?:godz\.?\s*)?)(\d{1,2}:\d{2}(?::\d{2})?))?(?:\s*(UTC|GMT|Z\b))?/u', $value, $m) !== 1) {
            return null;
        }

        $date = Normalizer::date($m[1]);
        if (!Validator::isValidDate($date)) {
            return null;
        }

        if (($m[2] ?? '') !== '' && ($m[3] ?? '') !== '') {
            try {
                return (new DateTimeImmutable($date . ' ' . $m[2], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d');
            } catch (Exception) {
                return $date;
            }
        }

        return $date;
    }

    /**
     * Termin podany zdaniem: „Podpis straci ważność za 60 dni:” i data w tej samej albo następnej linii.
     */
    private static function expiryFromSentence(string $body): ?string
    {
        $lines = self::lines($body);
        foreach ($lines as $index => $line) {
            if (preg_match('/straci\s+ważność|traci\s+ważność|wygaśnie|wygasa\b|ważny\s+do\b|expires?\s+on|will\s+expire/iu', $line) !== 1) {
                continue;
            }
            foreach (array_slice($lines, $index, 3) as $candidate) {
                $date = self::date($candidate);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Okres ważności w miesiącach: „2 lata”, „1 rok”, „36 miesięcy”, „3 years”, „podpis 3-letni”.
     */
    public static function months(string $value): ?int
    {
        if (preg_match('/(\d{1,3})\s*(?:-\s*)?(lat\w*|letni\w*|rok\w*|r\b|year\w*|yr\w*|y\b|miesi\w*|mies\b|month\w*|m\b)/iu', $value, $m) !== 1) {
            return null;
        }

        $number = (int) $m[1];
        $unit = mb_strtolower($m[2]);
        $months = preg_match('/^(lat|letni|rok|r$|year|yr|y$)/u', $unit) === 1 ? $number * 12 : $number;

        return $months >= 1 && $months <= 120 ? $months : null;
    }

    // ------------------------------------------------------------ rodzaj nadawcy

    /**
     * Automatyczne powiadomienie (dostawcy certyfikatów albo skrzynki typu „no-reply”).
     */
    private static function isNotification(EmlMessage $message): bool
    {
        $email = (string) ($message->from['email'] ?? '');

        return $email !== '' && (self::isVendorAddress($email) || preg_match(self::AUTOMATED_MAILBOX, $email) === 1);
    }

    private static function isVendorAddress(string $email): bool
    {
        $domain = mb_strtolower((string) substr((string) strrchr($email, '@'), 1));
        foreach (self::VENDOR_DOMAINS as $vendor) {
            if ($domain === $vendor || str_ends_with($domain, '.' . $vendor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Treść bez stopki automatu: od pierwszej linii z danymi rejestrowymi nadawcy do końca.
     */
    private static function withoutFooter(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $index => $line) {
            if (self::isFooterLine($line)) {
                return implode("\n", array_slice($lines, 0, $index));
            }
        }

        return $text;
    }

    private static function isFooterLine(string $line): bool
    {
        $line = mb_substr($line, 0, self::MAX_LINE_CHARS);

        return preg_match('/©|kapitał\s+zakładowy|wiadomość\s+została\s+wygenerowana\s+automatycznie|adres\s+do\s+korespondencji|dane\s+adresowe/iu', $line) === 1
            || (preg_match('/\bKRS\b/u', $line) === 1 && preg_match('/sąd\s+rejonowy/iu', $line) === 1);
    }

    // ------------------------------------------------------------- czytanie linii

    /**
     * Pola rozpoznane w kolejnych liniach; przy powtórzeniach wygrywa pierwsze wystąpienie.
     *
     * @param list<string>          $lines
     * @param array<string, string> $custom klucz etykiety z szablonu odczytu → pole
     * @return array<string, string>
     */
    private static function read(array $lines, array $custom): array
    {
        $found = [];
        $section = null;
        $count = count($lines);

        for ($index = 0; $index < $count; ++$index) {
            $line = $lines[$index];
            $next = $lines[$index + 1] ?? null;

            $heading = self::heading($line);
            if ($heading !== null) {
                $section = $heading;
                continue;
            }

            // Sama etykieta w linii („WYDANY DLA”) — wartość stoi niżej.
            $alone = Normalizer::key(rtrim($line, ': '));
            if (isset($custom[$alone]) || in_array($alone, self::NEXT_LINE_LABELS, true)) {
                $field = $custom[$alone] ?? 'person.full_name';
                if ($next !== null && !isset($found[$field]) && self::canFollow($field, $next, $section, $custom)) {
                    $found[$field] = $next;
                    ++$index;
                }
                continue;
            }

            $pair = self::pair($line);
            if ($pair === null) {
                continue;
            }

            [$label, $value] = $pair;
            $field = self::resolveField(Normalizer::key($label), $section, $custom);
            if ($field === null || isset($found[$field])) {
                continue;
            }
            // „Etykieta:” bez wartości, a wartość w następnej linii.
            if ($value === '' && str_ends_with($line, ':') && $next !== null && self::canFollow($field, $next, $section, $custom)) {
                $value = $next;
                ++$index;
            }
            if (!self::isEmptyValue($value)) {
                $found[$field] = $value;
            }
        }

        return $found;
    }

    /**
     * Czy linia może być wartością etykiety stojącej wyżej: nie jest nagłówkiem ani kolejną znaną parą,
     * a w miejscu imienia i nazwiska wygląda jak imię i nazwisko.
     *
     * @param array<string, string> $custom
     */
    private static function canFollow(string $field, string $line, ?string $section, array $custom): bool
    {
        if (self::heading($line) !== null || self::isEmptyValue($line) || mb_strlen($line) > 200) {
            return false;
        }
        $pair = self::pair($line);
        if ($pair !== null && self::resolveField(Normalizer::key($pair[0]), $section, $custom) !== null) {
            return false;
        }

        return !str_starts_with($field, 'person.full_name')
            || preg_match('/^\p{L}[\p{L}\'.\-]*(?:\s+\p{L}[\p{L}\'.\-]*)+$/u', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(mb_substr($line, 0, self::MAX_LINE_CHARS));
            if ($line !== '') {
                $lines[] = $line;
                if (count($lines) >= self::MAX_LINES) {
                    break;
                }
            }
        }

        return $lines;
    }

    /**
     * Wiersze tabel HTML jako „Etykieta: wartość” (dwie komórki) albo kolejne pary komórek (cztery i więcej).
     * Wiersze układu strony (komórka z wieloma akapitami) pomijamy — czyta je zwykły tekst wiadomości.
     *
     * @return list<string>
     */
    private static function tableLines(string $html): array
    {
        if ($html === '' || stripos($html, '<tr') === false) {
            return [];
        }

        // Podział po znacznikach zamykających i odczyt tego, co stoi po ostatnim otwarciu, jest liniowy — wyrażenie
        // z „(.*?)” po całym dokumencie mogłoby się liczyć kwadratowo na złośliwym HTML-u z samymi otwarciami.
        $lines = [];
        $rows = 0;
        foreach (preg_split('#</tr\s*>#i', $html) ?: [] as $chunk) {
            $row = self::afterLastOpen($chunk, 'tr');
            if ($row === null) {
                continue;
            }
            if (++$rows > self::MAX_TABLE_ROWS) {
                break;
            }

            $texts = [];
            foreach (preg_split('#</t[dh]\s*>#i', $row) ?: [] as $piece) {
                $cell = self::afterLastOpen($piece, 't[dh]');
                if ($cell === null) {
                    continue;
                }
                $text = TemplateRenderer::htmlToText($cell);
                if (str_contains($text, "\n")) {
                    continue 2;
                }
                $texts[] = $text;
            }

            for ($i = 0; $i + 1 < count($texts); $i += 2) {
                if ($texts[$i] !== '') {
                    $lines[] = mb_substr(rtrim($texts[$i], ': ') . ': ' . $texts[$i + 1], 0, self::MAX_LINE_CHARS);
                }
            }
        }

        return $lines;
    }

    /**
     * Treść po ostatnim znaczniku otwierającym `$tag` (wzorzec bez `<` i `>`), null gdy go nie ma. Granica znacznika
     * odrzuca `<track` czy `<thead`, a atrybuty są ograniczone, więc czas rośnie liniowo z długością tekstu.
     */
    private static function afterLastOpen(string $html, string $tag): ?string
    {
        $parts = preg_split('#<' . $tag . '(?=[\s/>])[^>]{0,500}>#i', $html);
        if ($parts === false || count($parts) < 2) {
            return null;
        }

        return $parts[count($parts) - 1];
    }

    /**
     * Wiadomość z obciętą treścią (tylko to, co czyta ekstraktor); załączniki nie biorą udziału w odczycie.
     */
    private static function limited(EmlMessage $message): EmlMessage
    {
        if (strlen($message->text) <= self::MAX_TEXT_BYTES && strlen($message->html) <= self::MAX_HTML_BYTES) {
            return $message;
        }

        return new EmlMessage(
            $message->headers,
            $message->subject,
            $message->from,
            $message->to,
            $message->date,
            $message->messageId,
            mb_strcut($message->text, 0, self::MAX_TEXT_BYTES),
            [],
            mb_strcut($message->html, 0, self::MAX_HTML_BYTES),
        );
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

    /**
     * @param array<string, string> $custom
     */
    private static function resolveField(string $labelKey, ?string $section, array $custom = []): ?string
    {
        if ($labelKey === '') {
            return null;
        }
        if (isset($custom[$labelKey])) {
            return $custom[$labelKey];
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

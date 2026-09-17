<?php

declare(strict_types=1);

namespace App\Exchange;

/**
 * Kolumny zbiorów danych w eksporcie i imporcie (F9, F10).
 *
 * Eksport CSV ma nagłówki z etykietami w języku interfejsu, XML — nazwy pól. Import rozpoznaje
 * oba warianty w obu językach oraz popularne nazwy kolumn z arkuszy („NIP”, „Data wygaśnięcia”),
 * więc plik wyeksportowany z aplikacji albo przygotowany ręcznie w Excelu wraca bez przeróbek.
 */
final class Columns
{
    /** Zbiory, które można importować, w kolejności importu (płatnik → osoba → certyfikat). */
    public const IMPORTABLE = ['payers', 'beneficiaries', 'certificates'];

    /**
     * Pole → [klucz etykiety, importowane, wymagane w pliku importu, dodatkowe nazwy kolumny].
     * Pola nieimportowane są tylko w eksporcie (identyfikatory, wartości wyliczane, daty systemowe).
     *
     * @var array<string, array<string, array{0: string, 1: bool, 2: bool, 3: list<string>}>>
     */
    private const DEFINITIONS = [
        'payers' => [
            'id'                => ['exchange.column.id', false, false, []],
            'company_name'      => ['field.company_name', true, true, ['nazwa', 'firma', 'nazwa firmy', 'płatnik', 'company', 'company name']],
            'tax_id'            => ['field.tax_id', true, false, ['nip', 'vat', 'numer nip', 'nip płatnika', 'vat id']],
            'contact_person'    => ['field.contact_person', true, true, ['kontakt', 'osoba do kontaktu', 'contact']],
            'email'             => ['field.email', true, false, ['mail', 'adres email', 'adres e-mail']],
            'phone'             => ['field.phone', true, false, ['tel', 'nr telefonu', 'phone number']],
            'address_line'      => ['field.address_line', true, false, ['adres', 'ulica', 'address', 'street']],
            'postal_code'       => ['field.postal_code', true, false, ['kod', 'zip', 'postcode']],
            'city'              => ['field.city', true, false, ['miasto', 'town']],
            'beneficiary_count' => ['exchange.column.beneficiary_count', false, false, []],
            'certificate_count' => ['exchange.column.certificate_count', false, false, []],
            'created_at'        => ['common.created_at', false, false, []],
            'archived_at'       => ['exchange.column.archived_at', false, false, []],
        ],
        'beneficiaries' => [
            'id'                => ['exchange.column.id', false, false, []],
            'first_name'        => ['field.first_name', true, true, ['imie', 'given name']],
            'last_name'         => ['field.last_name', true, true, ['surname', 'family name']],
            'email'             => ['field.email', true, false, ['mail', 'adres email', 'adres e-mail']],
            'phone'             => ['field.phone', true, false, ['tel', 'nr telefonu', 'phone number']],
            'payer_name'        => ['exchange.column.payer_name', true, false, ['nazwa płatnika', 'firma', 'pracodawca', 'payer name']],
            'payer_tax_id'      => ['exchange.column.payer_tax_id', true, false, ['nip', 'payer tax id']],
            'notes'             => ['field.notes', true, false, ['uwagi', 'notatka', 'comments']],
            'certificate_count' => ['exchange.column.certificate_count', false, false, []],
            'earliest_expiry'   => ['common.earliest_expiry', false, false, []],
            'created_at'        => ['common.created_at', false, false, []],
            'archived_at'       => ['exchange.column.archived_at', false, false, []],
        ],
        'certificates' => [
            'id'                      => ['exchange.column.id', false, false, []],
            'name'                    => ['field.name', true, true, ['nazwa certyfikatu', 'certyfikat', 'usługa', 'certificate', 'service']],
            'certificate_type'        => ['field.certificate_type', true, true, ['rodzaj', 'typ certyfikatu', 'type']],
            'serial_number'           => ['field.serial_number', true, false, ['nr seryjny', 'numer', 'serial', 'sn']],
            'issuer'                  => ['field.issuer', true, false, ['wydawca', 'dostawca', 'ca']],
            'valid_from'              => ['field.valid_from', true, false, ['data wydania', 'od', 'issued']],
            'expiry_date'             => ['field.expiry_date', true, true, ['data wygaśnięcia', 'ważny do', 'data ważności', 'do', 'expires', 'valid to', 'expiry']],
            'renewal_lead_days'       => ['field.renewal_lead_days', true, false, ['czas odnowienia', 'margines odnowienia', 'lead days']],
            'status'                  => ['field.status', true, false, []],
            'beneficiary_first_name'  => ['exchange.column.beneficiary_first_name', true, false, ['imię', 'imie']],
            'beneficiary_last_name'   => ['exchange.column.beneficiary_last_name', true, false, ['nazwisko']],
            'beneficiary_email'       => ['exchange.column.beneficiary_email', true, false, ['e-mail', 'email', 'e-mail osoby']],
            'payer_name'              => ['exchange.column.payer_name', true, false, ['nazwa płatnika', 'firma', 'payer name']],
            'payer_tax_id'            => ['exchange.column.payer_tax_id', true, false, ['nip', 'payer tax id']],
            'owner_email'             => ['exchange.column.owner_email', true, false, ['opiekun', 'owner']],
            'annual_cost'             => ['field.annual_cost', true, false, ['cena', 'kwota', 'cost', 'price']],
            'billing_cycle'           => ['field.billing_cycle', true, false, ['okres rozliczeniowy', 'cykl rozliczeń']],
            'currency'                => ['field.currency', true, false, []],
            'payment_status'          => ['field.payment_status', true, false, ['status płatności']],
            'last_payment_date'       => ['field.last_payment_date', true, false, ['data płatności']],
            'auto_renew'              => ['field.auto_renew', true, false, ['autoodnawianie']],
            'notes'                   => ['field.notes', true, false, ['uwagi']],
            'previous_certificate_id' => ['exchange.column.previous_certificate_id', false, false, []],
            'days_left'               => ['exchange.column.days_left', false, false, []],
            'priority'                => ['exchange.column.priority', false, false, []],
            'created_at'              => ['common.created_at', false, false, []],
            'archived_at'             => ['exchange.column.archived_at', false, false, []],
        ],
    ];

    /** @var array<string, array<string, string>> */
    private static array $aliases = [];

    public static function isImportable(string $dataset): bool
    {
        return in_array($dataset, self::IMPORTABLE, true);
    }

    /**
     * @return list<string>
     */
    public static function fields(string $dataset): array
    {
        return array_keys(self::DEFINITIONS[$dataset] ?? []);
    }

    public static function labelKey(string $dataset, string $field): string
    {
        return self::DEFINITIONS[$dataset][$field][0] ?? $field;
    }

    /**
     * Opis kolumn do ekranu importu: pole, etykieta, czy wymagane.
     *
     * @return list<array{field: string, label: string, required: bool}>
     */
    public static function importColumns(string $dataset): array
    {
        $columns = [];
        foreach (self::DEFINITIONS[$dataset] ?? [] as $field => [$labelKey, $importable, $required]) {
            if ($importable) {
                $columns[] = ['field' => $field, 'label' => \__($labelKey), 'required' => $required];
            }
        }

        return $columns;
    }

    /**
     * Dopasowanie nagłówków pliku do pól zbioru.
     *
     * @param list<string> $headers
     * @return array{mapping: array<string, string>, ignored: list<string>, missing: list<string>}
     */
    public static function mapHeaders(string $dataset, array $headers): array
    {
        $aliases = self::aliases($dataset);
        $mapping = [];
        $ignored = [];
        $used = [];

        foreach ($headers as $header) {
            $field = $aliases[Normalizer::key($header)] ?? null;
            if ($field === null || isset($used[$field]) || !self::DEFINITIONS[$dataset][$field][1]) {
                $ignored[] = $header;
                continue;
            }
            $mapping[$header] = $field;
            $used[$field] = true;
        }

        $missing = [];
        foreach (self::DEFINITIONS[$dataset] as $field => $definition) {
            if ($definition[2] && !isset($used[$field])) {
                $missing[] = $field;
            }
        }

        return ['mapping' => $mapping, 'ignored' => $ignored, 'missing' => $missing];
    }

    /**
     * Nazwy kolumn rozpoznawane przy imporcie (znormalizowane) → pole. Pierwsze pole z danym
     * aliasem wygrywa, więc kolejność w DEFINITIONS rozstrzyga niejednoznaczne nazwy.
     *
     * @return array<string, string>
     */
    public static function aliases(string $dataset): array
    {
        if (isset(self::$aliases[$dataset])) {
            return self::$aliases[$dataset];
        }

        $aliases = [];
        foreach (self::DEFINITIONS[$dataset] ?? [] as $field => [$labelKey, , , $extra]) {
            $names = array_merge([$field, Normalizer::translator('pl')->translate($labelKey), Normalizer::translator('en')->translate($labelKey)], $extra);
            foreach ($names as $name) {
                $key = Normalizer::key($name);
                if ($key !== '' && !isset($aliases[$key])) {
                    $aliases[$key] = $field;
                }
            }
        }

        return self::$aliases[$dataset] = $aliases;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Walidacja i normalizacja danych z formularzy, API i importu.
 *
 * Każda metoda zwraca znormalizowaną wartość (albo null dla pustego pola opcjonalnego)
 * i zapisuje błąd pola, zamiast przerywać — formularz dostaje wszystkie błędy naraz.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function string(string $field, bool $required, int $maxLength): ?string
    {
        $value = $this->raw($field);

        if ($value === null || (!is_scalar($value))) {
            $this->missing($field, $required);

            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            $this->missing($field, $required);

            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            $this->addError($field, 'validation.max_length', ['max' => (string) $maxLength]);
        }

        return $text;
    }

    public function email(string $field, bool $required): ?string
    {
        $email = $this->string($field, $required, 255);
        if ($email === null) {
            return null;
        }

        $email = mb_strtolower($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($field, 'validation.email');
        }

        return $email;
    }

    public function phone(string $field): ?string
    {
        $phone = $this->string($field, false, 50);
        if ($phone !== null && preg_match('/^[0-9 +()\/.\-]{5,50}$/', $phone) !== 1) {
            $this->addError($field, 'validation.phone');
        }

        return $phone;
    }

    /**
     * Data w formacie RRRR-MM-DD, sprawdzana kalendarzem (np. 2026-02-31 jest błędem — §5 pkt 10).
     */
    public function date(string $field, bool $required): ?string
    {
        $value = $this->string($field, $required, 10);
        if ($value === null) {
            return null;
        }

        if (!self::isValidDate($value)) {
            $this->addError($field, 'validation.date');

            return null;
        }

        return $value;
    }

    public function int(string $field, bool $required, int $min, int $max): ?int
    {
        $value = $this->raw($field);
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            $this->missing($field, $required);

            return null;
        }

        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
            $number = (int) trim($value);
        } elseif (is_float($value) && floor($value) === $value) {
            $number = (int) $value;
        } else {
            $this->addError($field, 'validation.integer', ['min' => (string) $min, 'max' => (string) $max]);

            return null;
        }

        if ($number < $min || $number > $max) {
            $this->addError($field, 'validation.integer', ['min' => (string) $min, 'max' => (string) $max]);

            return null;
        }

        return $number;
    }

    /**
     * Identyfikator rekordu (liczba dodatnia) — pusty oznacza brak powiązania.
     */
    public function id(string $field, bool $required): ?int
    {
        return $this->int($field, $required, 1, PHP_INT_MAX);
    }

    public function decimal(string $field, bool $required, float $min, float $max): ?float
    {
        $value = $this->raw($field);
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->missing($field, $required);

            return null;
        }

        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value)) {
            $normalized = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));
            if (!is_numeric($normalized)) {
                $this->addError($field, 'validation.number', ['min' => (string) $min, 'max' => (string) $max]);

                return null;
            }
            $number = (float) $normalized;
        } else {
            $this->addError($field, 'validation.number', ['min' => (string) $min, 'max' => (string) $max]);

            return null;
        }

        if ($number < $min || $number > $max) {
            $this->addError($field, 'validation.number', ['min' => (string) $min, 'max' => (string) $max]);

            return null;
        }

        return round($number, 2);
    }

    /**
     * @param list<string> $allowed
     */
    public function enum(string $field, bool $required, array $allowed, ?string $default = null): ?string
    {
        $value = $this->raw($field);
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($default !== null) {
                return $default;
            }

            $this->missing($field, $required);

            return null;
        }

        $text = is_scalar($value) ? trim((string) $value) : '';
        if (!in_array($text, $allowed, true)) {
            $this->addError($field, 'validation.choice');

            return $default;
        }

        return $text;
    }

    public function bool(string $field, bool $default): bool
    {
        $value = $this->raw($field);
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            $this->addError($field, 'validation.choice');

            return $default;
        }

        return $parsed;
    }

    public function currency(string $field): string
    {
        $value = $this->string($field, false, 3);
        if ($value === null) {
            return 'PLN';
        }

        $value = strtoupper($value);
        if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            $this->addError($field, 'validation.currency');
        }

        return $value;
    }

    /**
     * NIP (10 cyfr z sumą kontrolną, dopuszczalny prefiks PL) albo numer VAT UE innego kraju.
     * Zwraca postać bez spacji i myślników.
     */
    public function taxId(string $field): ?string
    {
        $value = $this->string($field, false, 20);
        if ($value === null) {
            return null;
        }

        $normalized = self::normalizeTaxId($value);
        if (!self::isValidTaxId($normalized)) {
            $this->addError($field, 'validation.tax_id');
        }

        return $normalized;
    }

    public function postalCode(string $field): ?string
    {
        $value = $this->string($field, false, 16);
        if ($value !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{1,14}[A-Za-z0-9]$/', $value) !== 1) {
            $this->addError($field, 'validation.postal_code');
        }

        return $value;
    }

    /**
     * @param array<string, string> $replace
     */
    public function addError(string $field, string $messageKey, array $replace = []): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = \__($messageKey, $replace);
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @throws ServiceException
     */
    public function throwIfFailed(): void
    {
        if ($this->fails()) {
            throw ServiceException::validation($this->errors);
        }
    }

    public static function isValidDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Postać kanoniczna: bez spacji, kresek i kropek; polski NIP jako 10 cyfr (bez prefiksu PL),
     * żeby ten sam numer zapisany na dwa sposoby nie ominął unikalności.
     */
    public static function normalizeTaxId(string $value): string
    {
        $normalized = strtoupper((string) preg_replace('/[\s\-.]/', '', $value));

        return preg_match('/^PL(\d{10})$/', $normalized, $m) === 1 ? $m[1] : $normalized;
    }

    public static function isValidTaxId(string $normalized): bool
    {
        if (preg_match('/^(?:PL)?(\d{10})$/', $normalized, $m) === 1) {
            return self::isValidPolishNip($m[1]);
        }

        // Numer VAT innego kraju UE: dwuliterowy kod kraju + 2–18 znaków.
        return preg_match('/^(?!PL)[A-Z]{2}[A-Z0-9]{2,18}$/', $normalized) === 1;
    }

    public static function isValidPolishNip(string $digits): bool
    {
        if (preg_match('/^\d{10}$/', $digits) !== 1) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }

        $control = $sum % 11;

        return $control !== 10 && $control === (int) $digits[9];
    }

    private function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    private function missing(string $field, bool $required): void
    {
        if ($required) {
            $this->addError($field, 'validation.required');
        }
    }
}

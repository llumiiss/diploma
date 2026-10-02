<?php

declare(strict_types=1);

namespace App\Intake;

/**
 * Dane firmy z publicznego rejestru (Biała Lista podatników VAT Ministerstwa Finansów) w postaci
 * gotowej do wstawienia w formularz: nazwa, adres rozbity na ulicę, kod i miasto oraz status VAT.
 * Pełny zapis z rejestru zawiera m.in. numery rachunków — tu zostaje tylko to, czego potrzebuje ewidencja.
 */
final class CompanyRecord
{
    public function __construct(
        public readonly string $nip,
        /** Nazwa dokładnie tak, jak w rejestrze (zwykle wielkimi literami). */
        public readonly string $officialName,
        /** Nazwa czytelna: ta sama, ale pisana normalnie (Orlen Spółka Akcyjna). */
        public readonly string $name,
        public readonly ?string $statusVat,
        public readonly ?string $regon,
        public readonly ?string $krs,
        public readonly ?string $addressLine,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly ?string $rawAddress,
        public readonly ?string $registrationDate,
    ) {
    }

    /**
     * Czy podatnik jest czynnym podatnikiem VAT (inne statusy to ostrzeżenie dla operatora).
     */
    public function isActiveVatPayer(): bool
    {
        return $this->statusVat !== null && mb_strtolower($this->statusVat) === 'czynny';
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'nip'               => $this->nip,
            'official_name'     => $this->officialName,
            'name'              => $this->name,
            'status_vat'        => $this->statusVat,
            'regon'             => $this->regon,
            'krs'               => $this->krs,
            'address_line'      => $this->addressLine,
            'postal_code'       => $this->postalCode,
            'city'              => $this->city,
            'raw_address'       => $this->rawAddress,
            'registration_date' => $this->registrationDate,
        ];
    }

    /**
     * @param array<string, mixed> $data zapis z toArray()
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        return new self(
            (string) ($data['nip'] ?? ''),
            (string) ($data['official_name'] ?? ''),
            (string) ($data['name'] ?? ''),
            $string('status_vat'),
            $string('regon'),
            $string('krs'),
            $string('address_line'),
            $string('postal_code'),
            $string('city'),
            $string('raw_address'),
            $string('registration_date'),
        );
    }
}

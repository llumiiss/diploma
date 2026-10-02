<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Intake\CompanyRecord;
use App\Intake\CompanyRegistry;
use App\Intake\RegistryException;
use App\Intake\WhiteListRegistry;
use DateTimeImmutable;

/**
 * Rejestr firm podstawiany w testach: odpowiedzi z tablicy NIP → dane albo wyjątek, bez sieci.
 */
final class FakeCompanyRegistry implements CompanyRegistry
{
    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string, array<string, mixed>|RegistryException> $subjects NIP → zapis „subject” z API Białej Listy
     */
    public function __construct(private array $subjects = [])
    {
    }

    /**
     * @param array<string, mixed> $subject
     */
    public function add(string $nip, array $subject): void
    {
        $this->subjects[$nip] = $subject;
    }

    public function fail(string $nip, string $message = 'Rejestr jest niedostępny (HTTP 503).'): void
    {
        $this->subjects[$nip] = new RegistryException($message);
    }

    public function lookupNip(string $nip, ?DateTimeImmutable $date = null): ?CompanyRecord
    {
        $this->queries[] = $nip;
        $subject = $this->subjects[$nip] ?? null;

        if ($subject instanceof RegistryException) {
            throw $subject;
        }

        return $subject === null ? null : WhiteListRegistry::record($nip, $subject);
    }

    /**
     * Zapis w formacie odpowiedzi API Białej Listy (tylko pola używane przez aplikację).
     *
     * @return array<string, mixed>
     */
    public static function subject(string $name, string $nip, string $address, string $status = 'Czynny'): array
    {
        return [
            'name'                  => $name,
            'nip'                   => $nip,
            'statusVat'             => $status,
            'regon'                 => '123456785',
            'krs'                   => '0000123456',
            'residenceAddress'      => null,
            'workingAddress'        => $address,
            'registrationLegalDate' => '2010-05-12',
        ];
    }
}

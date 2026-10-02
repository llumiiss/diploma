<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Intake\RegistryException;
use App\Intake\WhiteListRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WhiteListRegistryTest extends TestCase
{
    private const FOUND = '{"result":{"subject":{"name":"ORLEN SPÓŁKA AKCYJNA","nip":"7740001454","statusVat":"Czynny","regon":"610188201","pesel":null,"krs":"0000028860","residenceAddress":null,"workingAddress":"CHEMIKÓW 7, 09-411 PŁOCK","representatives":[],"authorizedClerks":[],"partners":[],"registrationLegalDate":"1993-07-05","accountNumbers":["47124062921787001126387086"]},"requestId":"abc","requestDateTime":"02-10-2026 02:11:20"}}';
    private const NOT_FOUND = '{"result":{"subject":null,"requestId":"sU9A7-98lm148","requestDateTime":"02-10-2026 02:11:20"}}';

    /** @var list<string> */
    private array $urls = [];

    /**
     * @return callable(string): array{0: int, 1: string}
     */
    private function transport(int $status, string $body): callable
    {
        return function (string $url) use ($status, $body): array {
            $this->urls[] = $url;

            return [$status, $body];
        };
    }

    public function testFoundCompanyIsMappedAndRequestUsesNipAndDate(): void
    {
        $registry = new WhiteListRegistry('https://wl-api.mf.gov.pl/', 5, $this->transport(200, self::FOUND));

        $company = $registry->lookupNip('774-000-14-54', new DateTimeImmutable('2026-10-02'));

        $this->assertSame(['https://wl-api.mf.gov.pl/api/search/nip/7740001454?date=2026-10-02'], $this->urls);
        $this->assertNotNull($company);
        $this->assertSame('7740001454', $company->nip);
        $this->assertSame('ORLEN SPÓŁKA AKCYJNA', $company->officialName);
        $this->assertSame('Orlen Spółka Akcyjna', $company->name);
        $this->assertSame('Chemików 7', $company->addressLine);
        $this->assertSame('09-411', $company->postalCode);
        $this->assertSame('Płock', $company->city);
        $this->assertSame('Czynny', $company->statusVat);
        $this->assertTrue($company->isActiveVatPayer());
        $this->assertSame('0000028860', $company->krs);
        $this->assertSame('610188201', $company->regon);
        $this->assertSame('1993-07-05', $company->registrationDate);
    }

    public function testRecordKeepsOnlyWhatTheEvidenceNeeds(): void
    {
        $company = (new WhiteListRegistry('x', 5, $this->transport(200, self::FOUND)))->lookupNip('7740001454');

        $this->assertNotNull($company);
        $array = $company->toArray();
        $this->assertArrayNotHasKey('accountNumbers', $array);
        $this->assertSame($company->name, \App\Intake\CompanyRecord::fromArray($array)->name);
    }

    public function testUnknownNipReturnsNull(): void
    {
        $this->assertNull((new WhiteListRegistry('x', 5, $this->transport(200, self::NOT_FOUND)))->lookupNip('1234567890'));
    }

    public function testTransientFailuresAreReportedAsRegistryErrors(): void
    {
        foreach ([[429, '{}', 'limit'], [503, '', 'niedostępny'], [0, '', 'niedostępny'], [200, 'not json', 'Nieczytelna'], [400, '{"code":"WL-100","message":"Nieprawidłowy NIP"}', 'Nieprawidłowy NIP']] as [$status, $body, $fragment]) {
            try {
                (new WhiteListRegistry('x', 5, $this->transport($status, $body)))->lookupNip('7740001454');
                $this->fail("HTTP {$status} powinien zgłosić błąd rejestru.");
            } catch (RegistryException $e) {
                $this->assertStringContainsString($fragment, $e->getMessage(), "HTTP {$status}");
            }
        }
    }

    public function testMalformedNipNeverReachesTheNetwork(): void
    {
        $registry = new WhiteListRegistry('x', 5, $this->transport(200, self::FOUND));

        $this->expectException(RegistryException::class);
        try {
            $registry->lookupNip('12345');
        } finally {
            $this->assertSame([], $this->urls);
        }
    }

    public function testSoleProprietorWithResidenceAddressOnly(): void
    {
        $body = '{"result":{"subject":{"name":"Jan Kowalski Usługi Informatyczne","nip":"6342851974","statusVat":"Zwolniony","residenceAddress":"ul. Długa 5/2, 40-100 Katowice","workingAddress":null}}}';

        $company = (new WhiteListRegistry('x', 5, $this->transport(200, $body)))->lookupNip('6342851974');

        $this->assertNotNull($company);
        $this->assertSame('Jan Kowalski Usługi Informatyczne', $company->name);
        $this->assertSame('ul. Długa 5/2', $company->addressLine);
        $this->assertSame('40-100', $company->postalCode);
        $this->assertSame('Katowice', $company->city);
        $this->assertFalse($company->isActiveVatPayer());
    }

    public function testAddressParsingAndNameBeautifying(): void
    {
        $this->assertSame(['Chemików 7', '09-411', 'Płock'], WhiteListRegistry::parseAddress('CHEMIKÓW 7, 09-411 PŁOCK'));
        $this->assertSame(['Aleje Jerozolimskie 160', '02-326', 'Warszawa'], WhiteListRegistry::parseAddress('ALEJE JEROZOLIMSKIE 160, 02-326 WARSZAWA'));
        $this->assertSame(['Ulica bez kodu 5', null, null], WhiteListRegistry::parseAddress('Ulica bez kodu 5'));
        $this->assertSame([null, null, null], WhiteListRegistry::parseAddress(null));

        $this->assertSame('Novatech sp. z o.o.', WhiteListRegistry::prettyName('NOVATECH SP. Z O.O.'));
        $this->assertSame('Grupa Wisła S.A.', WhiteListRegistry::prettyName('GRUPA WISŁA S.A.'));
        // Nazwa już pisana normalnie zostaje bez zmian.
        $this->assertSame('NovaTech Sp. z o.o.', WhiteListRegistry::prettyName('NovaTech Sp. z o.o.'));
        $this->assertSame('', WhiteListRegistry::prettyName(''));
    }
}

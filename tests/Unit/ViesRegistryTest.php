<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Intake\RegistryException;
use App\Intake\ViesRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Klient VIES (Komisja Europejska) — firmy z innych krajów Unii po numerze VAT.
 * Transport jest podstawiany, więc testy nie wychodzą do sieci.
 */
final class ViesRegistryTest extends TestCase
{
    /** @var list<array{url: string, payload: string}> */
    private array $calls = [];

    private function registry(int $status, string $body): ViesRegistry
    {
        return new ViesRegistry('https://ec.europa.eu/taxation_customs/vies/rest-api', 5, function (string $url, string $payload) use ($status, $body): array {
            $this->calls[] = ['url' => $url, 'payload' => $payload];

            return [$status, $body];
        });
    }

    public function testSupportsOnlyEuNumbersFromOtherCountries(): void
    {
        $registry = $this->registry(200, '{}');

        $this->assertTrue($registry->supports('DE123456789'));
        $this->assertTrue($registry->supports('de 123 456 789'));
        $this->assertTrue($registry->supports('IE6388047V'));
        $this->assertFalse($registry->supports('PL6342851974'), 'polski NIP obsługuje Biała lista — daje więcej danych');
        $this->assertFalse($registry->supports('6342851974'));
        $this->assertFalse($registry->supports('US123456789'), 'kraj spoza Unii');
    }

    public function testReadsNameAndAddressOfAValidNumber(): void
    {
        $body = (string) json_encode([
            'countryCode' => 'DE',
            'vatNumber'   => '123456789',
            'valid'       => true,
            'requestDate' => '2026-10-23',
            'name'        => 'MUSTER GMBH',
            'address'     => "HAUPTSTRASSE 5\n10115 BERLIN",
        ]);

        $record = $this->registry(200, $body)->lookupNip('DE 123 456 789');

        $this->assertNotNull($record);
        $this->assertSame('DE123456789', $record->nip);
        $this->assertSame('MUSTER GMBH', $record->officialName);
        $this->assertSame('Muster Gmbh', $record->name, 'nazwę z kapitalików zapisujemy normalnie');
        $this->assertSame('Hauptstrasse 5', $record->addressLine);
        $this->assertSame('10115', $record->postalCode);
        $this->assertSame('Berlin', $record->city);
        $this->assertTrue($record->isActiveVatPayer(), 'ważny numer w VIES = zarejestrowany do transakcji unijnych');
        $this->assertNull($record->regon, 'VIES nie zna polskich identyfikatorów');
        $this->assertNull($record->krs);

        $this->assertCount(1, $this->calls);
        $this->assertStringEndsWith('/check-vat-number', $this->calls[0]['url']);
        $this->assertSame('{"countryCode":"DE","vatNumber":"123456789"}', $this->calls[0]['payload']);
    }

    /**
     * Prawdziwa odpowiedź VIES (IE6388047V, 2026-10-23) — pole nazywa się „valid”, nie „isValid”,
     * a dane traderXxx bywają nieujawnione („---”). Ten test pilnuje, żeby zmiana kształtu
     * odpowiedzi nie przeszła niezauważona.
     */
    public function testRealWorldResponseShapeIsUnderstood(): void
    {
        $body = <<<'JSON'
            {
              "countryCode" : "IE",
              "vatNumber" : "6388047V",
              "requestDate" : "2026-10-23T20:44:07.772Z",
              "valid" : true,
              "requestIdentifier" : "",
              "name" : "GOOGLE IRELAND LIMITED",
              "address" : "3RD FLOOR, GORDON HOUSE, BARROW STREET, DUBLIN 4",
              "traderName" : "---",
              "traderStreet" : "---",
              "traderPostalCode" : "---",
              "traderCity" : "---",
              "traderCompanyType" : "---",
              "traderNameMatch" : "NOT_PROCESSED"
            }
            JSON;

        $record = $this->registry(200, $body)->lookupNip('IE6388047V');

        $this->assertNotNull($record);
        $this->assertSame('Google Ireland Limited', $record->name);
        $this->assertSame('3rd Floor, Gordon House, Barrow Street, Dublin 4', $record->addressLine);
        $this->assertNull($record->postalCode, 'Irlandia nie podaje kodu w osobnym polu — operator uzupełni');
        $this->assertTrue($record->isActiveVatPayer());
    }

    public function testInvalidNumberGivesNoCompany(): void
    {
        $body = (string) json_encode(['countryCode' => 'DE', 'vatNumber' => '000000000', 'valid' => false]);

        $this->assertNull($this->registry(200, $body)->lookupNip('DE000000000'));
    }

    public function testHiddenNameIsTreatedAsMissing(): void
    {
        // Część krajów nie ujawnia nazwy ani adresu — VIES zwraca wtedy „---”.
        $body = (string) json_encode(['valid' => true, 'name' => '---', 'address' => '---']);

        $record = $this->registry(200, $body)->lookupNip('ES12345678Z');

        $this->assertNotNull($record);
        $this->assertSame('', $record->officialName);
        $this->assertNull($record->addressLine);
        $this->assertNull($record->rawAddress);
    }

    public function testServerFailureAndRateLimitAreReported(): void
    {
        foreach ([[500, 'niedostępny'], [0, 'niedostępny'], [429, 'limit']] as [$status, $fragment]) {
            try {
                $this->registry($status, '')->lookupNip('DE123456789');
                $this->fail('oczekiwano RegistryException dla HTTP ' . $status);
            } catch (RegistryException $e) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
        }
    }

    public function testRejectedRequestCarriesTheServiceMessage(): void
    {
        $body = (string) json_encode(['errorWrappers' => [['error' => 'INVALID_INPUT']]]);

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessageMatches('/INVALID_INPUT/');

        $this->registry(400, $body)->lookupNip('DE123456789');
    }

    public function testUnreadableAnswerIsReported(): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionMessageMatches('/Nieczytelna/u');

        $this->registry(200, 'to nie jest JSON')->lookupNip('DE123456789');
    }

    public function testNumberWithoutACountryCodeIsRejectedBeforeTheRequest(): void
    {
        $registry = $this->registry(200, '{}');

        try {
            $registry->lookupNip('6342851974');
            $this->fail('oczekiwano RegistryException');
        } catch (RegistryException $e) {
            $this->assertStringContainsString('kodu kraju', $e->getMessage());
        }

        $this->assertSame([], $this->calls, 'bez kodu kraju nie ma do kogo pytać');
    }

    /**
     * Układ adresu zależy od kraju — sprawdzamy kilka realnych postaci.
     */
    public function testAddressParsingHandlesSeveralLayouts(): void
    {
        $this->assertSame(['Hauptstrasse 5', '10115', 'Berlin'], ViesRegistry::parseAddress("HAUPTSTRASSE 5\n10115 BERLIN"));
        $this->assertSame(['Rue De La Loi 1', '1000', 'Bruxelles'], ViesRegistry::parseAddress("RUE DE LA LOI 1\n1000 BRUXELLES"));
        $this->assertSame(['Main Street 1', '12345', 'Dublin'], ViesRegistry::parseAddress("MAIN STREET 1\nDUBLIN 12345"));
        $this->assertSame(['Jeden Wiersz Bez Kodu', null, null], ViesRegistry::parseAddress('JEDEN WIERSZ BEZ KODU'));
        $this->assertSame([null, null, null], ViesRegistry::parseAddress(null));
    }
}

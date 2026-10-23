<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Intake\KrsRegistry;
use App\Intake\RegistryException;
use PHPUnit\Framework\TestCase;

/**
 * Uzupełnienie danych firmy z Krajowego Rejestru Sądowego (publiczne API, bez klucza).
 * Fragment odpowiedzi odwzorowuje prawdziwy odpis (ORLEN, KRS 0000028860, 2026-10-23).
 */
final class KrsRegistryTest extends TestCase
{
    private string $directory = '';

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/certisub-krs-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function registry(int $status, string $body, int $ttl = 604800): KrsRegistry
    {
        return new KrsRegistry('https://api-krs.ms.gov.pl', 5, $this->directory, $ttl, function (string $url) use ($status, $body): array {
            $this->calls[] = $url;

            return [$status, $body];
        });
    }

    private static function odpis(): string
    {
        return (string) json_encode([
            'odpis' => ['dane' => ['dzial1' => [
                'danePodmiotu'    => ['nazwa' => 'ORLEN SPÓŁKA AKCYJNA', 'formaPrawna' => 'SPÓŁKA AKCYJNA'],
                'siedzibaIAdres'  => ['adres' => ['ulica' => 'CHEMIKÓW', 'nrDomu' => '7', 'kodPocztowy' => '09-411', 'miejscowosc' => 'PŁOCK']],
            ], 'dzial2' => [
                // Dane osobowe reprezentacji — nie czytamy ich w ogóle.
                'reprezentacja' => ['sklad' => [['nazwisko' => ['nazwiskoICzlon' => 'F*****'], 'identyfikator' => ['pesel' => '5**********']]]],
            ]]],
        ]);
    }

    public function testReadsLegalFormAndStructuredAddress(): void
    {
        $record = $this->registry(200, self::odpis())->lookupKrs('28860');

        $this->assertNotNull($record);
        $this->assertSame('0000028860', $record['krs'], 'numer jest dopełniany zerami do dziesięciu znaków');
        $this->assertSame('Orlen Spółka Akcyjna', $record['name']);
        $this->assertSame('Spółka Akcyjna', $record['legal_form']);
        $this->assertSame('Chemików 7', $record['address_line']);
        $this->assertSame('09-411', $record['postal_code']);
        $this->assertSame('Płock', $record['city']);
        $this->assertArrayNotHasKey('reprezentacja', $record, 'danych osobowych nie przenosimy');
        $this->assertStringContainsString('/api/krs/OdpisAktualny/0000028860?rejestr=P&format=json', $this->calls[0]);
    }

    public function testFlatNumberIsAddedToTheAddress(): void
    {
        $body = (string) json_encode(['odpis' => ['dane' => ['dzial1' => [
            'danePodmiotu'   => ['nazwa' => 'TESTOWA SP. Z O.O.', 'formaPrawna' => 'SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ'],
            'siedzibaIAdres' => ['adres' => ['ulica' => 'KWIATOWA', 'nrDomu' => '5', 'nrLokalu' => '12', 'kodPocztowy' => '40-020', 'miejscowosc' => 'KATOWICE']],
        ]]]]);

        $record = $this->registry(200, $body)->lookupKrs('0000123456');

        $this->assertNotNull($record);
        $this->assertSame('Kwiatowa 5/12', $record['address_line']);
    }

    public function testUnknownNumberGivesNullAndIsRemembered(): void
    {
        $registry = $this->registry(404, '');

        $this->assertNull($registry->lookupKrs('0000000001'));
        $this->assertNull($registry->lookupKrs('0000000001'));

        $this->assertCount(1, $this->calls, 'brak numeru też zapamiętujemy');
    }

    public function testAnswerIsReadFromTheCacheOnTheSecondCall(): void
    {
        $registry = $this->registry(200, self::odpis());

        $first = $registry->lookupKrs('0000028860');
        $second = $registry->lookupKrs('0000028860');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->calls, 'odpis waży ok. 60 kB — nie pobieramy go dwa razy');
    }

    public function testFailureIsReportedAndNotRemembered(): void
    {
        $registry = $this->registry(503, '');

        try {
            $registry->lookupKrs('0000028860');
            $this->fail('oczekiwano RegistryException');
        } catch (RegistryException $e) {
            $this->assertStringContainsString('KRS', $e->getMessage());
        }

        $this->assertSame([], glob($this->directory . '/*.json') ?: [], 'awarii nie zapamiętujemy');
    }

    public function testUnreadableAnswerIsReported(): void
    {
        $this->expectException(RegistryException::class);
        $this->expectExceptionMessageMatches('/Nieczytelna/u');

        $this->registry(200, '{"odpis":{}}')->lookupKrs('0000028860');
    }

    public function testCacheCanBeDisabled(): void
    {
        $registry = $this->registry(200, self::odpis(), 0);

        $registry->lookupKrs('0000028860');
        $registry->lookupKrs('0000028860');

        $this->assertCount(2, $this->calls);
        $this->assertFalse(is_dir($this->directory));
    }
}

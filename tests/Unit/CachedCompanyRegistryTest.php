<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Intake\CachedCompanyRegistry;
use App\Intake\RegistryException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCompanyRegistry;

/**
 * Pamięć pośrednia rejestru firm: mniej zapytań do Ministerstwa Finansów przy imporcie
 * i przy przycisku „pobierz po NIP”.
 */
final class CachedCompanyRegistryTest extends TestCase
{
    private string $directory = '';
    private FakeCompanyRegistry $inner;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/certisub-registry-' . bin2hex(random_bytes(6));
        $this->inner = new FakeCompanyRegistry();
        $this->inner->add('6342851974', FakeCompanyRegistry::subject('NOVATECH SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', '6342851974', 'PRZEMYSŁOWA 12, 40-020 KATOWICE'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    private function registry(int $ttlSeconds = 604800): CachedCompanyRegistry
    {
        return new CachedCompanyRegistry($this->inner, $this->directory, $ttlSeconds);
    }

    public function testSecondLookupIsAnsweredFromTheCache(): void
    {
        $registry = $this->registry();

        $first = $registry->lookupNip('6342851974');
        $second = $registry->lookupNip('634-285-19-74');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('Novatech Spółka z Ograniczoną Odpowiedzialnością', $second->name);
        $this->assertSame($first->toArray(), $second->toArray(), 'dane z pamięci muszą być takie same jak z rejestru');
        $this->assertCount(1, $this->inner->queries, 'drugie pytanie nie może iść do rejestru');
    }

    public function testMissingCompanyIsAlsoRemembered(): void
    {
        $registry = $this->registry();

        $this->assertNull($registry->lookupNip('1111111111'));
        $this->assertNull($registry->lookupNip('1111111111'));

        $this->assertCount(1, $this->inner->queries, 'brak firmy to też wynik — nie pytamy dwa razy');
    }

    public function testRegistryFailureIsNotRemembered(): void
    {
        $this->inner->fail('9876543210');
        $registry = $this->registry();

        try {
            $registry->lookupNip('9876543210');
            $this->fail('oczekiwano RegistryException');
        } catch (RegistryException) {
            // tak ma być
        }

        $this->inner->add('9876543210', FakeCompanyRegistry::subject('WISŁA SPÓŁKA AKCYJNA', '9876543210', 'NADRZECZNA 5, 43-460 WISŁA'));

        // Awaria mogła minąć, więc kolejne pytanie znów idzie do rejestru.
        $this->assertNotNull($registry->lookupNip('9876543210'));
        $this->assertCount(2, $this->inner->queries);
    }

    public function testExpiredEntryIsFetchedAgain(): void
    {
        $registry = $this->registry(1);
        $registry->lookupNip('6342851974');

        // Cofamy czas zapisu pliku o dwie sekundy — wpis jest starszy niż TTL.
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            touch($file, time() - 2);
        }

        $registry->lookupNip('6342851974');

        $this->assertCount(2, $this->inner->queries);
    }

    public function testLookupWithAnExplicitDateBypassesTheCache(): void
    {
        $registry = $this->registry();
        $day = new DateTimeImmutable('2026-01-15');

        $registry->lookupNip('6342851974', $day);
        $registry->lookupNip('6342851974', $day);

        $this->assertCount(2, $this->inner->queries, 'status VAT na konkretny dzień nie jest cache\'owany');
        $this->assertSame([], glob($this->directory . '/*.json') ?: []);
    }

    public function testCorruptedCacheFileIsIgnored(): void
    {
        $registry = $this->registry();
        $registry->lookupNip('6342851974');

        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            file_put_contents($file, 'to nie jest JSON');
        }

        $this->assertNotNull($registry->lookupNip('6342851974'));
        $this->assertCount(2, $this->inner->queries);
    }
}

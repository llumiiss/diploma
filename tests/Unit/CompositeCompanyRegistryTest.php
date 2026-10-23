<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Intake\CompanyRecord;
use App\Intake\CompanyRegistry;
use App\Intake\CompositeCompanyRegistry;
use App\Intake\RegistryException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Kilka rejestrów za jednym interfejsem: polski NIP obsługuje Biała lista, numer VAT z Unii — VIES.
 */
final class CompositeCompanyRegistryTest extends TestCase
{
    /**
     * @param list<string> $prefixes numery, które to źródło obsługuje (po prefiksie)
     */
    private function source(array $prefixes, ?CompanyRecord $record, ?RegistryException $error = null, ?array &$asked = null): CompanyRegistry
    {
        return new class ($prefixes, $record, $error, $asked) implements CompanyRegistry {
            /**
             * @param list<string> $prefixes
             */
            public function __construct(
                private readonly array $prefixes,
                private readonly ?CompanyRecord $record,
                private readonly ?RegistryException $error,
                private ?array &$asked,
            ) {
            }

            public function supports(string $taxId): bool
            {
                foreach ($this->prefixes as $prefix) {
                    if (str_starts_with($taxId, $prefix)) {
                        return true;
                    }
                }

                return false;
            }

            public function lookupNip(string $taxId, ?DateTimeImmutable $date = null): ?CompanyRecord
            {
                if ($this->asked !== null) {
                    $this->asked[] = $taxId;
                }

                if ($this->error !== null) {
                    throw $this->error;
                }

                return $this->record;
            }
        };
    }

    private static function record(string $taxId): CompanyRecord
    {
        return new CompanyRecord($taxId, 'FIRMA Z REJESTRU', 'Firma z Rejestru', 'Czynny', null, null, 'Testowa 1', '00-001', 'Warszawa', null, null);
    }

    public function testAsksOnlyTheSourceThatSupportsTheNumber(): void
    {
        $polishAsked = [];
        $euAsked = [];
        $registry = new CompositeCompanyRegistry(
            $this->source(['6', '7', '9'], self::record('6342851974'), null, $polishAsked),
            $this->source(['DE', 'FR'], self::record('DE123456789'), null, $euAsked),
        );

        $record = $registry->lookupNip('6342851974');

        $this->assertNotNull($record);
        $this->assertSame('6342851974', $record->nip);
        $this->assertSame(['6342851974'], $polishAsked);
        $this->assertSame([], $euAsked, 'VIES nie jest pytany o polski NIP');
    }

    public function testSecondSourceAnswersWhenTheFirstDoesNotSupportTheNumber(): void
    {
        $registry = new CompositeCompanyRegistry(
            $this->source(['6'], self::record('6342851974')),
            $this->source(['DE'], self::record('DE123456789')),
        );

        $record = $registry->lookupNip('DE123456789');

        $this->assertNotNull($record);
        $this->assertSame('DE123456789', $record->nip);
    }

    public function testFailureOfOneSourceDoesNotStopTheChain(): void
    {
        $registry = new CompositeCompanyRegistry(
            $this->source(['6'], null, new RegistryException('Rejestr jest niedostępny (HTTP 503).')),
            $this->source(['6'], self::record('6342851974')),
        );

        $record = $registry->lookupNip('6342851974');

        $this->assertNotNull($record, 'drugie źródło obsługujące ten numer ratuje zapytanie');
    }

    public function testFailureIsReportedWhenNoSourceAnswered(): void
    {
        $registry = new CompositeCompanyRegistry(
            $this->source(['6'], null, new RegistryException('Rejestr jest niedostępny (HTTP 503).')),
        );

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessageMatches('/niedostępny/u');

        $registry->lookupNip('6342851974');
    }

    public function testUnknownCompanyGivesNullNotAnError(): void
    {
        $registry = new CompositeCompanyRegistry($this->source(['6'], null));

        $this->assertNull($registry->lookupNip('6342851974'));
    }

    public function testNumberNobodySupportsIsReportedClearly(): void
    {
        $registry = new CompositeCompanyRegistry($this->source(['6'], null), $this->source(['DE'], null));

        $this->assertFalse($registry->supports('US999999999'));

        $this->expectException(RegistryException::class);
        $this->expectExceptionMessageMatches('/Żaden rejestr/u');

        $registry->lookupNip('US999999999');
    }

    public function testSupportsReflectsTheSources(): void
    {
        $registry = new CompositeCompanyRegistry($this->source(['6'], null), $this->source(['DE'], null));

        $this->assertTrue($registry->supports('6342851974'));
        $this->assertTrue($registry->supports('DE123456789'));
        $this->assertFalse($registry->supports('XX1'));
    }
}

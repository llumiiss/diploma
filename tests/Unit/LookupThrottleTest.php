<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\LookupThrottle;
use PHPUnit\Framework\TestCase;

/**
 * Limit zapytań do zewnętrznego rejestru na konto — zabezpieczenie przed pętlą i przed
 * odcięciem całej instalacji od Białej listy przez jedno konto.
 */
final class LookupThrottleTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/certisub-throttle-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testAllowsUpToTheLimitAndThenBlocks(): void
    {
        $throttle = new LookupThrottle($this->directory, 3, 3600);

        $this->assertTrue($throttle->allow('konto-7'));
        $this->assertTrue($throttle->allow('konto-7'));
        $this->assertTrue($throttle->allow('konto-7'));
        $this->assertFalse($throttle->allow('konto-7'), 'czwarte zapytanie w oknie jest odrzucane');
    }

    public function testEachKeyHasItsOwnCounter(): void
    {
        $throttle = new LookupThrottle($this->directory, 1, 3600);

        $this->assertTrue($throttle->allow('konto-1'));
        $this->assertFalse($throttle->allow('konto-1'));
        $this->assertTrue($throttle->allow('konto-2'), 'limit jednego konta nie blokuje innych');
    }

    public function testCounterStartsOverAfterTheWindow(): void
    {
        $throttle = new LookupThrottle($this->directory, 2, 1);

        $this->assertTrue($throttle->allow('konto-7'));
        $this->assertTrue($throttle->allow('konto-7'));
        $this->assertFalse($throttle->allow('konto-7'));

        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $state = json_decode((string) file_get_contents($file), true);
            $state['since'] = time() - 5;
            file_put_contents($file, (string) json_encode($state));
        }

        $this->assertTrue($throttle->allow('konto-7'), 'po upływie okna licznik zaczyna od nowa');
    }

    public function testRemainingReportsWhatIsLeft(): void
    {
        $throttle = new LookupThrottle($this->directory, 5, 3600);

        $this->assertSame(5, $throttle->remaining('konto-9'));
        $throttle->allow('konto-9');
        $throttle->allow('konto-9');
        $this->assertSame(3, $throttle->remaining('konto-9'));
    }

    public function testKeyWithoutUsableCharactersDoesNotBlockTheFeature(): void
    {
        // Brak pliku do zapisu nie może zablokować pobierania danych — limit to zabezpieczenie,
        // nie kontrola dostępu.
        $throttle = new LookupThrottle($this->directory, 1, 3600);

        $this->assertTrue($throttle->allow('///'));
        $this->assertTrue($throttle->allow('///'));
    }
}

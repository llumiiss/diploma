<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Session;
use App\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        Session::ensureStarted();
        $_SESSION = [];
        unset($_GET['lang']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_GET['lang']);
    }

    public function testPolishIsTheDefaultLocale(): void
    {
        $translator = Translator::init();

        $this->assertSame('pl', $translator->locale());
        $this->assertSame('', $translator->querySuffix());
    }

    public function testChosenLocaleIsKeptInLinks(): void
    {
        $_GET['lang'] = 'en';

        $translator = Translator::init();

        $this->assertSame('en', $translator->locale());
        $this->assertSame('?lang=en', $translator->querySuffix());
    }

    public function testUnsupportedLocaleFallsBackToDefault(): void
    {
        $_SESSION['lang'] = 'fr';

        $this->assertSame('pl', Translator::init()->locale());
    }

    public function testMissingKeyFallsBackToEnglish(): void
    {
        // Nowe teksty dodajemy tylko po polsku i angielsku (decyzja D4) — pozostałe języki dziedziczą EN.
        $_GET['lang'] = 'de';
        $english = require dirname(__DIR__, 2) . '/lang/en.php';

        $this->assertSame($english['auth.error.delete_blocked'], Translator::get('auth.error.delete_blocked'));
    }

    public function testUnknownKeyIsReturnedUnchanged(): void
    {
        $this->assertSame('no.such.key', Translator::get('no.such.key'));
    }
}

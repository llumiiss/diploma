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

    /**
     * Każdy język ma własny tekst dla wszystkich kluczy (uzupełnione 2026-10-23).
     * Pełność zestawu kluczy pilnuje TranslationFilesTest.
     */
    public function testTranslationIsTakenFromTheChosenLocale(): void
    {
        $_GET['lang'] = 'de';
        $english = require dirname(__DIR__, 2) . '/lang/en.php';
        $german = require dirname(__DIR__, 2) . '/lang/de.php';

        $translated = Translator::get('auth.error.delete_blocked');

        $this->assertSame($german['auth.error.delete_blocked'], $translated);
        $this->assertNotSame($english['auth.error.delete_blocked'], $translated);
    }

    /**
     * Zestaw kluczy budujemy z pliku angielskiego i nadpisujemy wybranym językiem,
     * więc gdyby w przyszłości jakiegoś klucza w nim zabrakło, tekst będzie angielski,
     * a nie surowa nazwa klucza.
     */
    public function testLocaleFileIsMergedOnTopOfEnglish(): void
    {
        $_GET['lang'] = 'uk';
        $english = require dirname(__DIR__, 2) . '/lang/en.php';

        $lines = Translator::init()->all();

        $this->assertSame([], array_diff(array_keys($english), array_keys($lines)));
    }

    public function testUnknownKeyIsReturnedUnchanged(): void
    {
        $this->assertSame('no.such.key', Translator::get('no.such.key'));
    }
}

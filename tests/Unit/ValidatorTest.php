<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\ServiceException;
use App\Service\Validator;
use App\Session;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        Session::ensureStarted();
        $_SESSION = [];
    }

    public function testDatesAreCheckedAgainstTheCalendar(): void
    {
        $this->assertTrue(Validator::isValidDate('2028-02-29'));
        $this->assertFalse(Validator::isValidDate('2026-02-31'));
        $this->assertFalse(Validator::isValidDate('2026-13-01'));
        $this->assertFalse(Validator::isValidDate('16.09.2026'));

        $v = new Validator(['expiry_date' => '2026-02-31', 'valid_from' => '']);
        $this->assertNull($v->date('expiry_date', true));
        $this->assertNull($v->date('valid_from', false));
        $this->assertSame(['expiry_date'], array_keys($v->errors()));
    }

    public function testDiscountAcceptsSpreadsheetNotationAndStoresSizeOfDiscount(): void
    {
        foreach ([['-5%', 5.0], ['-5', 5.0], ['5', 5.0], ['5,5 %', 5.5], ['−10 %', 10.0], [10, 10.0], [-3, 3.0], ['', 0.0], [null, 0.0], ['0', 0.0], ['100', 100.0]] as [$input, $expected]) {
            $v = new Validator(['discount_percent' => $input]);
            $this->assertSame($expected, $v->discountPercent('discount_percent'), 'wejście: ' . var_export($input, true));
            $this->assertFalse($v->fails());
        }

        foreach (['+5%', '101', '-150', 'dużo', '5 zł', '1e2'] as $input) {
            $v = new Validator(['discount_percent' => $input]);
            $v->discountPercent('discount_percent');
            $this->assertSame(['discount_percent'], array_keys($v->errors()), 'wejście: ' . $input);
        }
    }

    public function testPolishNipChecksumAndEuVatNumbers(): void
    {
        $this->assertTrue(Validator::isValidTaxId('6342851974'));
        $this->assertTrue(Validator::isValidTaxId('PL6342851974'));
        $this->assertFalse(Validator::isValidTaxId('1234567890'));
        $this->assertFalse(Validator::isValidTaxId('6342851975'));
        $this->assertTrue(Validator::isValidTaxId('DE123456789'));
        $this->assertFalse(Validator::isValidTaxId('PLABC'));

        $v = new Validator(['tax_id' => 'pl 634-285-19-74']);
        $this->assertSame('6342851974', $v->taxId('tax_id'));
        $this->assertFalse($v->fails());
    }

    public function testNumbersAcceptPolishDecimalCommaAndRespectRange(): void
    {
        $v = new Validator(['cost' => '1 234,50', 'negative' => '-5', 'days' => '30', 'bad_days' => '3.5']);

        $this->assertSame(1234.5, $v->decimal('cost', true, 0, 1_000_000));
        $this->assertNull($v->decimal('negative', true, 0, 100));
        $this->assertSame(30, $v->int('days', false, 0, 3650));
        $this->assertNull($v->int('bad_days', false, 0, 3650));
        $this->assertSame(['negative', 'bad_days'], array_keys($v->errors()));
    }

    public function testStringsAreTrimmedAndLimited(): void
    {
        $v = new Validator(['name' => '  Jan  ', 'long' => str_repeat('ą', 6), 'email' => ' Jan.Kowalski@Example.COM ']);

        $this->assertSame('Jan', $v->string('name', true, 100));
        $this->assertSame(str_repeat('ą', 6), $v->string('long', true, 5));
        $this->assertSame('jan.kowalski@example.com', $v->email('email', true));
        $this->assertNull($v->string('missing', true, 10));
        $this->assertSame(['long', 'missing'], array_keys($v->errors()));
    }

    public function testEnumUsesDefaultForEmptyValueButRejectsUnknownOne(): void
    {
        $v = new Validator(['status' => '', 'cycle' => 'weekly']);

        $this->assertSame('active', $v->enum('status', false, ['active', 'expired'], 'active'));
        $v->enum('cycle', false, ['monthly', 'annual'], 'annual');

        $this->expectException(ServiceException::class);
        $v->throwIfFailed();
    }
}

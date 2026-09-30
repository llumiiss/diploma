<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsALongEnoughMixedPassword(): void
    {
        $this->assertNull(PasswordPolicy::validate('TajneHaslo123'));
        $this->assertNull(PasswordPolicy::validate('kot-plotek-2026!'));
    }

    public function testRejectsShortPasswords(): void
    {
        $this->assertSame('auth.error.password_too_short', PasswordPolicy::validate('Krotkie1'));
    }

    public function testRejectsPasswordsWithoutADigitOrSymbol(): void
    {
        $this->assertSame('auth.error.password_too_simple', PasswordPolicy::validate('samelitery'));
    }

    public function testRejectsPasswordsWithoutALetter(): void
    {
        $this->assertSame('auth.error.password_too_simple', PasswordPolicy::validate('1234567890'));
    }

    public function testRejectsWellKnownPasswords(): void
    {
        $this->assertSame('auth.error.password_common', PasswordPolicy::validate('haslo12345'));
        $this->assertSame('auth.error.password_common', PasswordPolicy::validate('qwerty1234'));
    }

    public function testRejectsPasswordContainingTheEmailName(): void
    {
        $this->assertSame(
            'auth.error.password_contains_email',
            PasswordPolicy::validate('anna.nowak2026', 'anna.nowak@example.com')
        );
    }

    public function testRejectsAbsurdlyLongPasswords(): void
    {
        $this->assertSame(
            'auth.error.password_too_long',
            PasswordPolicy::validate(str_repeat('a1', PasswordPolicy::MAX_LENGTH))
        );
    }

    public function testHashIsSaltedSoTwoHashesDiffer(): void
    {
        $this->assertNotSame(PasswordPolicy::hash('TajneHaslo123'), PasswordPolicy::hash('TajneHaslo123'));
    }
}

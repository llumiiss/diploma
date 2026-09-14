<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\MailConfig;
use PHPUnit\Framework\TestCase;

final class MailConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(MailConfig::class);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testDefaultConfigUsesSandboxDriver(): void
    {
        $this->assertSame('sandbox', MailConfig::driver());
        $this->assertTrue(MailConfig::isSandbox());
    }

    public function testProductionSmtpNeverLogsOtpCodes(): void
    {
        $ref = new \ReflectionClass(MailConfig::class);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue(null, [
            'driver' => 'smtp',
            'dev_log_codes' => true,
        ]);

        $this->assertFalse(MailConfig::shouldLogOtpCodes());
    }
}

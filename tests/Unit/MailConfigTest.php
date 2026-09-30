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

    /**
     * @param array<string, mixed> $config
     */
    private function setConfig(array $config): void
    {
        $prop = (new \ReflectionClass(MailConfig::class))->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue(null, $config);
    }

    public function testDefaultConfigUsesSandboxDriver(): void
    {
        $this->assertSame('sandbox', MailConfig::driver());
        $this->assertTrue(MailConfig::isSandbox());
    }

    public function testOauth2DriverIsRecognisedAsRealDelivery(): void
    {
        $this->setConfig([
            'driver' => 'oauth2',
            'dev_log_codes' => true,
            'oauth2' => ['provider' => 'google', 'user_email' => 'skrzynka@example.com'],
        ]);

        $this->assertSame('oauth2', MailConfig::driver());
        $this->assertTrue(MailConfig::isOauth2());
        $this->assertTrue(MailConfig::isRealDelivery());
        $this->assertFalse(MailConfig::isSandbox());
        $this->assertSame('skrzynka@example.com', MailConfig::oauth2()['user_email']);
        $this->assertFalse(MailConfig::shouldLogOtpCodes(), 'przy prawdziwej wysyłce nie logujemy linków');
    }

    public function testUnknownDriverFallsBackToSandbox(): void
    {
        $this->setConfig(['driver' => 'wymyslony']);

        $this->assertSame('sandbox', MailConfig::driver());
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

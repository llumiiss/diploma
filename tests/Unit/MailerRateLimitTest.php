<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Rozpoznawanie odmowy „za dużo wiadomości na sekundę”. Tylko taki błąd wolno ponowić:
 * serwer odrzuca wiadomość przed przyjęciem, więc ponowienie nie wyśle duplikatu.
 */
final class MailerRateLimitTest extends TestCase
{
    public function testRecognisesTheMailtrapPerSecondLimit(): void
    {
        $error = 'SMTP Error: data not accepted. SMTP server error: DATA command failed '
            . 'Detail: Too many emails per second. Please upgrade your plan '
            . 'https://mailtrap.io/billing/plans/testing SMTP code: 550 Additional SMTP info: 5.7.0';

        $this->assertTrue(Mailer::isRateLimited($error));
    }

    public function testRecognisesGmailAndOutlookThrottling(): void
    {
        $this->assertTrue(Mailer::isRateLimited('421-4.7.28 Gmail has detected an unusual rate of mail'));
        $this->assertTrue(Mailer::isRateLimited('452 4.3.1 Too many messages for this session'));
        $this->assertTrue(Mailer::isRateLimited('451 Server busy, try again later'));
    }

    public function testDoesNotRetryOtherFailures(): void
    {
        // Te błędy powtórzą się co do joty, a przy niektórych ponowienie mogłoby wysłać duplikat.
        $this->assertFalse(Mailer::isRateLimited('SMTP Error: Could not authenticate.'));
        $this->assertFalse(Mailer::isRateLimited('550 5.1.1 The email account that you tried to reach does not exist'));
        $this->assertFalse(Mailer::isRateLimited('SMTP connect() failed.'));
        $this->assertFalse(Mailer::isRateLimited(''));
    }
}

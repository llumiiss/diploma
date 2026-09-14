<?php

declare(strict_types=1);

namespace Tests\Support;

use App\OtpMailer;

final class RecordingOtpMailer implements OtpMailer
{
    /** @var list<array{email: string, code: string}> */
    public array $sent = [];

    public function sendOtpCode(string $toEmail, string $code): bool
    {
        $this->sent[] = ['email' => $toEmail, 'code' => $code];

        return true;
    }
}

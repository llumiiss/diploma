<?php

declare(strict_types=1);

namespace App;

interface OtpMailer
{
    public function sendOtpCode(string $toEmail, string $code): bool;
}

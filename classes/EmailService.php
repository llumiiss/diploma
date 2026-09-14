<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as PhpMailerException;

final class EmailService implements OtpMailer
{
    private ?Mailer $mailer = null;

    public function __construct(private ?Mailer $injectedMailer = null)
    {
    }

    public function sendOtpCode(string $toEmail, string $code): bool
    {
        $subject = __('auth.email.subject');
        $textBody = __('auth.email.body', ['code' => $code]);
        $htmlBody = '<p>' . nl2br(htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8')) . '</p>';

        try {
            $this->getMailer()->send($toEmail, $subject, $htmlBody, '', $textBody);
            return true;
        } catch (PhpMailerException $e) {
            if (MailConfig::shouldLogOtpCodes()) {
                $this->logOtpForDev($toEmail, $code, false, $e->getMessage());
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            if (MailConfig::shouldLogOtpCodes()) {
                $this->logOtpForDev($toEmail, $code, false, $e->getMessage());
                return true;
            }
            return false;
        }
    }

    private function getMailer(): Mailer
    {
        if ($this->injectedMailer !== null) {
            return $this->injectedMailer;
        }

        if ($this->mailer === null) {
            $this->mailer = new Mailer();
        }

        return $this->mailer;
    }

    private function logOtpForDev(string $toEmail, string $code, bool $mailSent, string $error = ''): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $status = $mailSent ? 'mail_sent' : 'mail_failed';
        $suffix = $error !== '' ? ' error=' . $error : '';
        $line = sprintf(
            "[%s] %s → %s (code: %s)%s\n",
            date('Y-m-d H:i:s'),
            $status,
            $toEmail,
            $code,
            $suffix
        );

        @file_put_contents($logDir . '/otp.log', $line, FILE_APPEND | LOCK_EX);
    }
}

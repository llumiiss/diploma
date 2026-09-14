<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->config = MailConfig::all();
    }

    /**
     * @throws PhpMailerException
     */
    public function send(
        string $toEmail,
        string $subject,
        string $htmlBody,
        string $toName = '',
        ?string $textBody = null
    ): void {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isHTML(true);

        $fromEmail = (string) ($this->config['from_email'] ?? 'noreply@localhost');
        $fromName = (string) ($this->config['from_name'] ?? 'CertiSub');
        $mail->setFrom($fromEmail, $fromName);

        if ($toName !== '') {
            $mail->addAddress($toEmail, $toName);
        } else {
            $mail->addAddress($toEmail);
        }

        $smtp = $this->config['smtp'] ?? [];
        if (!empty($smtp['enabled'])) {
            $mail->isSMTP();
            $mail->Host = (string) ($smtp['host'] ?? '');
            $mail->Port = (int) ($smtp['port'] ?? 587);
            $mail->SMTPAuth = (bool) ($smtp['auth'] ?? true);
            $mail->Username = (string) ($smtp['username'] ?? '');
            $mail->Password = (string) ($smtp['password'] ?? '');

            $secure = strtolower((string) ($smtp['smtp_secure'] ?? 'tls'));
            if ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }
        }

        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?? strip_tags($htmlBody);

        $mail->send();
    }
}

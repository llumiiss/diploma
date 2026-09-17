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
     * @param list<array{path: string, name: string}> $attachments
     *
     * @throws PhpMailerException
     */
    public function send(
        string $toEmail,
        string $subject,
        string $htmlBody,
        string $toName = '',
        ?string $textBody = null,
        array $attachments = []
    ): void {
        if (MailConfig::driver() === 'log') {
            $this->writeToLog($toEmail, $toName, $subject, $textBody ?? strip_tags($htmlBody), $attachments);

            return;
        }

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

        foreach ($attachments as $attachment) {
            $mail->addAttachment($attachment['path'], $attachment['name']);
        }

        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?? strip_tags($htmlBody);

        $mail->send();
    }

    /**
     * Sterownik „log” — do demonstracji i instalacji bez serwera SMTP: wiadomość trafia
     * do logs/mail.log zamiast do odbiorcy. Nie używać w produkcji (kody logowania w pliku).
     *
     * @param list<array{path: string, name: string}> $attachments
     */
    private function writeToLog(string $toEmail, string $toName, string $subject, string $textBody, array $attachments): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $names = array_map(static fn (array $attachment): string => $attachment['name'], $attachments);
        $entry = sprintf(
            "[%s] To: %s <%s>\nSubject: %s\nAttachments: %s\n\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $toName,
            $toEmail,
            $subject,
            $names === [] ? '—' : implode(', ', $names),
            $textBody,
            str_repeat('-', 72)
        );

        file_put_contents($logDir . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
    }
}

<?php

declare(strict_types=1);

namespace App;

use App\Mail\OAuth2TokenProvider;
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

        if (MailConfig::isOauth2()) {
            $this->configureOauth2($mail);
        } else {
            $smtp = $this->config['smtp'] ?? [];
            if (is_array($smtp) && !empty($smtp['enabled'])) {
                $mail->isSMTP();
                $mail->Host = (string) ($smtp['host'] ?? '');
                $mail->Port = (int) ($smtp['port'] ?? 587);
                $mail->SMTPAuth = (bool) ($smtp['auth'] ?? true);
                $mail->Username = (string) ($smtp['username'] ?? '');
                $mail->Password = (string) ($smtp['password'] ?? '');
                $this->applyEncryption($mail, (string) ($smtp['smtp_secure'] ?? 'tls'));
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
     * Sterownik „oauth2”: SMTP bez hasła do skrzynki. PHPMailer pyta dostawcę tokenów
     * o ciąg XOAUTH2 (klasa OAuth2TokenProvider), a ten wymienia refresh_token na
     * krótkotrwały access_token. Nazwa użytkownika SMTP to adres skrzynki wysyłającej.
     */
    private function configureOauth2(PHPMailer $mail): void
    {
        $oauth2 = MailConfig::oauth2();

        $mail->isSMTP();
        $mail->Host = (string) ($oauth2['host'] ?? 'smtp.gmail.com');
        $mail->Port = (int) ($oauth2['port'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->AuthType = 'XOAUTH2';
        $mail->Username = (string) ($oauth2['user_email'] ?? '');
        $mail->setOAuth(new OAuth2TokenProvider($oauth2));
        $this->applyEncryption($mail, (string) ($oauth2['smtp_secure'] ?? 'tls'));
    }

    private function applyEncryption(PHPMailer $mail, string $mode): void
    {
        $secure = strtolower($mode);

        if ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
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

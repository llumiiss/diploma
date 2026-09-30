<?php

declare(strict_types=1);

namespace App;

use App\Mail\OAuth2TokenProvider;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /** Przerwa przed jedynym ponowieniem wysyłki odrzuconej przez limit (2 s). */
    private const RATE_LIMIT_RETRY_DELAY_US = 2_000_000;

    /** Fragmenty odpowiedzi SMTP oznaczające „za szybko, zwolnij”. */
    private const RATE_LIMIT_SIGNATURES = [
        'too many emails per second',
        'too many messages',
        'rate limit',
        'try again later',
        '4.7.28',
    ];

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

        try {
            $mail->send();
        } catch (PhpMailerException $e) {
            // Serwer przyjmuje ograniczoną liczbę wiadomości na sekundę (Mailtrap w darmowym
            // planie: jedną; podobnie zachowują się Gmail i Outlook przy szybkiej serii).
            // W takiej sytuacji odczekujemy chwilę i próbujemy raz jeszcze — inaczej druga
            // wiadomość wysłana w tej samej sekundzie (np. przypomnienia z crona albo link
            // do hasła zaraz po rejestracji) przepadałaby bez powodu.
            // Ponawiamy TYLKO ten błąd: serwer odrzucił wiadomość przed przyjęciem,
            // więc nie grozi to wysłaniem duplikatu.
            if (!self::isRateLimited($mail->ErrorInfo)) {
                throw self::describe($mail, $e);
            }

            // Po odmowie w środku rozmowy SMTP połączenie zostaje otwarte w połowie transakcji
            // (kolejne MAIL FROM dostałoby „503 nested MAIL command”), więc zamykamy je
            // i druga próba nawiązuje połączenie od nowa.
            $mail->smtpClose();
            usleep(self::RATE_LIMIT_RETRY_DELAY_US);

            try {
                $mail->send();
            } catch (PhpMailerException $retry) {
                throw self::describe($mail, $retry);
            }
        }
    }

    /**
     * Wyjątek z pełną odpowiedzią serwera. PHPMailer w komunikacie wyjątku podaje tylko
     * ogólne „data not accepted”, a powód (kod i opis SMTP) trzyma w ErrorInfo — bez tego
     * w logu nie byłoby widać, czy to limit, odrzucony nadawca, czy błędne hasło.
     */
    private static function describe(PHPMailer $mail, PhpMailerException $exception): PhpMailerException
    {
        $info = trim($mail->ErrorInfo);

        if ($info === '' || $info === $exception->getMessage()) {
            return $exception;
        }

        return new PhpMailerException($info, (int) $exception->getCode(), $exception);
    }

    /**
     * Czy serwer odrzucił wiadomość z powodu limitu liczby wiadomości w czasie.
     * Rozpoznajemy po treści odpowiedzi SMTP, bo kody są różne u różnych dostawców.
     */
    public static function isRateLimited(string $smtpError): bool
    {
        $error = strtolower($smtpError);

        foreach (self::RATE_LIMIT_SIGNATURES as $signature) {
            if (str_contains($error, $signature)) {
                return true;
            }
        }

        return false;
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

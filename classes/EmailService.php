<?php

declare(strict_types=1);

namespace App;

/**
 * Wiadomości uwierzytelniające wysyłane przez PHPMailer — przy sterowniku „oauth2”
 * autoryzacja SMTP odbywa się tokenem OAuth2 (XOAUTH2), bez hasła do skrzynki.
 *
 * Gdy wysyłka nie uda się, a konfiguracja pozwala na zapis pomocniczy (dev_log_codes),
 * link trafia do logs/auth-links.log — tylko dla pracy lokalnej i pokazu bez SMTP.
 */
final class EmailService implements AuthMailer
{
    private ?Mailer $mailer = null;

    public function __construct(private ?Mailer $injectedMailer = null)
    {
    }

    public function sendEmailVerification(string $toEmail, string $toName, string $link): bool
    {
        return $this->sendLink(
            $toEmail,
            $toName,
            __('auth.email.verify_subject'),
            __('auth.email.verify_body', [
                'name'  => $toName !== '' ? $toName : $toEmail,
                'link'  => $link,
                'hours' => (string) Auth\VerificationTokens::EMAIL_VERIFY_TTL_HOURS,
            ]),
            __('auth.email.verify_button'),
            $link
        );
    }

    public function sendPasswordSetLink(string $toEmail, string $toName, string $link): bool
    {
        return $this->sendLink(
            $toEmail,
            $toName,
            __('auth.email.password_subject'),
            __('auth.email.password_body', [
                'name'  => $toName !== '' ? $toName : $toEmail,
                'link'  => $link,
                'hours' => (string) Auth\VerificationTokens::PASSWORD_SET_TTL_HOURS,
            ]),
            __('auth.email.password_button'),
            $link
        );
    }

    private function sendLink(
        string $toEmail,
        string $toName,
        string $subject,
        string $textBody,
        string $buttonLabel,
        string $link
    ): bool {
        $htmlBody = $this->renderHtml($textBody, $buttonLabel, $link);

        try {
            $this->getMailer()->send($toEmail, $subject, $htmlBody, $toName, $textBody);

            return true;
        } catch (\Throwable $e) {
            if (MailConfig::shouldLogOtpCodes()) {
                $this->logLinkForDev($toEmail, $link, $e->getMessage());

                return true;
            }

            return false;
        }
    }

    /**
     * Treść wiadomości: akapity z wersji tekstowej + przycisk z odnośnikiem.
     * Sam adres podajemy też jawnie, bo część programów pocztowych nie pokazuje przycisków.
     */
    private function renderHtml(string $textBody, string $buttonLabel, string $link): string
    {
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $paragraphs = '';

        foreach (preg_split('/\n{2,}/', $textBody) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '' || str_contains($paragraph, $link)) {
                continue;
            }

            $paragraphs .= '<p style="margin:0 0 16px">'
                . nl2br(htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8'))
                . '</p>';
        }

        return '<div style="font:15px/1.6 Arial,sans-serif;color:#1e293b;max-width:520px">'
            . $paragraphs
            . '<p style="margin:24px 0"><a href="' . $safeLink . '"'
            . ' style="background:#2563eb;color:#fff;padding:12px 20px;border-radius:8px;'
            . 'text-decoration:none;font-weight:bold;display:inline-block">'
            . htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8')
            . '</a></p>'
            . '<p style="margin:0;font-size:13px;color:#64748b">' . $safeLink . '</p>'
            . '</div>';
    }

    private function getMailer(): Mailer
    {
        if ($this->injectedMailer !== null) {
            return $this->injectedMailer;
        }

        return $this->mailer ??= new Mailer();
    }

    private function logLinkForDev(string $toEmail, string $link, string $error): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $line = sprintf(
            "[%s] mail_failed → %s link=%s error=%s\n",
            date('Y-m-d H:i:s'),
            $toEmail,
            $link,
            $error
        );

        @file_put_contents($logDir . '/auth-links.log', $line, FILE_APPEND | LOCK_EX);
    }
}

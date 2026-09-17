<?php

declare(strict_types=1);

namespace App\Service;

use App\Mailer;

/**
 * Wysyłka zaproszeń przez skonfigurowaną pocztę aplikacji (config/mail.php).
 */
final class MailerInvitationMailer implements InvitationMailer
{
    private ?Mailer $mailer;

    public function __construct(?Mailer $mailer = null)
    {
        $this->mailer = $mailer;
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody, array $attachments): void
    {
        $this->mailer ??= new Mailer();
        $this->mailer->send($toEmail, $subject, $htmlBody, $toName, $textBody, $attachments);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\InvitationMailer;
use RuntimeException;

final class RecordingInvitationMailer implements InvitationMailer
{
    /** @var list<array{to: string, name: string, subject: string, html: string, text: string, attachments: list<array{path: string, name: string}>}> */
    public array $sent = [];

    public ?string $failWith = null;

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody, array $attachments): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->sent[] = [
            'to'          => $toEmail,
            'name'        => $toName,
            'subject'     => $subject,
            'html'        => $htmlBody,
            'text'        => $textBody,
            'attachments' => $attachments,
        ];
    }
}

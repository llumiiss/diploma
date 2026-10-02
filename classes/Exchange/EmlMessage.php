<?php

declare(strict_types=1);

namespace App\Exchange;

/**
 * Wiadomość e-mail odczytana z pliku EML: nagłówki, nadawca, treść tekstowa i załączniki.
 */
final class EmlMessage
{
    /**
     * @param array<string, string>                                                        $headers  nazwa (małe litery) → wartość
     * @param array{name: ?string, email: ?string}                                         $from
     * @param list<array{name: ?string, email: ?string}>                                   $to
     * @param list<array{filename: string, content_type: string, content: string, size: int}> $attachments
     */
    public function __construct(
        public readonly array $headers,
        public readonly ?string $subject,
        public readonly array $from,
        public readonly array $to,
        public readonly ?string $date,
        public readonly ?string $messageId,
        public readonly string $text,
        public readonly array $attachments,
        /** Treść HTML (jeśli wiadomość ją zawiera) — z niej wyciągane są tabele z formularzy. */
        public readonly string $html = '',
    ) {
    }
}

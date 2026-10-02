<?php

declare(strict_types=1);

namespace App\Exchange;

use App\Service\ServiceException;
use App\Service\TemplateRenderer;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Parser wiadomości e-mail w formacie EML (RFC 5322 + MIME) w czystym PHP — bez rozszerzenia
 * mailparse. Obsługuje nagłówki łamane i kodowane (RFC 2047), treść w base64 i quoted-printable,
 * zestawy znaków, części multipart na dowolnym poziomie zagnieżdżenia oraz nazwy załączników
 * w zapisie RFC 2231.
 */
final class EmlParser
{
    public const MAX_BYTES = 10_000_000;
    private const MAX_DEPTH = 10;

    public static function parse(string $raw): EmlMessage
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw ServiceException::validation(['file' => \__('exchange.error.file_too_large', ['size' => '10 MB'])]);
        }

        $raw = str_replace(["\r\n", "\r"], "\n", ltrim($raw, "\xEF\xBB\xBF"));
        [$headerBlock, $body] = self::splitHeaderBody($raw);
        $headers = self::parseHeaders($headerBlock);

        if (!isset($headers['from']) && !isset($headers['subject']) && !isset($headers['content-type'])) {
            throw ServiceException::validation(['file' => \__('exchange.error.eml_invalid')]);
        }

        $texts = [];
        $htmls = [];
        $attachments = [];
        self::walk($headers, $body, $texts, $htmls, $attachments, 0);

        $text = trim(implode("\n\n", $texts));
        if ($text === '' && $htmls !== []) {
            $text = TemplateRenderer::htmlToText(implode("\n", $htmls));
        }

        $from = self::addresses($headers['from'] ?? '');

        return new EmlMessage(
            $headers,
            isset($headers['subject']) ? self::decodeHeader($headers['subject']) : null,
            $from[0] ?? ['name' => null, 'email' => null],
            self::addresses($headers['to'] ?? ''),
            self::date($headers['date'] ?? null),
            isset($headers['message-id']) ? trim($headers['message-id'], " <>\t") : null,
            $text,
            $attachments,
            trim(implode('
', $htmls)),
        );
    }

    /**
     * Adresy z nagłówka From/To: „Jan Kowalski <jan@example.com>, ania@example.com”.
     *
     * @return list<array{name: ?string, email: ?string}>
     */
    public static function addresses(string $value): array
    {
        $value = self::decodeHeader($value);
        $parts = [];
        $current = '';
        $quoted = false;
        $angle = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ($char === '"') {
                $quoted = !$quoted;
            } elseif ($char === '<' && !$quoted) {
                $angle = true;
            } elseif ($char === '>' && !$quoted) {
                $angle = false;
            } elseif ($char === ',' && !$quoted && !$angle) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        $addresses = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(.*)<([^>]*)>\s*$/s', $part, $m) === 1) {
                $name = trim(trim($m[1]), "\"' ");
                $email = trim($m[2]);
            } else {
                $name = '';
                $email = $part;
            }
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null;
            $addresses[] = ['name' => $name !== '' ? $name : null, 'email' => $email];
        }

        return $addresses;
    }

    public static function decodeHeader(string $value): string
    {
        // Bez słów kodowanych (=?…?=) wartość zostaje — iconv_mime_decode gubi znaki spoza ASCII,
        // a część programów wysyła nagłówki wprost w UTF-8.
        if (!str_contains($value, '=?')) {
            return trim(mb_scrub($value, 'UTF-8'));
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded === false) {
            $decoded = mb_decode_mimeheader($value);
        }

        return trim(mb_scrub($decoded, 'UTF-8'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitHeaderBody(string $raw): array
    {
        $position = strpos($raw, "\n\n");
        if ($position === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $position), substr($raw, $position + 2)];
    }

    /**
     * Nagłówki z rozwiniętymi liniami kontynuacji. Przy powtórzeniach zostaje pierwsze wystąpienie
     * (dla From, Subject czy Content-Type to wartość właściwa; Received nie jest potrzebny).
     *
     * @return array<string, string>
     */
    private static function parseHeaders(string $block): array
    {
        $headers = [];
        $name = null;
        foreach (explode("\n", $block) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $name !== null) {
                $headers[$name] .= ' ' . trim($line);
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                $name = null;
                continue;
            }
            $candidate = strtolower(trim(substr($line, 0, $colon)));
            if ($candidate === '' || isset($headers[$candidate])) {
                $name = null;
                continue;
            }
            $name = $candidate;
            $headers[$name] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /**
     * @param array<string, string>                                                             $headers
     * @param list<string>                                                                      $texts
     * @param list<string>                                                                      $htmls
     * @param list<array{filename: string, content_type: string, content: string, size: int}> $attachments
     */
    private static function walk(array $headers, string $body, array &$texts, array &$htmls, array &$attachments, int $depth): void
    {
        [$type, $params] = self::headerWithParams($headers['content-type'] ?? 'text/plain; charset=us-ascii');
        [$disposition, $dispositionParams] = self::headerWithParams($headers['content-disposition'] ?? '');

        if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < self::MAX_DEPTH) {
            foreach (self::multipartParts($body, $params['boundary']) as $part) {
                [$partHeaders, $partBody] = self::splitHeaderBody($part);
                self::walk(self::parseHeaders($partHeaders), $partBody, $texts, $htmls, $attachments, $depth + 1);
            }

            return;
        }

        $content = self::decodeBody($body, strtolower(trim($headers['content-transfer-encoding'] ?? '7bit')));
        $filename = $dispositionParams['filename'] ?? $params['name'] ?? null;

        $isAttachment = $disposition === 'attachment'
            || ($filename !== null && !in_array($type, ['text/plain', 'text/html'], true))
            || $type === 'message/rfc822';

        if ($isAttachment) {
            $attachments[] = [
                'filename'     => $filename !== null ? self::decodeHeader($filename) : ($type === 'message/rfc822' ? 'message.eml' : 'attachment'),
                'content_type' => $type,
                'content'      => $content,
                'size'         => strlen($content),
            ];

            return;
        }

        if ($type === 'text/plain' || $type === 'text/html') {
            $text = self::toUtf8($content, $params['charset'] ?? 'us-ascii');
            if ($type === 'text/plain') {
                $texts[] = $text;
            } else {
                $htmls[] = $text;
            }
        }
    }

    /**
     * Wartość nagłówka i parametry, np. „text/plain; charset="utf-8"”. Parametry w zapisie RFC 2231
     * (filename*=UTF-8''raport%20.csv, także dzielone na części filename*0*=) są składane i dekodowane.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function headerWithParams(string $value): array
    {
        $segments = self::splitParameters($value);
        $main = strtolower(trim((string) array_shift($segments)));
        $params = [];
        $extended = [];

        foreach ($segments as $segment) {
            $equals = strpos($segment, '=');
            if ($equals === false) {
                continue;
            }
            $key = strtolower(trim(substr($segment, 0, $equals)));
            $raw = trim(substr($segment, $equals + 1));
            $unquoted = strlen($raw) >= 2 && $raw[0] === '"' && str_ends_with($raw, '"')
                ? str_replace(['\\"', '\\\\'], ['"', '\\'], substr($raw, 1, -1))
                : $raw;

            if (preg_match('/^([a-z0-9_-]+)\*(?:(\d+)\*?)?$/', $key, $m) === 1) {
                $extended[$m[1]][(int) ($m[2] ?? 0)] = [$unquoted, str_ends_with($key, '*')];
                continue;
            }
            $params[$key] = $unquoted;
        }

        foreach ($extended as $key => $pieces) {
            ksort($pieces);
            $joined = '';
            $charset = 'utf-8';
            foreach ($pieces as $index => [$piece, $encoded]) {
                if ($index === 0 && $encoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $piece, $m) === 1) {
                    $charset = $m[1] !== '' ? $m[1] : 'utf-8';
                    $piece = $m[2];
                }
                $joined .= $encoded ? rawurldecode($piece) : $piece;
            }
            $params[$key] = self::toUtf8($joined, $charset);
        }

        return [$main, $params];
    }

    /**
     * @return list<string>
     */
    private static function splitParameters(string $value): array
    {
        $segments = [];
        $current = '';
        $quoted = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ($char === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $quoted = !$quoted;
            }
            if ($char === ';' && !$quoted) {
                $segments[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $segments[] = $current;

        return $segments;
    }

    /**
     * @return list<string>
     */
    private static function multipartParts(string $body, string $boundary): array
    {
        $parts = [];
        $current = null;
        foreach (explode("\n", $body) as $line) {
            $trimmed = rtrim($line);
            if ($trimmed === '--' . $boundary) {
                if ($current !== null) {
                    $parts[] = implode("\n", $current);
                }
                $current = [];
                continue;
            }
            if ($trimmed === '--' . $boundary . '--') {
                if ($current !== null) {
                    $parts[] = implode("\n", $current);
                }
                $current = null;
                break;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null) {
            $parts[] = implode("\n", $current);
        }

        return $parts;
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => (string) base64_decode((string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset, " \"'"));
        if (in_array($charset, ['', 'utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return mb_scrub($text, 'UTF-8');
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);

        return $converted !== false ? $converted : mb_scrub($text, 'UTF-8');
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Nazwa dnia tygodnia potrafi nie zgadzać się z datą, a wtedy PHP przesuwa datę do tego dnia;
        // komentarz w nawiasie na końcu (np. „(CEST)”) też bywa nieparsowalny.
        $value = (string) preg_replace('/^\s*[A-Za-z]{2,9}\s*,\s*/', '', $value);
        $value = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $value));

        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Exchange\Columns;
use App\Exchange\EmlMessage;
use App\Exchange\EmlParser;
use PDO;
use Throwable;

/**
 * Import wiadomości e-mail (EML) do ewidencji (F10).
 *
 * Analiza wiadomości wskazuje, co z nią zrobić: nadawca rozpoznany jako użytkownik certyfikatu
 * lub płatnik (albo propozycja nowej osoby z nazwą, adresem i telefonem z treści), certyfikaty
 * po numerach seryjnych i płatnicy po NIP-ach wymienionych w wiadomości, otwarte zaproszenia
 * wysłane na adres nadawcy oraz załączniki CSV/XML gotowe do importu.
 *
 * Zastosowanie wykonuje tylko zaznaczone działania: dodanie osoby, oznaczenie zaproszeń jako
 * „z odpowiedzią” i zapis wiadomości na osi czasu certyfikatów, osoby lub płatnika.
 */
final class EmlImportService
{
    private const EXCERPT = 500;

    private readonly EventLogger $events;
    private readonly PayerService $payers;
    private readonly BeneficiaryService $beneficiaries;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null)
    {
        $this->events = $events ?? new EventLogger($db);
        $this->payers = new PayerService($db, $this->events);
        $this->beneficiaries = new BeneficiaryService($db, $this->events, $this->payers);
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(Actor $actor, string $filename, string $content): array
    {
        $actor->authorize('import.run');
        $message = EmlParser::parse($content);
        $sender = $message->from['email'];
        $haystack = ($message->subject ?? '') . "\n" . $message->text;

        $beneficiaries = $sender !== null ? $this->beneficiariesByEmail($actor, $sender) : [];
        $senderPayers = $sender !== null ? $this->payersByEmail($actor, $sender) : [];
        $phones = self::phones($message->text);
        $taxIds = self::taxIds($haystack);

        return [
            'file_name'    => $filename,
            'message'      => [
                'subject'     => $message->subject,
                'from'        => $message->from,
                'to'          => $message->to,
                'date'        => $message->date,
                'message_id'  => $message->messageId,
                'text'        => mb_substr($message->text, 0, 5000),
                'truncated'   => mb_strlen($message->text) > 5000,
                'attachments' => $this->attachments($message),
            ],
            'sender'       => [
                'beneficiaries' => $beneficiaries,
                'payers'        => $senderPayers,
                'is_account'    => $sender !== null && $this->isAccount($sender),
                'suggestion'    => $sender !== null && $beneficiaries === [] ? self::suggestion($message, $phones) : null,
            ],
            'certificates' => $this->certificatesInText($actor, $haystack),
            'payers'       => $this->payersByTaxIds($actor, $taxIds),
            'invitations'  => $sender !== null ? $this->openInvitations($actor, $sender) : [],
            'detected'     => ['phones' => $phones, 'tax_ids' => $taxIds],
        ];
    }

    /**
     * @param array{create_beneficiary?: mixed, responded?: mixed, note_certificates?: mixed, note_beneficiaries?: mixed, note_payers?: mixed} $actions
     * @return array{beneficiary: array{id: int, name: string}|null, responded: int, notes: int}
     */
    public function apply(Actor $actor, string $filename, string $content, array $actions): array
    {
        $actor->authorize('import.run');
        $message = EmlParser::parse($content);
        $payload = array_filter([
            'subject'   => $message->subject,
            'from'      => $message->from['email'],
            'from_name' => $message->from['name'],
            'sent_at'   => $message->date,
            'excerpt'   => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $message->text)), 0, self::EXCERPT),
            'file_name' => $filename,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return Transaction::run($this->db, function () use ($actor, $actions, $payload, $filename, $message): array {
            $result = ['beneficiary' => null, 'responded' => 0, 'notes' => 0];

            if (is_array($actions['create_beneficiary'] ?? null)) {
                $created = $this->beneficiaries->create($actor, $actions['create_beneficiary']);
                $result['beneficiary'] = ['id' => (int) $created['id'], 'name' => trim($created['first_name'] . ' ' . $created['last_name'])];
                $this->events->log('beneficiary', (int) $created['id'], 'email_received', $actor->id, [
                    'beneficiary_id' => (int) $created['id'],
                    'payer_id'       => $created['payer_id'],
                ], $payload);
                ++$result['notes'];
            }

            $invitations = new InvitationService($this->db, null, null, $this->events);
            foreach (self::ids($actions['responded'] ?? []) as $id) {
                $invitations->markResponded($actor, $id);
                ++$result['responded'];
            }

            foreach (self::ids($actions['note_certificates'] ?? []) as $id) {
                $certificate = $this->visibleCertificate($actor, $id);
                $this->events->log('certificate', $id, 'email_received', $actor->id, [
                    'certificate_id' => $id,
                    'beneficiary_id' => $certificate['beneficiary_id'] !== null ? (int) $certificate['beneficiary_id'] : null,
                    'payer_id'       => (int) $certificate['payer_id'],
                ], $payload);
                ++$result['notes'];
            }

            foreach (self::ids($actions['note_beneficiaries'] ?? []) as $id) {
                $person = $this->beneficiaries->findVisible($actor, $id, false);
                $this->events->log('beneficiary', $id, 'email_received', $actor->id, [
                    'beneficiary_id' => $id,
                    'payer_id'       => $person['payer_id'],
                ], $payload);
                ++$result['notes'];
            }

            foreach (self::ids($actions['note_payers'] ?? []) as $id) {
                $this->payers->findVisible($actor, $id, false);
                $this->events->log('payer', $id, 'email_received', $actor->id, ['payer_id' => $id], $payload);
                ++$result['notes'];
            }

            $this->events->log('system', null, 'eml_imported', $actor->id, [], array_filter([
                'file_name'      => $filename,
                'subject'        => $message->subject,
                'from'           => $message->from['email'],
                'beneficiary_id' => $result['beneficiary']['id'] ?? null,
                'responded'      => $result['responded'],
                'notes'          => $result['notes'],
            ], static fn (mixed $value): bool => $value !== null));

            return $result;
        });
    }

    /**
     * Telefony z treści (polskie numery komórkowe i stacjonarne, z +48 lub bez).
     *
     * @return list<string>
     */
    public static function phones(string $text): array
    {
        preg_match_all('/(?<![\d+])(?:\+48[\s-]?)?(?:\d{3}[\s-]?\d{3}[\s-]?\d{3}|\(?\d{2}\)?[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2})(?![\d])/', $text, $matches);

        $phones = [];
        foreach ($matches[0] as $match) {
            $phone = trim((string) preg_replace('/\s+/', ' ', $match));
            $digits = (string) preg_replace('/\D/', '', $phone);
            if (strlen($digits) >= 9 && !in_array($phone, $phones, true) && !Validator::isValidPolishNip(substr($digits, -10))) {
                $phones[] = $phone;
            }
        }

        return array_slice($phones, 0, 5);
    }

    /**
     * Poprawne numery NIP z treści (z kreskami, spacjami, prefiksem PL albo bez).
     *
     * @return list<string>
     */
    public static function taxIds(string $text): array
    {
        preg_match_all('/(?<![\dA-Za-z])(?:PL\s?)?(\d{3}[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}|\d{3}[\s-]?\d{2}[\s-]?\d{2}[\s-]?\d{3})(?!\d)/', $text, $matches);

        $taxIds = [];
        foreach ($matches[1] as $match) {
            $digits = (string) preg_replace('/\D/', '', $match);
            if (strlen($digits) === 10 && Validator::isValidPolishNip($digits) && !in_array($digits, $taxIds, true)) {
                $taxIds[] = $digits;
            }
        }

        return $taxIds;
    }

    /**
     * @param list<string> $phones
     * @return array{first_name: string, last_name: string, email: string, phone: ?string}
     */
    private static function suggestion(EmlMessage $message, array $phones): array
    {
        $name = trim((string) ($message->from['name'] ?? ''));
        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));
        } else {
            $parts = preg_split('/\s+/u', $name) ?: [];
            $first = (string) array_shift($parts);
            $last = implode(' ', $parts);
        }

        return [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string) $message->from['email'],
            'phone'      => $phones[0] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(EmlMessage $message): array
    {
        $list = [];
        foreach ($message->attachments as $index => $attachment) {
            $dataset = null;
            $rows = null;
            $importable = ImportService::isTableFile($attachment['filename']);
            if ($importable) {
                try {
                    $table = ImportService::parseTable($attachment['filename'], $attachment['content']);
                    $rows = count($table->rows);
                    $dataset = self::guessDataset($table->headers);
                } catch (Throwable) {
                    $importable = false;
                }
            }

            $list[] = [
                'index'        => $index,
                'filename'     => $attachment['filename'],
                'content_type' => $attachment['content_type'],
                'size'         => $attachment['size'],
                'importable'   => $importable,
                'dataset'      => $dataset,
                'rows'         => $rows,
            ];
        }

        return $list;
    }

    /**
     * Zbiór, do którego pasuje najwięcej kolumn pliku (bez brakujących kolumn wymaganych).
     *
     * @param list<string> $headers
     */
    public static function guessDataset(array $headers): ?string
    {
        $best = null;
        $bestScore = 0;
        foreach (Columns::IMPORTABLE as $dataset) {
            $columns = Columns::mapHeaders($dataset, $headers);
            $score = count($columns['mapping']);
            if ($columns['missing'] === [] && $score > $bestScore) {
                $best = $dataset;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function beneficiariesByEmail(Actor $actor, string $email): array
    {
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
        $stmt = $this->db->prepare(
            "SELECT b.id, b.first_name, b.last_name, b.email, b.payer_id, p.company_name AS payer_name
             FROM beneficiaries b LEFT JOIN payers p ON p.id = b.payer_id
             WHERE b.email = :email AND b.archived_at IS NULL AND {$visibility}
             ORDER BY b.last_name, b.first_name"
        );
        $stmt->execute(['email' => $email] + $params);

        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'name'       => trim($row['first_name'] . ' ' . $row['last_name']),
            'email'      => $row['email'],
            'payer_id'   => $row['payer_id'] !== null ? (int) $row['payer_id'] : null,
            'payer_name' => $row['payer_name'],
        ], $stmt->fetchAll());
    }

    /**
     * @return list<array{id: int, company_name: string, tax_id: ?string}>
     */
    private function payersByEmail(Actor $actor, string $email): array
    {
        [$visibility, $params] = Visibility::payers($actor, 'p');
        $stmt = $this->db->prepare(
            "SELECT p.id, p.company_name, p.tax_id FROM payers p
             WHERE p.email = :email AND p.archived_at IS NULL AND {$visibility} ORDER BY p.company_name"
        );
        $stmt->execute(['email' => $email] + $params);

        return array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'company_name' => (string) $row['company_name'],
            'tax_id'       => $row['tax_id'],
        ], $stmt->fetchAll());
    }

    /**
     * @param list<string> $taxIds
     * @return list<array{id: int, company_name: string, tax_id: ?string}>
     */
    private function payersByTaxIds(Actor $actor, array $taxIds): array
    {
        $payers = [];
        foreach ($taxIds as $taxId) {
            [$visibility, $params] = Visibility::payers($actor, 'p');
            $stmt = $this->db->prepare(
                "SELECT p.id, p.company_name, p.tax_id FROM payers p WHERE p.tax_id = :tax_id AND p.archived_at IS NULL AND {$visibility} LIMIT 1"
            );
            $stmt->execute(['tax_id' => $taxId] + $params);
            $row = $stmt->fetch();
            if ($row !== false) {
                $payers[] = ['id' => (int) $row['id'], 'company_name' => (string) $row['company_name'], 'tax_id' => $row['tax_id']];
            }
        }

        return $payers;
    }

    /**
     * Certyfikaty, których numer seryjny (co najmniej 6 znaków) występuje w temacie lub treści.
     *
     * @return list<array<string, mixed>>
     */
    private function certificatesInText(Actor $actor, string $haystack): array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, c.serial_number, c.expiry_date, c.beneficiary_id, c.payer_id, p.company_name AS payer_name,
                    CONCAT_WS(' ', b.first_name, b.last_name) AS beneficiary_name
             FROM certificates c
             INNER JOIN payers p ON p.id = c.payer_id
             LEFT JOIN beneficiaries b ON b.id = c.beneficiary_id
             WHERE c.archived_at IS NULL AND c.serial_number IS NOT NULL
               AND CHAR_LENGTH(c.serial_number) >= 6 AND {$visibility}
             ORDER BY c.expiry_date"
        );
        $stmt->execute($params);

        $found = [];
        $normalizedHaystack = mb_strtolower((string) preg_replace('/[\s:-]+/', '', $haystack));
        foreach ($stmt->fetchAll() as $row) {
            $serial = mb_strtolower((string) preg_replace('/[\s:-]+/', '', (string) $row['serial_number']));
            if ($serial !== '' && str_contains($normalizedHaystack, $serial)) {
                $found[] = [
                    'id'               => (int) $row['id'],
                    'name'             => (string) $row['name'],
                    'serial_number'    => (string) $row['serial_number'],
                    'expiry_date'      => (string) $row['expiry_date'],
                    'beneficiary_id'   => $row['beneficiary_id'] !== null ? (int) $row['beneficiary_id'] : null,
                    'beneficiary_name' => $row['beneficiary_name'] !== '' ? $row['beneficiary_name'] : null,
                    'payer_id'         => (int) $row['payer_id'],
                    'payer_name'       => (string) $row['payer_name'],
                ];
            }
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openInvitations(Actor $actor, string $email): array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(
            "SELECT i.id, i.certificate_id, i.status, i.sent_at, i.reminder_count, i.subject, c.name AS certificate_name
             FROM invitations i INNER JOIN certificates c ON c.id = i.certificate_id
             WHERE i.recipient_email = :email AND i.status IN ('sent', 'failed') AND {$visibility}
             ORDER BY COALESCE(i.sent_at, i.created_at) DESC"
        );
        $stmt->execute(['email' => $email] + $params);

        return array_map(static fn (array $row): array => [
            'id'               => (int) $row['id'],
            'certificate_id'   => (int) $row['certificate_id'],
            'certificate_name' => (string) $row['certificate_name'],
            'status'           => (string) $row['status'],
            'sent_at'          => $row['sent_at'],
            'reminder_count'   => (int) $row['reminder_count'],
            'subject'          => (string) $row['subject'],
        ], $stmt->fetchAll());
    }

    private function isAccount(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleCertificate(Actor $actor, int $id): array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(
            "SELECT c.id, c.beneficiary_id, c.payer_id FROM certificates c
             WHERE c.id = :id AND c.archived_at IS NULL AND {$visibility} LIMIT 1"
        );
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ServiceException::notFound(\__('certificate.error.not_found'));
        }

        return $row;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            if ((is_int($item) || (is_string($item) && ctype_digit($item))) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }
}

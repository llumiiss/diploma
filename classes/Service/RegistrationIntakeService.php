<?php

declare(strict_types=1);

namespace App\Service;

use App\CertificateHelper;
use App\Exchange\EmlMessage;
use App\Exchange\EmlParser;
use App\Exchange\Normalizer;
use App\Intake\CompanyRecord;
use App\Intake\CompanyRegistry;
use App\Intake\ImapClient;
use App\Intake\ImapException;
use App\Intake\IntakeConfig;
use App\Intake\RegistrationExtractor;
use App\Intake\RegistryException;
use DateTimeImmutable;
use PDO;

/**
 * Przyjmowanie wniosków o certyfikat z poczty (Etap 10): wiadomość → odczyt danych → firma z Białej Listy
 * po NIP-ie → powiązanie z rekordami z ewidencji → gotowy do sprawdzenia formularz (registration_drafts).
 *
 * Usługa nie zakłada żadnych rekordów w ewidencji — robi to dopiero operator przyciskiem „Zatwierdź”
 * (App\Service\RegistrationService). Wiadomość trafia tu czterema drogami: z pliku przesłanego w panelu,
 * ze skrzynki IMAP (cron), z webhooka i z potoku serwera pocztowego; wszystkie przechodzą przez ingest().
 */
final class RegistrationIntakeService
{
    public const SOURCES = ['upload', 'imap', 'webhook', 'cli'];

    private readonly CompanyRegistry $registry;
    private readonly NotificationService $notifications;
    private readonly EventLogger $events;
    private readonly string $storageDir;

    public function __construct(
        private readonly PDO $db,
        ?CompanyRegistry $registry = null,
        ?string $storageDir = null,
        ?NotificationService $notifications = null,
        ?EventLogger $events = null,
    ) {
        $this->registry = $registry ?? IntakeConfig::registry();
        $this->notifications = $notifications ?? new NotificationService($db);
        $this->events = $events ?? new EventLogger($db);
        $this->storageDir = $storageDir ?? dirname(__DIR__, 2) . '/storage/intake';
    }

    /**
     * Zamienia wiadomość na wniosek do sprawdzenia.
     *
     * Ręczne wczytanie pliku (upload) tworzy wniosek zawsze — operator wie, co wczytuje. Wiadomości z automatycznych
     * dróg, które nie wyglądają na wniosek (brak NIP-u i etykiet), są pomijane, żeby skrzynka ze spamem nie zapełniła kolejki.
     *
     * @return array{status: string, draft_id: int|null, reason?: string}
     */
    public function ingest(string $raw, string $source = 'upload', ?Actor $uploader = null): array
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw ServiceException::badRequest(\__('api.error.unknown_action'));
        }
        $uploader?->authorize('registrations.intake');

        $message = EmlParser::parse($raw);
        $messageId = $message->messageId !== null && trim($message->messageId) !== '' ? mb_substr(trim($message->messageId), 0, 255) : null;
        $hash = hash('sha256', (string) str_replace("\r\n", "\n", $raw));

        $duplicate = $this->findDuplicate($messageId, $hash);
        if ($duplicate !== null) {
            return ['status' => 'duplicate', 'draft_id' => $duplicate];
        }

        $extracted = RegistrationExtractor::extract($message);
        if ($source !== 'upload' && !$extracted['looks_like_registration']) {
            return ['status' => 'skipped', 'draft_id' => null, 'reason' => 'not_registration'];
        }

        $today = $this->today();
        $nip = $extracted['company']['nip'];
        $lookup = $nip !== null ? $this->lookupCompany($nip, $today) : null;
        $record = $lookup !== null && $lookup['status'] === 'found' ? CompanyRecord::fromArray($lookup['record']) : null;

        $payer = $nip !== null ? $this->findPayerByTaxId($nip) : null;
        $beneficiary = $this->findBeneficiary($extracted['person']['email'], $payer !== null ? (int) $payer['id'] : null);

        $assumed = [];
        $form = self::buildForm($extracted, $record, $payer, $beneficiary['id'] ?? null, $today, $assumed);
        $extracted['assumed'] = $assumed;
        $warnings = self::buildWarnings($extracted, $lookup, $payer, $beneficiary);

        $draftId = Transaction::run($this->db, function () use ($raw, $source, $uploader, $message, $messageId, $hash, $extracted, $form, $lookup, $warnings, $payer, $beneficiary): int {
            $stmt = $this->db->prepare(
                'INSERT INTO registration_drafts (status, source, message_id, content_hash, sender_email, sender_name, subject,
                    received_at, body_text, raw_file, extracted, form, company_lookup, warnings, matched_payer_id,
                    matched_beneficiary_id, created_by_user_id, created_at)
                 VALUES (:status, :source, :message_id, :content_hash, :sender_email, :sender_name, :subject,
                    :received_at, :body_text, :raw_file, :extracted, :form, :company_lookup, :warnings, :matched_payer_id,
                    :matched_beneficiary_id, :created_by, NOW())'
            );
            $stmt->execute([
                'status'                 => 'pending',
                'source'                 => $source,
                'message_id'             => $messageId,
                'content_hash'           => $hash,
                'sender_email'           => $message->from['email'],
                'sender_name'            => $message->from['name'] !== null ? mb_substr($message->from['name'], 0, 255) : null,
                'subject'                => $message->subject !== null ? mb_substr($message->subject, 0, 255) : null,
                'received_at'            => $message->date ?? date('Y-m-d H:i:s'),
                'body_text'              => mb_substr($message->text, 0, 20000),
                'raw_file'               => $this->storeRaw($raw),
                'extracted'              => self::json($extracted),
                'form'                   => self::json($form),
                'company_lookup'         => $lookup !== null ? self::json($lookup) : null,
                'warnings'               => self::json($warnings),
                'matched_payer_id'       => $payer !== null ? (int) $payer['id'] : null,
                'matched_beneficiary_id' => $beneficiary['id'] ?? null,
                'created_by'             => $uploader !== null && $uploader->id > 0 ? $uploader->id : null,
            ]);
            $id = (int) $this->db->lastInsertId();

            $this->events->log('system', null, 'registration_received', $uploader !== null && $uploader->id > 0 ? $uploader->id : null, [
                'payer_id' => $payer !== null ? (int) $payer['id'] : null,
            ], array_filter([
                'draft_id' => $id,
                'source'   => $source,
                'subject'  => $message->subject,
                'from'     => $message->from['email'],
            ], static fn (mixed $value): bool => $value !== null));

            return $id;
        });

        $this->announce($draftId, $form, $message);

        return ['status' => 'created', 'draft_id' => $draftId];
    }

    /**
     * Pobiera nieprzeczytane wiadomości ze skrzynki i zamienia je na wnioski.
     *
     * Wiadomość po obróbce (utworzono, duplikat, pominięto albo nieczytelna) jest oznaczana jako przeczytana
     * albo przenoszona do skrzynki „przetworzone”, więc nie wraca w kolejnym przebiegu. Błąd połączenia przerywa
     * przebieg — wiadomości zostają nieprzeczytane i zostaną pobrane następnym razem.
     *
     * @return array{fetched: int, created: int, duplicates: int, skipped: int, failed: int, errors: list<string>}
     * @throws ImapException
     */
    public function fetchMailbox(ImapClient $client, string $mailbox = 'INBOX', int $max = 20, ?string $processedMailbox = null): array
    {
        $summary = ['fetched' => 0, 'created' => 0, 'duplicates' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        $client->select($mailbox);
        foreach ($client->searchUnseen($max) as $uid) {
            ++$summary['fetched'];

            $size = $client->messageSize($uid);
            if ($size !== null && $size > EmlParser::MAX_BYTES) {
                ++$summary['skipped'];
                $summary['errors'][] = 'UID ' . $uid . ': ' . \__('exchange.error.file_too_large', ['size' => '10 MB']);
                $this->done($client, $uid, $processedMailbox);
                continue;
            }

            try {
                $result = $this->ingest($client->fetchRaw($uid), 'imap');
                match ($result['status']) {
                    'created'   => ++$summary['created'],
                    'duplicate' => ++$summary['duplicates'],
                    default     => ++$summary['skipped'],
                };
            } catch (ServiceException $e) {
                // Nieczytelna wiadomość nie może blokować kolejki ani wracać co przebieg.
                ++$summary['failed'];
                $summary['errors'][] = 'UID ' . $uid . ': ' . ($e->errors['file'] ?? $e->getMessage());
            }

            $this->done($client, $uid, $processedMailbox);
        }

        return $summary;
    }

    /**
     * Odpytuje rejestr o firmę. Awaria rejestru nie przerywa przyjęcia wniosku — jest zapisana w wyniku.
     *
     * @return array{status: string, checked_at: string, record?: array<string, string|null>, message?: string}
     */
    public function lookupCompany(string $nip, ?DateTimeImmutable $today = null): array
    {
        $checkedAt = date('Y-m-d H:i:s');
        try {
            $record = $this->registry->lookupNip($nip, $today);
        } catch (RegistryException $e) {
            return ['status' => 'error', 'checked_at' => $checkedAt, 'message' => $e->getMessage()];
        }

        return $record === null
            ? ['status' => 'not_found', 'checked_at' => $checkedAt]
            : ['status' => 'found', 'checked_at' => $checkedAt, 'record' => $record->toArray()];
    }

    /**
     * Firma z ewidencji po NIP-ie (także z archiwum — NIP jest unikalny w całej bazie).
     *
     * @return array<string, mixed>|null
     */
    public function findPayerByTaxId(string $taxId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, company_name, tax_id, archived_at FROM payers WHERE tax_id = :tax_id LIMIT 1');
        $stmt->execute(['tax_id' => $taxId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Użytkownik certyfikatu po adresie e-mail. Dopasowanie obowiązuje tylko wtedy, gdy osoba należy do tej samej firmy —
     * ta sama skrzynka u pracownika innej firmy to sygnał do sprawdzenia, a nie powód, żeby podpiąć cudzą osobę.
     *
     * @return array{id: int|null, other_company: bool}|null
     */
    public function findBeneficiary(?string $email, ?int $payerId): ?array
    {
        if ($email === null || $email === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, payer_id FROM beneficiaries WHERE LOWER(email) = LOWER(:email) AND archived_at IS NULL ORDER BY id'
        );
        $stmt->execute(['email' => $email]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            if ($payerId !== null && (int) $row['payer_id'] === $payerId) {
                return ['id' => (int) $row['id'], 'other_company' => false];
            }
        }

        return ['id' => null, 'other_company' => true];
    }

    // ---------------------------------------------------------------- formularz

    /**
     * Formularz wniosku: dane z wiadomości uzupełnione danymi z rejestru i z ewidencji, z rozsądnymi wartościami
     * domyślnymi tam, gdzie wiadomość milczy (lista takich założeń trafia do $assumed i do ostrzeżeń).
     *
     * @param array<string, mixed>      $extracted wynik RegistrationExtractor::extract()
     * @param array<string, mixed>|null $payer     firma z ewidencji (wiersz tabeli payers)
     * @param list<string>              $assumed
     * @return array{company: array<string, mixed>, person: array<string, mixed>, certificate: array<string, mixed>}
     */
    public static function buildForm(array $extracted, ?CompanyRecord $record, ?array $payer, ?int $beneficiaryId, DateTimeImmutable $today, array &$assumed): array
    {
        $person = $extracted['person'];
        $company = $extracted['company'];
        $certificate = $extracted['certificate'];

        $personName = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));

        if ($payer !== null) {
            $companyForm = [
                'mode'           => 'existing',
                'payer_id'       => (int) $payer['id'],
                'company_name'   => (string) $payer['company_name'],
                'tax_id'         => $payer['tax_id'] !== null ? (string) $payer['tax_id'] : ($company['nip'] ?? ''),
                'contact_person' => '',
                'email'          => '',
                'phone'          => '',
                'address_line'   => '',
                'postal_code'    => '',
                'city'           => '',
            ];
        } else {
            $companyForm = [
                'mode'           => 'new',
                'payer_id'       => null,
                'company_name'   => $record->name ?? ($company['name'] ?? ''),
                'tax_id'         => $company['nip'] ?? '',
                'contact_person' => $personName !== '' ? $personName : (string) ($person['email'] ?? ''),
                'email'          => $company['email'] ?? '',
                'phone'          => $company['phone'] ?? '',
                'address_line'   => $record->addressLine ?? ($company['address_line'] ?? ''),
                'postal_code'    => $record->postalCode ?? ($company['postal_code'] ?? ''),
                'city'           => $record->city ?? ($company['city'] ?? ''),
            ];
        }

        $personForm = [
            'mode'           => $beneficiaryId !== null ? 'existing' : 'new',
            'beneficiary_id' => $beneficiaryId,
            'first_name'     => $person['first_name'] ?? '',
            'last_name'      => $person['last_name'] ?? '',
            'email'          => $person['email'] ?? '',
            'phone'          => $person['phone'] ?? '',
        ];

        $validFrom = $certificate['valid_from'];
        if ($validFrom === null) {
            $validFrom = $today->format('Y-m-d');
            $assumed[] = 'valid_from_assumed';
        }
        $months = $certificate['validity_months'];
        $expiry = $certificate['expiry_date'];
        if ($expiry === null) {
            if ($months === null) {
                $months = 12;
                $assumed[] = 'expiry_assumed';
            }
            $expiry = (new DateTimeImmutable($validFrom))->modify('+' . $months . ' months')->format('Y-m-d');
        } elseif ($months === null) {
            $span = (new DateTimeImmutable($validFrom))->diff(new DateTimeImmutable($expiry));
            $months = $span->invert === 1 ? 12 : $span->y * 12 + $span->m;
        }

        $type = (string) $certificate['certificate_type'];
        $subject = $type === 'QUALIFIED_SEAL' && $companyForm['company_name'] !== '' ? (string) $companyForm['company_name'] : ($personName !== '' ? $personName : (string) ($person['email'] ?? ''));
        $name = $certificate['name'] ?? trim(CertificateHelper::typeLabel($type) . ($subject !== '' ? ' — ' . $subject : ''));

        $certificateForm = [
            'name'              => $name,
            'certificate_type'  => $type,
            'serial_number'     => $certificate['serial_number'] ?? '',
            'issuer'            => $certificate['issuer'] ?? '',
            'valid_from'        => $validFrom,
            'expiry_date'       => $expiry,
            'renewal_lead_days' => '',
            'discount_percent'  => $certificate['discount_percent'] !== null && $certificate['discount_percent'] > 0 ? '-' . rtrim(rtrim(number_format((float) $certificate['discount_percent'], 2, '.', ''), '0'), '.') : '0',
            'billing_cycle'     => $months > 12 ? 'multi_year' : 'annual',
            'payment_status'    => 'due_soon',
            'status'            => ($certificate['serial_number'] ?? '') !== '' ? 'active' : 'pending',
            'notes'             => $certificate['notes'] ?? '',
        ];

        return ['company' => $companyForm, 'person' => $personForm, 'certificate' => $certificateForm];
    }

    /**
     * Wątpliwości dla operatora, wyliczane z aktualnego stanu (odczyt wiadomości, rejestr, ewidencja) — dzięki temu
     * po ponownym sprawdzeniu firmy w rejestrze lista jest spójna z formularzem.
     *
     * @param array<string, mixed>                          $extracted
     * @param array<string, mixed>|null                     $lookup
     * @param array<string, mixed>|null                     $payer
     * @param array{id: int|null, other_company: bool}|null $beneficiary
     * @return list<array{code: string, level: string, field?: string, params?: array<string, string>}>
     */
    public static function buildWarnings(array $extracted, ?array $lookup, ?array $payer, ?array $beneficiary): array
    {
        $warnings = [];
        foreach ($extracted['warnings'] as $warning) {
            // Brak NIP-u z wiadomości przestaje być problemem, gdy NIP znaleziono później (np. ręcznie podany).
            $warnings[] = $warning + ['level' => in_array($warning['code'], ['nip_missing', 'person_missing'], true) ? 'error' : 'warning'];
        }

        foreach ($extracted['assumed'] ?? [] as $code) {
            // Dzisiejsza data początku to naturalne domyślne założenie (informacja), brak terminu wygaśnięcia wymaga uwagi.
            $warnings[] = [
                'code'  => (string) $code,
                'level' => $code === 'expiry_assumed' ? 'warning' : 'info',
                'field' => $code === 'expiry_assumed' ? 'certificate.expiry_date' : 'certificate.valid_from',
            ];
        }

        if ($lookup !== null) {
            if ($lookup['status'] === 'not_found') {
                $warnings[] = ['code' => 'registry_not_found', 'level' => 'warning', 'field' => 'company'];
            } elseif ($lookup['status'] === 'error') {
                $warnings[] = ['code' => 'registry_error', 'level' => 'warning', 'field' => 'company', 'params' => ['message' => (string) ($lookup['message'] ?? '')]];
            } elseif ($lookup['status'] === 'found') {
                $record = CompanyRecord::fromArray($lookup['record']);
                if (!$record->isActiveVatPayer()) {
                    $warnings[] = ['code' => 'vat_inactive', 'level' => 'warning', 'field' => 'company', 'params' => ['status' => (string) ($record->statusVat ?? '—')]];
                }
                if ($payer !== null && self::namesDiffer((string) $payer['company_name'], $record->officialName)) {
                    $warnings[] = ['code' => 'company_name_differs', 'level' => 'info', 'field' => 'company', 'params' => ['database' => (string) $payer['company_name'], 'registry' => $record->name]];
                }
            }
        }

        if ($payer !== null) {
            $warnings[] = ['code' => $payer['archived_at'] !== null ? 'company_archived' : 'company_exists', 'level' => $payer['archived_at'] !== null ? 'error' : 'info', 'field' => 'company', 'params' => ['name' => (string) $payer['company_name']]];
        }

        if ($beneficiary !== null) {
            if ($beneficiary['other_company']) {
                $warnings[] = ['code' => 'person_other_company', 'level' => 'warning', 'field' => 'person'];
            } else {
                $warnings[] = ['code' => 'person_exists', 'level' => 'info', 'field' => 'person'];
            }
        }

        return $warnings;
    }

    // ------------------------------------------------------------------ szczegóły

    /**
     * Nazwy różnią się, gdy żadna nie zaczyna się od początku drugiej (skrót formy prawnej na końcu nie liczy się).
     */
    private static function namesDiffer(string $database, string $registry): bool
    {
        $a = Normalizer::key($database);
        $b = Normalizer::key($registry);

        return !(str_starts_with($a, substr($b, 0, 6)) || str_starts_with($b, substr($a, 0, 6)));
    }

    private function findDuplicate(?string $messageId, string $hash): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM registration_drafts WHERE content_hash = :hash OR (:message_id IS NOT NULL AND message_id = :message_id2) ORDER BY id LIMIT 1'
        );
        $stmt->execute(['hash' => $hash, 'message_id' => $messageId, 'message_id2' => $messageId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Oryginał wiadomości w storage/intake (poza zasięgiem przeglądarki) — pozwala odtworzyć wniosek po poprawie
     * ekstraktora. Brak zapisu nie blokuje przyjęcia wniosku.
     */
    private function storeRaw(string $raw): ?string
    {
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
            error_log('[CertiSub] Nie można utworzyć katalogu ' . $this->storageDir);

            return null;
        }

        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.eml';
        if (@file_put_contents($this->storageDir . '/' . $name, $raw) === false) {
            error_log('[CertiSub] Nie można zapisać wiadomości w ' . $this->storageDir);

            return null;
        }

        return $name;
    }

    /**
     * Komunikat systemowy dla osób, które mogą sprawdzać wnioski.
     *
     * @param array<string, mixed> $form
     */
    private function announce(int $draftId, array $form, EmlMessage $message): void
    {
        $person = trim($form['person']['first_name'] . ' ' . $form['person']['last_name']);
        $subject = \__('registration.notification.subject', ['name' => $person !== '' ? $person : (string) ($message->from['email'] ?? '—')]);
        $body = \__('registration.notification.body', [
            'subject' => (string) ($message->subject ?? '—'),
            'company' => (string) ($form['company']['company_name'] !== '' ? $form['company']['company_name'] : '—'),
            'nip'     => (string) ($form['company']['tax_id'] !== '' ? $form['company']['tax_id'] : '—'),
        ]);

        $this->notifications->notify($this->notifications->usersWithPermission('registrations.review'), $subject, $body, 'registration', $draftId);
    }

    private function done(ImapClient $client, int $uid, ?string $processedMailbox): void
    {
        if ($processedMailbox !== null && $processedMailbox !== '') {
            $client->moveTo($uid, $processedMailbox);

            return;
        }
        $client->markSeen($uid);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->db->query('SELECT CURDATE()')->fetchColumn());
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\CertificateHelper;
use App\Exchange\Columns;
use App\Exchange\CsvReader;
use App\Exchange\EmlParser;
use App\Exchange\Normalizer;
use App\Exchange\ParsedTable;
use App\Exchange\XmlRecordReader;
use PDO;
use PDOException;
use Throwable;

/**
 * Import płatników, użytkowników certyfikatów i certyfikatów z plików CSV i XML (F10),
 * także z załącznika wiadomości EML.
 *
 * Podgląd i zapis przechodzą tę samą ścieżkę: każdy wiersz jest zapisywany przez usługę ewidencji
 * (walidacja, unikalność, uprawnienia, historia), a podgląd wycofuje na końcu całą transakcję.
 * Dzięki temu podgląd pokazuje dokładnie to, co zrobi import. Wiersz z błędem wycofuje tylko
 * swój punkt zapisu (SAVEPOINT) i nie przerywa pozostałych.
 *
 * Istniejące rekordy rozpoznaje klucz naturalny: płatnik — NIP albo nazwa, osoba — e-mail albo
 * imię i nazwisko (z płatnikiem), certyfikat — wystawca i numer seryjny albo nazwa, data wygaśnięcia
 * i płatnik. Tryb „skip” je pomija, „update” uzupełnia kolumnami obecnymi w pliku.
 */
final class ImportService
{
    public const MAX_BYTES = 5_000_000;
    public const MODES = ['skip', 'update'];

    private const CERTIFICATE_INPUT = [
        'name', 'certificate_type', 'serial_number', 'issuer', 'valid_from', 'expiry_date', 'renewal_lead_days', 'status',
        'annual_cost', 'billing_cycle', 'currency', 'payment_status', 'last_payment_date', 'auto_renew', 'notes',
    ];

    /** Nazwy okresu rozliczeń spotykane w arkuszach. */
    private const BILLING_SYNONYMS = [
        'roczny' => 'annual', 'roczna' => 'annual', 'rok' => 'annual', 'yearly' => 'annual',
        'miesięczny' => 'monthly', 'miesięczna' => 'monthly', 'miesiąc' => 'monthly',
        'wieloletni' => 'multi_year', 'wieloletnia' => 'multi_year', 'multiyear' => 'multi_year',
    ];

    private readonly EventLogger $events;
    private readonly PayerService $payers;
    private readonly BeneficiaryService $beneficiaries;
    private readonly CertificateService $certificates;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null)
    {
        $this->events = $events ?? new EventLogger($db);
        $this->payers = new PayerService($db, $this->events);
        $this->beneficiaries = new BeneficiaryService($db, $this->events, $this->payers);
        $this->certificates = new CertificateService($db, $this->events, $this->payers, $this->beneficiaries);
    }

    /**
     * @param array{mode?: string, attachment?: int|null} $options
     * @return array<string, mixed>
     */
    public function preview(Actor $actor, string $dataset, string $filename, string $content, array $options = []): array
    {
        return $this->process($actor, $dataset, $filename, $content, $options, false);
    }

    /**
     * @param array{mode?: string, attachment?: int|null} $options
     * @return array<string, mixed>
     */
    public function commit(Actor $actor, string $dataset, string $filename, string $content, array $options = []): array
    {
        return $this->process($actor, $dataset, $filename, $content, $options, true);
    }

    /**
     * Tabela z pliku CSV/XML albo z załącznika CSV/XML wiadomości EML.
     *
     * @return array{0: ParsedTable, 1: string}
     */
    public static function readTable(string $filename, string $content, ?int $attachment = null): array
    {
        if (strlen($content) > self::MAX_BYTES && !str_ends_with(strtolower($filename), '.eml')) {
            throw ServiceException::validation(['file' => \__('exchange.error.file_too_large', ['size' => '5 MB'])]);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'eml') {
            $message = EmlParser::parse($content);
            $file = $attachment !== null ? ($message->attachments[$attachment] ?? null) : null;
            if ($file === null || !self::isTableFile($file['filename'])) {
                throw ServiceException::validation(['file' => \__('exchange.error.eml_attachment')]);
            }

            return [self::parseTable($file['filename'], $file['content']), $filename . ' → ' . $file['filename']];
        }

        return [self::parseTable($filename, $content), $filename];
    }

    public static function isTableFile(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['csv', 'txt', 'xml'], true);
    }

    public static function parseTable(string $filename, string $content): ParsedTable
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'csv', 'txt' => CsvReader::parse($content),
            'xml'        => XmlRecordReader::parse($content),
            default      => throw ServiceException::validation(['file' => \__('exchange.error.unsupported_file')]),
        };
    }

    /**
     * @param array{mode?: string, attachment?: int|null} $options
     * @return array<string, mixed>
     */
    private function process(Actor $actor, string $dataset, string $filename, string $content, array $options, bool $commit): array
    {
        $actor->authorize('import.run');
        if (!Columns::isImportable($dataset)) {
            throw ServiceException::badRequest(\__('exchange.error.unknown_dataset'));
        }
        $mode = in_array($options['mode'] ?? 'skip', self::MODES, true) ? (string) ($options['mode'] ?? 'skip') : 'skip';

        [$table, $source] = self::readTable($filename, $content, $options['attachment'] ?? null);
        $columns = Columns::mapHeaders($dataset, $table->headers);

        $result = [
            'dataset'   => $dataset,
            'file_name' => $source,
            'format'    => $table->format,
            'encoding'  => $table->encoding,
            'delimiter' => $table->delimiter === "\t" ? 'TAB' : $table->delimiter,
            'mode'      => $mode,
            'columns'   => [
                'mapped'  => array_map(static fn (string $header, string $field): array => [
                    'header' => $header,
                    'field'  => $field,
                    'label'  => \__(Columns::labelKey($dataset, $field)),
                ], array_keys($columns['mapping']), array_values($columns['mapping'])),
                'ignored' => $columns['ignored'],
                'missing' => array_map(static fn (string $field): array => [
                    'field' => $field,
                    'label' => \__(Columns::labelKey($dataset, $field)),
                ], $columns['missing']),
            ],
            'rows'      => [],
            'totals'    => ['rows' => 0, 'create' => 0, 'update' => 0, 'unchanged' => 0, 'skip' => 0, 'error' => 0],
            'committed' => false,
        ];

        if ($columns['missing'] !== []) {
            $message = \__('exchange.error.missing_columns', [
                'columns' => implode(', ', array_column($result['columns']['missing'], 'label')),
            ]);
            if ($commit) {
                throw ServiceException::validation(['file' => $message]);
            }
            $result['fatal'] = $message;

            return $result;
        }

        $ownTransaction = !$this->db->inTransaction();
        $ownTransaction ? $this->db->beginTransaction() : $this->db->exec('SAVEPOINT import_file');

        try {
            $seen = [];
            foreach ($table->rows as $record) {
                $data = [];
                foreach ($columns['mapping'] as $header => $field) {
                    $data[$field] = trim($record['values'][$header] ?? '');
                }
                if (implode('', $data) === '') {
                    continue;
                }

                $row = $this->processRow($actor, $dataset, $data, $record['line'], $mode, $seen);
                if ($row['status'] === 'create' && !$commit) {
                    $row['record_id'] = null;
                }
                $result['rows'][] = $row;
                ++$result['totals']['rows'];
                ++$result['totals'][$row['status']];
            }

            if ($commit) {
                $this->events->log('system', null, 'data_imported', $actor->id, [], [
                    'dataset'   => $dataset,
                    'format'    => $table->format,
                    'file_name' => $source,
                    'mode'      => $mode,
                    'created'   => $result['totals']['create'],
                    'updated'   => $result['totals']['update'],
                    'skipped'   => $result['totals']['skip'] + $result['totals']['unchanged'],
                    'errors'    => $result['totals']['error'],
                ]);
                $ownTransaction ? $this->db->commit() : $this->db->exec('RELEASE SAVEPOINT import_file');
                $result['committed'] = true;
            } else {
                $ownTransaction ? $this->db->rollBack() : $this->db->exec('ROLLBACK TO SAVEPOINT import_file');
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int>    $seen klucz naturalny → linia pierwszego wystąpienia w pliku
     * @return array<string, mixed>
     */
    private function processRow(Actor $actor, string $dataset, array $data, int $line, string $mode, array &$seen): array
    {
        $row = [
            'line'      => $line,
            'label'     => self::rowLabel($dataset, $data),
            'status'    => 'error',
            'record_id' => null,
            'changes'   => [],
            'message'   => null,
            'errors'    => [],
        ];

        $this->db->exec('SAVEPOINT import_row');
        try {
            $outcome = match ($dataset) {
                'payers'        => $this->payerRow($actor, $data, $line, $mode, $seen),
                'beneficiaries' => $this->beneficiaryRow($actor, $data, $line, $mode, $seen),
                default         => $this->certificateRow($actor, $data, $line, $mode, $seen),
            };
            $this->db->exec('RELEASE SAVEPOINT import_row');

            return $outcome + $row;
        } catch (ServiceException $e) {
            $this->db->exec('ROLLBACK TO SAVEPOINT import_row');
            $row['message'] = $e->getMessage();
            $row['errors'] = self::errorList($dataset, $e->errors);
        } catch (PDOException $e) {
            $this->db->exec('ROLLBACK TO SAVEPOINT import_row');
            error_log('[CertiSub import] ' . $e->getMessage());
            $row['message'] = \__('api.error.server');
        }

        return $row;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int>    $seen
     * @return array<string, mixed>
     */
    private function payerRow(Actor $actor, array $data, int $line, string $mode, array &$seen): array
    {
        $input = array_intersect_key($data, array_flip(PayerService::FIELDS));
        $taxId = ($input['tax_id'] ?? '') !== '' ? Validator::normalizeTaxId($input['tax_id']) : null;
        $name = $input['company_name'] ?? '';
        self::assertFirstInFile($seen, $taxId !== null ? 'tax:' . $taxId : 'name:' . Normalizer::key($name), $line);

        if ($taxId !== null) {
            $existing = $this->fetchOne('SELECT * FROM payers WHERE tax_id = :tax_id LIMIT 1', ['tax_id' => $taxId]);
        } else {
            $existing = $this->fetchUnique(
                'SELECT * FROM payers p WHERE p.company_name = :name AND p.archived_at IS NULL AND ' . PayerService::notPersonalOnly('p') . ' LIMIT 2',
                ['name' => $name],
                'company_name',
                'exchange.error.payer_ambiguous'
            );
        }

        if ($existing === null) {
            return ['status' => 'create', 'record_id' => $this->payers->create($actor, $input)['id']];
        }

        return $this->existing($existing, $input, $mode, PayerService::FIELDS,
            fn (array $merged): array => $this->payers->validate($merged),
            fn (array $merged): mixed => $this->payers->update($actor, (int) $existing['id'], $merged));
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int>    $seen
     * @return array<string, mixed>
     */
    private function beneficiaryRow(Actor $actor, array $data, int $line, string $mode, array &$seen): array
    {
        $input = array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'phone', 'notes']));
        if (array_key_exists('payer_name', $data) || array_key_exists('payer_tax_id', $data)) {
            $input['payer_id'] = $this->resolvePayer($data['payer_tax_id'] ?? '', $data['payer_name'] ?? '');
        }

        $email = mb_strtolower($input['email'] ?? '');
        $nameKey = Normalizer::key(($input['first_name'] ?? '') . ' ' . ($input['last_name'] ?? ''));
        self::assertFirstInFile($seen, $email !== '' ? 'email:' . $email : 'name:' . $nameKey . ':' . ($input['payer_id'] ?? ''), $line);

        if ($email !== '') {
            $existing = $this->fetchUnique(
                'SELECT * FROM beneficiaries WHERE email = :email ORDER BY archived_at IS NOT NULL, id LIMIT 2',
                ['email' => $email],
                'email',
                'exchange.error.beneficiary_ambiguous',
                true
            );
        } else {
            $payerCondition = array_key_exists('payer_id', $input) ? ' AND payer_id <=> :payer_id' : '';
            $params = ['first_name' => $input['first_name'] ?? '', 'last_name' => $input['last_name'] ?? ''];
            if ($payerCondition !== '') {
                $params['payer_id'] = $input['payer_id'];
            }
            $existing = $this->fetchUnique(
                'SELECT * FROM beneficiaries WHERE first_name = :first_name AND last_name = :last_name AND archived_at IS NULL' . $payerCondition . ' LIMIT 2',
                $params,
                'last_name',
                'exchange.error.beneficiary_ambiguous'
            );
        }

        if ($existing === null) {
            return ['status' => 'create', 'record_id' => $this->beneficiaries->create($actor, $input)['id']];
        }

        return $this->existing($existing, $input, $mode, BeneficiaryService::FIELDS,
            fn (array $merged): array => $this->beneficiaries->validate($actor, $merged, self::castIds($existing, ['payer_id'])),
            fn (array $merged): mixed => $this->beneficiaries->update($actor, (int) $existing['id'], $merged));
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int>    $seen
     * @return array<string, mixed>
     */
    private function certificateRow(Actor $actor, array $data, int $line, string $mode, array &$seen): array
    {
        $input = array_intersect_key($data, array_flip(self::CERTIFICATE_INPUT));
        foreach (['valid_from', 'expiry_date', 'last_payment_date'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = Normalizer::date($input[$field]);
            }
        }
        if (isset($input['auto_renew'])) {
            $input['auto_renew'] = Normalizer::bool($input['auto_renew']);
        }
        if (isset($input['certificate_type'])) {
            $input['certificate_type'] = Normalizer::choice($input['certificate_type'], self::labelKeys(CertificateService::TYPES, static fn (string $code): string => CertificateHelper::typeKey($code)));
        }
        if (isset($input['status'])) {
            $input['status'] = Normalizer::choice($input['status'], self::labelKeys(CertificateService::STATUSES, static fn (string $code): string => 'status.' . $code));
        }
        if (isset($input['billing_cycle'])) {
            $input['billing_cycle'] = Normalizer::choice(
                $input['billing_cycle'],
                self::labelKeys(CertificateService::BILLING_CYCLES, static fn (string $code): string => 'billing.' . $code),
                self::BILLING_SYNONYMS
            );
        }
        if (isset($input['payment_status'])) {
            $input['payment_status'] = Normalizer::choice($input['payment_status'], self::labelKeys(
                CertificateService::PAYMENT_STATUSES,
                static fn (string $code): string => $code === 'not_applicable' ? 'payment.na' : 'payment.' . $code
            ));
        }
        if (isset($input['annual_cost']) && $input['annual_cost'] !== '') {
            $input['annual_cost'] = (string) preg_replace('/\s*(zł|zl|pln|eur|usd)\s*$/i', '', $input['annual_cost']);
        }

        if (($data['owner_email'] ?? '') !== '') {
            $input['user_id'] = $this->resolveOwner($data['owner_email']);
        }
        if (array_key_exists('beneficiary_email', $data) || array_key_exists('beneficiary_first_name', $data) || array_key_exists('beneficiary_last_name', $data)) {
            $input['beneficiary_id'] = $this->resolveBeneficiary($data['beneficiary_email'] ?? '', $data['beneficiary_first_name'] ?? '', $data['beneficiary_last_name'] ?? '');
        }
        if (array_key_exists('payer_name', $data) || array_key_exists('payer_tax_id', $data)) {
            $input['payer_id'] = $this->resolvePayer($data['payer_tax_id'] ?? '', $data['payer_name'] ?? '');
        }

        $issuer = $input['issuer'] ?? '';
        $serial = $input['serial_number'] ?? '';
        $payerId = $input['payer_id'] ?? $this->beneficiaryPayer($input['beneficiary_id'] ?? null);
        $key = $issuer !== '' && $serial !== ''
            ? 'serial:' . Normalizer::key($issuer) . ':' . mb_strtolower($serial)
            : 'name:' . Normalizer::key($input['name'] ?? '') . ':' . ($input['expiry_date'] ?? '') . ':' . ($payerId ?? '');
        self::assertFirstInFile($seen, $key, $line);

        if ($issuer !== '' && $serial !== '') {
            $existing = $this->fetchOne(
                "SELECT * FROM certificates WHERE issuer = :issuer AND serial_number = :serial AND scope = 'corporate' LIMIT 1",
                ['issuer' => $issuer, 'serial' => $serial]
            );
        } else {
            $existing = $payerId === null ? null : $this->fetchOne(
                "SELECT * FROM certificates WHERE name = :name AND expiry_date = :expiry AND payer_id = :payer_id
                   AND scope = 'corporate' AND archived_at IS NULL LIMIT 1",
                ['name' => $input['name'] ?? '', 'expiry' => $input['expiry_date'] ?? '', 'payer_id' => $payerId]
            );
        }

        if ($existing === null) {
            return ['status' => 'create', 'record_id' => $this->certificates->create($actor, $input)['id']];
        }

        return $this->existing($existing, $input, $mode, CertificateService::FIELDS,
            fn (array $merged): array => $this->certificates->validate($actor, $merged, self::castIds($existing, ['user_id', 'beneficiary_id', 'payer_id'])),
            fn (array $merged): mixed => $this->certificates->update($actor, (int) $existing['id'], $merged));
    }

    /**
     * Rekord już istnieje: pominięcie albo aktualizacja kolumnami obecnymi w pliku.
     *
     * @param array<string, mixed>                        $existing
     * @param array<string, mixed>                        $input
     * @param list<string>                                $fields
     * @param callable(array<string, mixed>): array<string, mixed> $validate
     * @param callable(array<string, mixed>): mixed       $update
     * @return array<string, mixed>
     */
    private function existing(array $existing, array $input, string $mode, array $fields, callable $validate, callable $update): array
    {
        $id = (int) $existing['id'];
        if ($existing['archived_at'] !== null) {
            if ($mode === 'update') {
                throw ServiceException::conflict(\__('exchange.error.archived'));
            }

            return ['status' => 'skip', 'record_id' => $id, 'message' => \__('exchange.skip.archived')];
        }
        if ($mode === 'skip') {
            return ['status' => 'skip', 'record_id' => $id, 'message' => \__('exchange.skip.exists')];
        }

        $merged = array_merge(array_intersect_key($existing, array_flip($fields)), $input);
        $changes = array_keys(EventLogger::diff($existing, $validate($merged), $fields));
        if ($changes === []) {
            return ['status' => 'unchanged', 'record_id' => $id, 'message' => \__('exchange.skip.unchanged')];
        }

        $update($merged);

        return [
            'status'    => 'update',
            'record_id' => $id,
            'changes'   => array_map(static fn (string $field): string => self::fieldLabel($field), $changes),
        ];
    }

    private function resolvePayer(string $taxId, string $name): ?int
    {
        if ($taxId !== '') {
            $payer = $this->fetchOne('SELECT id, archived_at FROM payers WHERE tax_id = :tax_id LIMIT 1', ['tax_id' => Validator::normalizeTaxId($taxId)]);
            if ($payer === null) {
                throw ServiceException::validation(['payer_tax_id' => \__('exchange.error.payer_not_found', ['value' => $taxId])]);
            }
            if ($payer['archived_at'] !== null) {
                throw ServiceException::validation(['payer_tax_id' => \__('exchange.error.payer_archived', ['value' => $taxId])]);
            }

            return (int) $payer['id'];
        }

        if ($name === '') {
            return null;
        }

        $payer = $this->fetchUnique(
            'SELECT id FROM payers p WHERE p.company_name = :name AND p.archived_at IS NULL AND ' . PayerService::notPersonalOnly('p') . ' LIMIT 2',
            ['name' => $name],
            'payer_name',
            'exchange.error.payer_ambiguous'
        );
        if ($payer === null) {
            throw ServiceException::validation(['payer_name' => \__('exchange.error.payer_not_found', ['value' => $name])]);
        }

        return (int) $payer['id'];
    }

    private function resolveBeneficiary(string $email, string $firstName, string $lastName): ?int
    {
        if ($email !== '') {
            $person = $this->fetchUnique(
                'SELECT id FROM beneficiaries WHERE email = :email AND archived_at IS NULL LIMIT 2',
                ['email' => $email],
                'beneficiary_email',
                'exchange.error.beneficiary_ambiguous'
            );
            if ($person === null) {
                throw ServiceException::validation(['beneficiary_email' => \__('exchange.error.beneficiary_not_found', ['value' => $email])]);
            }

            return (int) $person['id'];
        }

        if ($firstName === '' && $lastName === '') {
            return null;
        }

        $person = $this->fetchUnique(
            'SELECT id FROM beneficiaries WHERE first_name = :first_name AND last_name = :last_name AND archived_at IS NULL LIMIT 2',
            ['first_name' => $firstName, 'last_name' => $lastName],
            'beneficiary_last_name',
            'exchange.error.beneficiary_ambiguous'
        );
        if ($person === null) {
            throw ServiceException::validation(['beneficiary_last_name' => \__('exchange.error.beneficiary_not_found', [
                'value' => trim($firstName . ' ' . $lastName),
            ])]);
        }

        return (int) $person['id'];
    }

    /**
     * Opiekun z kolumny e-mail albo imię i nazwisko konta personelu (tylko konta aktywne).
     */
    private function resolveOwner(string $value): int
    {
        $owner = str_contains($value, '@')
            ? $this->fetchOne('SELECT id FROM users WHERE email = :value AND deactivated_at IS NULL LIMIT 1', ['value' => $value])
            : $this->fetchUnique(
                "SELECT id FROM users WHERE CONCAT_WS(' ', first_name, last_name) = :value AND deactivated_at IS NULL LIMIT 2",
                ['value' => $value],
                'owner_email',
                'exchange.error.owner_ambiguous'
            );

        if ($owner === null) {
            throw ServiceException::validation(['owner_email' => \__('exchange.error.owner_not_found', ['value' => $value])]);
        }

        return (int) $owner['id'];
    }

    private function beneficiaryPayer(?int $beneficiaryId): ?int
    {
        if ($beneficiaryId === null) {
            return null;
        }
        $row = $this->fetchOne('SELECT payer_id FROM beneficiaries WHERE id = :id', ['id' => $beneficiaryId]);

        return $row !== null && $row['payer_id'] !== null ? (int) $row['payer_id'] : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Jeden pasujący rekord albo null; kilka pasujących to błąd niejednoznaczności w podanym polu.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchUnique(string $sql, array $params, string $field, string $messageKey, bool $preferFirst = false): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if (count($rows) > 1 && !$preferFirst) {
            throw ServiceException::validation([$field => \__($messageKey)]);
        }

        return $rows[0] ?? null;
    }

    /**
     * Wiersz z bazy z identyfikatorami jako liczbami — tak porównują je walidatory usług.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $fields
     * @return array<string, mixed>
     */
    private static function castIds(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            $row[$field] = isset($row[$field]) ? (int) $row[$field] : null;
        }

        return $row;
    }

    /**
     * @param array<string, int> $seen
     */
    private static function assertFirstInFile(array &$seen, string $key, int $line): void
    {
        if (isset($seen[$key])) {
            throw ServiceException::validation(['_row' => \__('exchange.error.duplicate_in_file', ['line' => (string) $seen[$key]])]);
        }
        $seen[$key] = $line;
    }

    /**
     * @param list<string>              $codes
     * @param callable(string): string $key
     * @return array<string, string>
     */
    private static function labelKeys(array $codes, callable $key): array
    {
        $labels = [];
        foreach ($codes as $code) {
            $labels[$code] = $key($code);
        }

        return $labels;
    }

    /**
     * @param array<string, string> $data
     */
    private static function rowLabel(string $dataset, array $data): string
    {
        return match ($dataset) {
            'payers'        => $data['company_name'] ?? '',
            'beneficiaries' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
            default         => trim(($data['name'] ?? '') . (($data['serial_number'] ?? '') !== '' ? ' (' . $data['serial_number'] . ')' : '')),
        };
    }

    /**
     * @param array<string, string> $errors
     * @return list<array{field: string, label: string, message: string}>
     */
    private static function errorList(string $dataset, array $errors): array
    {
        $list = [];
        foreach ($errors as $field => $message) {
            $label = $field === '_row' ? '' : (in_array($field, Columns::fields($dataset), true)
                ? \__(Columns::labelKey($dataset, $field))
                : self::fieldLabel($field));
            $list[] = ['field' => $field, 'label' => $label, 'message' => $message];
        }

        return $list;
    }

    private static function fieldLabel(string $field): string
    {
        $label = \__('field.' . $field);

        return $label === 'field.' . $field ? $field : $label;
    }
}

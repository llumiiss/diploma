<?php

declare(strict_types=1);

namespace App\Service;

use App\CertificateHelper;
use App\Exchange\Columns;
use App\Exchange\CsvWriter;
use App\Exchange\XmlExporter;
use PDO;

/**
 * Eksport danych do CSV i XML (F9): listy certyfikatów, użytkowników certyfikatów, płatników,
 * zadań, zaproszeń i dziennika zdarzeń, karty raportowe osoby i płatnika oraz harmonogram
 * wygaśnięć. Wzory plików importu to eksport samych nagłówków.
 *
 * CSV jest dla ludzi (etykiety kolumn i wartości słownikowych w języku interfejsu), XML dla
 * systemów (nazwy pól i kody). Import przyjmuje oba warianty. Dane pochodzą z tych samych
 * usług co ekrany, więc eksport ma ten sam zakres danych konta (D8). Każdy eksport z danymi
 * zapisuje zdarzenie w historii — to wyniesienie danych osobowych poza system.
 */
final class ExportService
{
    public const FORMATS = ['csv', 'xml'];
    public const DATASETS = ['certificates', 'beneficiaries', 'payers', 'tasks', 'invitations', 'events', 'beneficiary_card', 'payer_card', 'schedule'];

    private const FILE_NAMES = [
        'certificates'     => 'certyfikaty',
        'beneficiaries'    => 'uzytkownicy-certyfikatow',
        'payers'           => 'platnicy',
        'tasks'            => 'zadania',
        'invitations'      => 'zaproszenia',
        'events'           => 'dziennik-zdarzen',
        'beneficiary_card' => 'karta-uzytkownika',
        'payer_card'       => 'karta-platnika',
        'schedule'         => 'harmonogram-wygasniec',
    ];

    /** Nazwy elementów pozycji list w XML kart i harmonogramu. */
    private const XML_ITEMS = [
        'certificates'  => 'certificate',
        'history'       => 'certificate',
        'tasks'         => 'task',
        'invitations'   => 'invitation',
        'timeline'      => 'event',
        'beneficiaries' => 'beneficiary',
        'schedule'      => 'month',
        'buckets'       => 'month',
        'changes'       => 'change',
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
     * @param array{archived?: bool, template?: bool, id?: int|null, filters?: array<string, mixed>} $options
     * @return array{filename: string, content_type: string, body: string, records: int}
     */
    public function export(Actor $actor, string $dataset, string $format, array $options = []): array
    {
        $actor->authorize('export.run');
        if (!in_array($dataset, self::DATASETS, true)) {
            throw ServiceException::badRequest(\__('exchange.error.unknown_dataset'));
        }
        if (!in_array($format, self::FORMATS, true)) {
            throw ServiceException::badRequest(\__('exchange.error.unknown_format'));
        }

        $archived = !empty($options['archived']);
        $template = !empty($options['template']);
        $id = isset($options['id']) ? (int) $options['id'] : null;
        $filters = $options['filters'] ?? [];

        if ($template) {
            if (!Columns::isImportable($dataset)) {
                throw ServiceException::badRequest(\__('exchange.error.unknown_dataset'));
            }

            return $this->file($dataset, $format, '-wzor', $this->template($dataset, $format), 0);
        }

        [$body, $records] = match ($dataset) {
            'certificates'     => $this->listFile($dataset, $format, $this->certificateRecords($actor, $archived)),
            'beneficiaries'    => $this->listFile($dataset, $format, $this->beneficiaryRecords($actor, $archived)),
            'payers'           => $this->listFile($dataset, $format, $this->payerRecords($actor, $archived)),
            'tasks'            => $this->plainFile($dataset, $format, $this->taskRecords($actor), 'task'),
            'invitations'      => $this->plainFile($dataset, $format, $this->invitationRecords($actor), 'invitation'),
            'events'           => $this->plainFile($dataset, $format, $this->eventRecords($actor, $filters), 'event'),
            'beneficiary_card' => $this->cardFile('beneficiary_card', $format, (new ReportService($this->db))->beneficiaryCard($actor, self::requireId($id))),
            'payer_card'       => $this->cardFile('payer_card', $format, (new ReportService($this->db))->payerCard($actor, self::requireId($id))),
            'schedule'         => $this->scheduleFile($format, (new ReportService($this->db))->schedule($actor, $filters)),
        };

        $this->events->log('system', null, 'data_exported', $actor->id, [], array_filter([
            'dataset'   => $dataset,
            'format'    => $format,
            'records'   => $records,
            'archived'  => $archived ?: null,
            'record_id' => $id,
        ], static fn (mixed $value): bool => $value !== null));

        $suffix = ($archived ? '-archiwum' : '') . ($id !== null && str_ends_with($dataset, '_card') ? '-' . $id : '');

        return $this->file($dataset, $format, $suffix, $body, $records);
    }

    /**
     * @return array{filename: string, content_type: string, body: string, records: int}
     */
    private function file(string $dataset, string $format, string $suffix, string $body, int $records): array
    {
        return [
            'filename'     => self::FILE_NAMES[$dataset] . $suffix . '-' . date('Y-m-d') . '.' . $format,
            'content_type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/xml; charset=UTF-8',
            'body'         => $body,
            'records'      => $records,
        ];
    }

    private function template(string $dataset, string $format): string
    {
        $fields = array_column(Columns::importColumns($dataset), 'field');
        if ($format === 'csv') {
            return CsvWriter::write(array_map(static fn (string $field): string => \__(Columns::labelKey($dataset, $field)), $fields), []);
        }

        return XmlExporter::records('certisub', self::recordElement($dataset), [array_fill_keys($fields, null)], ['dataset' => $dataset, 'template' => '1']);
    }

    /**
     * Zbiór z definicją kolumn w App\Exchange\Columns (ten sam układ co import).
     *
     * @param list<array<string, mixed>> $records rekordy z kodami wartości (XML)
     * @return array{0: string, 1: int}
     */
    private function listFile(string $dataset, string $format, array $records): array
    {
        if ($format === 'xml') {
            return [XmlExporter::records('certisub', self::recordElement($dataset), $records, [
                'dataset'      => $dataset,
                'generated_at' => date('c'),
            ]), count($records)];
        }

        $fields = Columns::fields($dataset);
        $headers = array_map(static fn (string $field): string => \__(Columns::labelKey($dataset, $field)), $fields);
        $rows = array_map(static fn (array $record): array => array_map(
            static fn (string $field): string|int|float|bool|null => self::csvValue($field, $record[$field] ?? null),
            $fields
        ), $records);

        return [CsvWriter::write($headers, $rows), count($records)];
    }

    /**
     * Zbiór tylko do eksportu: kolumny to klucze rekordu, etykiety z exchange.column.<pole>.
     *
     * @param list<array<string, mixed>> $records
     * @return array{0: string, 1: int}
     */
    private function plainFile(string $dataset, string $format, array $records, string $element): array
    {
        if ($format === 'xml') {
            return [XmlExporter::records('certisub', $element, $records, [
                'dataset'      => $dataset,
                'generated_at' => date('c'),
            ]), count($records)];
        }

        $fields = $records !== [] ? array_keys($records[0]) : [];
        $headers = array_map(static fn (string $field): string => \__('exchange.column.' . $field), $fields);
        $rows = array_map(static fn (array $record): array => array_map(
            static fn (string $field): string|int|float|bool|null => self::csvValue($field, $record[$field]),
            $fields
        ), $records);

        return [CsvWriter::write($headers, $rows), count($records)];
    }

    /**
     * @param array<string, mixed> $report
     * @return array{0: string, 1: int}
     */
    private function cardFile(string $dataset, string $format, array $report): array
    {
        $certificates = array_merge($report['certificates'], $report['history']);

        if ($format === 'xml') {
            return [XmlExporter::tree($dataset, $report, ['generated_at' => date('c')], self::XML_ITEMS), count($certificates)];
        }

        $rows = array_map(static fn (array $item): array => self::reportCertificateRow($item), $certificates);

        return [CsvWriter::write(self::reportCertificateHeaders(), $rows), count($certificates)];
    }

    /**
     * @param array<string, mixed> $report
     * @return array{0: string, 1: int}
     */
    private function scheduleFile(string $format, array $report): array
    {
        if ($format === 'xml') {
            return [XmlExporter::tree('schedule', $report, ['generated_at' => date('c')], self::XML_ITEMS), (int) $report['total']];
        }

        $headers = array_merge([\__('exchange.column.schedule_month')], self::reportCertificateHeaders());
        $rows = [];
        foreach ($report['buckets'] as $bucket) {
            foreach ($bucket['certificates'] as $item) {
                $rows[] = array_merge([$bucket['overdue'] ? \__('report.schedule.overdue') : $bucket['month']], self::reportCertificateRow($item));
            }
        }

        return [CsvWriter::write($headers, $rows), (int) $report['total']];
    }

    /**
     * @return list<string>
     */
    private static function reportCertificateHeaders(): array
    {
        return array_map(static fn (string $key): string => \__($key), [
            'exchange.column.id', 'field.name', 'field.certificate_type', 'field.serial_number', 'field.beneficiary_id',
            'field.payer_id', 'field.valid_from', 'field.expiry_date', 'report.column.renewal_from', 'field.renewal_lead_days',
            'exchange.column.days_left', 'exchange.column.priority', 'exchange.column.task_status', 'task.assignee',
            'exchange.column.invitation_count', 'exchange.column.last_contact_at', 'field.discount_percent',
            'exchange.column.archived_at', 'exchange.column.next_certificate_id',
        ]);
    }

    /**
     * @param array<string, mixed> $item certyfikat z ReportService
     * @return list<string|int|float|bool|null>
     */
    private static function reportCertificateRow(array $item): array
    {
        return [
            $item['id'],
            $item['name'],
            \__(CertificateHelper::typeKey((string) $item['certificate_type'])),
            $item['serial_number'],
            $item['beneficiary']['name'] ?? null,
            $item['payer']['name'],
            $item['valid_from'],
            $item['expiry_date'],
            $item['renewal_from'],
            $item['renewal_lead_days'],
            $item['days_left'],
            $item['priority'] !== null ? \__('priority.label.' . $item['priority']) : null,
            $item['open_task'] !== null ? \__('task.status.' . $item['open_task']['status']) : null,
            $item['open_task']['assignee_name'] ?? null,
            $item['invitation_count'],
            $item['last_contact_at'],
            CertificateHelper::formatDiscount((float) $item['discount_percent']),
            $item['archived_at'],
            $item['next_certificate_id'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function certificateRecords(Actor $actor, bool $archived): array
    {
        return array_map(static fn (array $row): array => [
            'id'                      => $row['id'],
            'name'                    => $row['name'],
            'certificate_type'        => $row['certificate_type'],
            'serial_number'           => $row['serial_number'],
            'issuer'                  => $row['issuer'],
            'valid_from'              => $row['valid_from'],
            'expiry_date'             => $row['expiry_date'],
            'renewal_lead_days'       => $row['renewal_lead_days'],
            'status'                  => $row['status'],
            'beneficiary_first_name'  => $row['beneficiary_first_name'],
            'beneficiary_last_name'   => $row['beneficiary_last_name'],
            'beneficiary_email'       => $row['beneficiary_email'],
            'payer_name'              => $row['company_name'],
            'payer_tax_id'            => $row['payer_tax_id'],
            'owner_email'             => $row['user_email'],
            'discount_percent'        => (float) $row['discount_percent'],
            'billing_cycle'           => $row['billing_cycle'],
            'payment_status'          => $row['payment_status'],
            'last_payment_date'       => $row['last_payment_date'],
            'auto_renew'              => (bool) $row['auto_renew'],
            'notes'                   => $row['notes'],
            'previous_certificate_id' => $row['previous_certificate_id'],
            'days_left'               => $row['days_left'],
            'priority'                => $archived ? null : $row['priority'],
            'created_at'              => $row['created_at'],
            'archived_at'             => $row['archived_at'],
        ], $this->certificates->list($actor, [], $archived));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function beneficiaryRecords(Actor $actor, bool $archived): array
    {
        return array_map(static fn (array $row): array => [
            'id'                => $row['id'],
            'first_name'        => $row['first_name'],
            'last_name'         => $row['last_name'],
            'email'             => $row['email'],
            'phone'             => $row['phone'],
            'payer_name'        => $row['payer_name'],
            'payer_tax_id'      => $row['payer_tax_id'],
            'notes'             => $row['notes'],
            'certificate_count' => $row['certificate_count'],
            'earliest_expiry'   => $row['earliest_expiry'],
            'created_at'        => $row['created_at'],
            'archived_at'       => $row['archived_at'],
        ], $this->beneficiaries->list($actor, $archived));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payerRecords(Actor $actor, bool $archived): array
    {
        return array_map(static fn (array $row): array => [
            'id'                => $row['id'],
            'company_name'      => $row['company_name'],
            'tax_id'            => $row['tax_id'],
            'contact_person'    => $row['contact_person'],
            'email'             => $row['email'],
            'phone'             => $row['phone'],
            'address_line'      => $row['address_line'],
            'postal_code'       => $row['postal_code'],
            'city'              => $row['city'],
            'beneficiary_count' => $row['beneficiary_count'],
            'certificate_count' => $row['certificate_count'],
            'created_at'        => $row['created_at'],
            'archived_at'       => $row['archived_at'],
        ], $this->payers->list($actor, $archived));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taskRecords(Actor $actor): array
    {
        return array_map(static fn (array $row): array => [
            'id'               => $row['id'],
            'certificate_name' => $row['certificate_name'],
            'serial_number'    => $row['serial_number'],
            'beneficiary_name' => $row['beneficiary_name'],
            'payer_name'       => $row['payer_name'],
            'task_status'      => $row['status'],
            'priority'         => $row['priority'],
            'due_date'         => $row['due_date'],
            'assignee_name'    => $row['assignee_name'],
            'owner_name'       => $row['owner_name'],
            'created_at'       => $row['created_at'],
            'closed_at'        => $row['closed_at'],
            'resolution_note'  => $row['resolution_note'],
            'invitation_count' => $row['invitation_count'],
        ], (new TaskService($this->db, $this->events, $this->certificates))->list($actor, ['status' => 'all']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invitationRecords(Actor $actor): array
    {
        return array_map(static fn (array $row): array => [
            'id'                => $row['id'],
            'certificate_name'  => $row['certificate_name'],
            'serial_number'     => $row['serial_number'],
            'recipient_type'    => $row['recipient_type'],
            'recipient_name'    => $row['recipient_name'],
            'recipient_email'   => $row['recipient_email'],
            'subject'           => $row['subject'],
            'invitation_status' => $row['status'],
            'sent_at'           => $row['sent_at'],
            'reminder_count'    => $row['reminder_count'],
            'last_reminder_at'  => $row['last_reminder_at'],
            'next_reminder_at'  => $row['next_reminder_at'],
            'last_error'        => $row['last_error'],
            'created_at'        => $row['created_at'],
        ], (new InvitationService($this->db, null, null, $this->events))->list($actor));
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function eventRecords(Actor $actor, array $filters): array
    {
        return array_map(static fn (array $row): array => [
            'id'               => $row['id'],
            'occurred_at'      => $row['occurred_at'],
            'user_name'        => $row['user_name'] !== '' ? $row['user_name'] : \__('eventlog.system_user'),
            'entity_type'      => $row['entity_type'],
            'event_type'       => $row['event_type'],
            'event_label'      => self::eventLabel((string) $row['entity_type'], (string) $row['event_type']),
            'certificate_name' => $row['certificate_name'],
            'beneficiary_name' => $row['beneficiary_name'] !== '' ? $row['beneficiary_name'] : null,
            'payer_name'       => $row['payer_name'],
            'entity_label'     => $row['entity_label'],
            'payload'          => $row['payload'],
        ], (new TimelineService($this->db))->journalRows($actor, $filters));
    }

    private static function eventLabel(string $entity, string $event): string
    {
        $key = 'event.' . $entity . '.' . $event;
        $label = \__($key);

        return $label === $key ? \__('event.generic', ['type' => $event]) : $label;
    }

    /**
     * Wartość komórki CSV: kody słowników jako etykiety w języku interfejsu, struktury jako JSON.
     */
    private static function csvValue(string $field, mixed $value): string|int|float|bool|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value === [] ? null : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($field === 'discount_percent' && is_numeric($value)) {
            return CertificateHelper::formatDiscount((float) $value);
        }
        if (!is_string($value)) {
            return is_scalar($value) ? $value : null;
        }

        return match ($field) {
            'certificate_type'  => \__(CertificateHelper::typeKey($value)),
            'status'            => \__('status.' . $value),
            'billing_cycle'     => \__('billing.' . $value),
            'payment_status'    => \__($value === 'not_applicable' ? 'payment.na' : 'payment.' . $value),
            'priority'          => \__('priority.label.' . $value),
            'task_status'       => \__('task.status.' . $value),
            'invitation_status' => \__('invitation.status.' . $value),
            'recipient_type'    => \__('invitation.recipient_type.' . $value),
            'entity_type'       => \__('eventlog.entity.' . $value),
            default             => $value,
        };
    }

    private static function requireId(?int $id): int
    {
        return $id ?? throw ServiceException::badRequest(\__('api.error.missing_id'));
    }

    private static function recordElement(string $dataset): string
    {
        return match ($dataset) {
            'certificates'  => 'certificate',
            'beneficiaries' => 'beneficiary',
            'payers'        => 'payer',
            default         => 'record',
        };
    }
}

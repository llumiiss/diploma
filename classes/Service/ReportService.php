<?php

declare(strict_types=1);

namespace App\Service;

use App\CertificateHelper;
use App\Settings;
use DateTimeImmutable;
use PDO;

/**
 * Raporty perspektyw z opisu pracy (F6, F7) i harmonogram wygaśnięć.
 *
 *  - karta użytkownika certyfikatu: dane osoby, certyfikaty z datami odnowienia, łańcuch odnowień,
 *    zadania, zaproszenia i historia na osi czasu,
 *  - karta płatnika: dane płatnika, powiązane osoby, certyfikaty z datami wygaśnięcia, koszt roczny,
 *    harmonogram wygaśnięć, zadania, zaproszenia i historia,
 *  - harmonogram wygaśnięć: certyfikaty z całego zakresu konta pogrupowane wg miesięcy.
 *
 * Wszystkie odczyty respektują zakres danych konta (D8). Certyfikaty z archiwum i zarchiwizowane
 * osoby pojawiają się w kartach tylko dla ról z dostępem do archiwum.
 */
final class ReportService
{
    public const DEFAULT_SCHEDULE_MONTHS = 12;
    public const MAX_SCHEDULE_MONTHS = 24;

    private readonly TimelineService $timeline;
    private readonly PayerService $payers;
    private readonly BeneficiaryService $beneficiaries;

    public function __construct(
        private readonly PDO $db,
        ?TimelineService $timeline = null,
        ?PayerService $payers = null,
        ?BeneficiaryService $beneficiaries = null,
    ) {
        $this->timeline = $timeline ?? new TimelineService($db);
        $this->payers = $payers ?? new PayerService($db);
        $this->beneficiaries = $beneficiaries ?? new BeneficiaryService($db, null, $this->payers);
    }

    /**
     * Perspektywa Użytkownika: daty odnowienia, szczegóły certyfikatów i historia (F6).
     *
     * @return array<string, mixed>
     */
    public function beneficiaryCard(Actor $actor, int $id, ?DateTimeImmutable $today = null): array
    {
        $actor->authorize('reports.view');
        $person = $this->beneficiaries->findVisible($actor, $id, true);
        $today = $today ?? $this->today();
        $settings = Settings::read($this->db);

        $certificates = $this->certificates($actor, 'c.beneficiary_id = :record_id', ['record_id' => $id], $today, $settings);
        $ids = array_column($certificates, 'id');
        $tasks = $this->tasks($ids);
        $invitations = $this->invitations($ids);
        [$active, $history] = self::splitArchived($certificates);

        return [
            'beneficiary'    => [
                'id'                => $person['id'],
                'first_name'        => $person['first_name'],
                'last_name'         => $person['last_name'],
                'email'             => $person['email'],
                'phone'             => $person['phone'],
                'notes'             => $person['notes'],
                'payer_id'          => $person['payer_id'],
                'payer_name'        => $person['payer_name'],
                'payer_archived_at' => $person['payer_archived_at'],
                'archived_at'       => $person['archived_at'],
                'created_at'        => $person['created_at'],
            ],
            'summary'        => self::summary($active, $history, $tasks, $invitations, $settings),
            'certificates'   => $active,
            'history'        => $history,
            'tasks'          => $tasks,
            'invitations'    => $invitations,
            'timeline'       => $this->timeline->forContext('beneficiary', $id, 300, $actor),
            'archive_access' => $actor->can('archive.view'),
            'thresholds'     => self::thresholds($settings),
            'generated_at'   => date('Y-m-d H:i:s'),
            'today'          => $today->format('Y-m-d'),
        ];
    }

    /**
     * Perspektywa Płatnika: certyfikaty, daty wygaśnięcia, historia i powiązane osoby (F7).
     *
     * @return array<string, mixed>
     */
    public function payerCard(Actor $actor, int $id, ?DateTimeImmutable $today = null): array
    {
        $actor->authorize('reports.view');
        $payer = $this->payers->findVisible($actor, $id, true);
        $today = $today ?? $this->today();
        $settings = Settings::read($this->db);

        $certificates = $this->certificates($actor, 'c.payer_id = :record_id', ['record_id' => $id], $today, $settings);
        $ids = array_column($certificates, 'id');
        $tasks = $this->tasks($ids);
        $invitations = $this->invitations($ids);
        [$active, $history] = self::splitArchived($certificates);
        $beneficiaries = $this->payerBeneficiaries($actor, $id, $today);

        $summary = self::summary($active, $history, $tasks, $invitations, $settings);
        $summary['beneficiaries'] = count(array_filter($beneficiaries, static fn (array $row): bool => $row['archived_at'] === null));
        $summary['annual_cost'] = self::costTotals($active);

        return [
            'payer'          => [
                'id'             => $payer['id'],
                'company_name'   => $payer['company_name'],
                'contact_person' => $payer['contact_person'],
                'tax_id'         => $payer['tax_id'],
                'email'          => $payer['email'],
                'phone'          => $payer['phone'],
                'address_line'   => $payer['address_line'],
                'postal_code'    => $payer['postal_code'],
                'city'           => $payer['city'],
                'archived_at'    => $payer['archived_at'],
                'created_at'     => $payer['created_at'],
            ],
            'summary'        => $summary,
            'beneficiaries'  => $beneficiaries,
            'certificates'   => $active,
            'history'        => $history,
            'schedule'       => self::scheduleBuckets($active, $today, self::DEFAULT_SCHEDULE_MONTHS),
            'tasks'          => $tasks,
            'invitations'    => $invitations,
            'timeline'       => $this->timeline->forContext('payer', $id, 300, $actor),
            'archive_access' => $actor->can('archive.view'),
            'thresholds'     => self::thresholds($settings),
            'generated_at'   => date('Y-m-d H:i:s'),
            'today'          => $today->format('Y-m-d'),
        ];
    }

    /**
     * Harmonogram wygaśnięć bieżących certyfikatów widocznych dla konta: zaległe oraz kolejne miesiące.
     *
     * @param array<string, mixed> $filters months (1–24), payer_id, certificate_type
     * @return array<string, mixed>
     */
    public function schedule(Actor $actor, array $filters = [], ?DateTimeImmutable $today = null): array
    {
        $actor->authorize('reports.view');
        $v = new Validator($filters);
        $months = $v->int('months', false, 1, self::MAX_SCHEDULE_MONTHS) ?? self::DEFAULT_SCHEDULE_MONTHS;
        $payerId = $v->id('payer_id', false);
        $type = $v->enum('certificate_type', false, CertificateService::TYPES);
        $v->throwIfFailed();

        $today = $today ?? $this->today();
        $settings = Settings::read($this->db);
        $end = $today->modify('first day of this month')->modify('+' . $months . ' months');

        $conditions = ['c.archived_at IS NULL', 'c.expiry_date < :schedule_end'];
        $params = ['schedule_end' => $end->format('Y-m-d')];
        if ($payerId !== null) {
            $conditions[] = 'c.payer_id = :payer_id';
            $params['payer_id'] = $payerId;
        }
        if ($type !== null) {
            $conditions[] = 'c.certificate_type = :certificate_type';
            $params['certificate_type'] = $type;
        }

        $certificates = $this->certificates($actor, implode(' AND ', $conditions), $params, $today, $settings);
        $buckets = self::scheduleBuckets($certificates, $today, $months);

        return [
            'months'       => $months,
            'buckets'      => $buckets,
            'total'        => count($certificates),
            'annual_cost'  => self::costTotals($certificates),
            'thresholds'   => self::thresholds($settings),
            'generated_at' => date('Y-m-d H:i:s'),
            'today'        => $today->format('Y-m-d'),
        ];
    }

    /**
     * Podział certyfikatów na zaległe (wygasłe) i miesiące wygaśnięcia od bieżącego.
     * Certyfikaty po ostatnim miesiącu zakresu są pomijane.
     *
     * @param list<array<string, mixed>> $certificates
     * @return list<array{key: string, month: ?string, overdue: bool, count: int, certificates: list<array<string, mixed>>}>
     */
    public static function scheduleBuckets(array $certificates, DateTimeImmutable $today, int $months): array
    {
        $buckets = ['overdue' => ['key' => 'overdue', 'month' => null, 'overdue' => true, 'count' => 0, 'certificates' => []]];
        $cursor = $today->modify('first day of this month');
        for ($i = 0; $i < $months; ++$i) {
            $key = $cursor->format('Y-m');
            $buckets[$key] = ['key' => $key, 'month' => $key, 'overdue' => false, 'count' => 0, 'certificates' => []];
            $cursor = $cursor->modify('+1 month');
        }

        foreach ($certificates as $certificate) {
            if ($certificate['archived_at'] !== null) {
                continue;
            }
            $key = $certificate['days_left'] < 0 ? 'overdue' : substr((string) $certificate['expiry_date'], 0, 7);
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['certificates'][] = $certificate;
            ++$buckets[$key]['count'];
        }

        return array_values($buckets);
    }

    /**
     * Certyfikaty firmowe spełniające warunek i widoczne dla konta, z otwartym zadaniem
     * i skrótem zaproszeń. Archiwalne tylko dla ról z dostępem do archiwum.
     *
     * @param array<string, int|string> $params
     * @param array<string, int>        $settings
     * @return list<array<string, mixed>>
     */
    private function certificates(Actor $actor, string $condition, array $params, DateTimeImmutable $today, array $settings): array
    {
        [$visibility, $visibilityParams] = Visibility::certificates($actor, 'c');
        [$nextVisibility, $nextParams] = Visibility::certificates($actor, 'n');
        $archived = $actor->can('archive.view') ? '' : ' AND c.archived_at IS NULL';

        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, c.certificate_type, c.serial_number, c.issuer, c.valid_from, c.expiry_date,
                    c.renewal_lead_days, c.status, c.annual_cost, c.billing_cycle, c.currency, c.payment_status,
                    c.previous_certificate_id, c.archived_at, c.user_id, c.beneficiary_id, c.payer_id,
                    u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                    b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name,
                    p.company_name AS payer_name,
                    (SELECT n.id FROM certificates n WHERE n.previous_certificate_id = c.id AND {$nextVisibility}
                     ORDER BY n.id DESC LIMIT 1) AS next_certificate_id,
                    t.id AS task_id, t.status AS task_status, t.priority AS task_priority, t.due_date AS task_due_date,
                    ta.first_name AS task_assignee_first_name, ta.last_name AS task_assignee_last_name,
                    (SELECT COUNT(*) FROM invitations i WHERE i.certificate_id = c.id) AS invitation_count,
                    (SELECT MAX(COALESCE(i2.last_reminder_at, i2.sent_at)) FROM invitations i2 WHERE i2.certificate_id = c.id) AS last_contact_at
             FROM certificates c
             INNER JOIN users u ON u.id = c.user_id
             INNER JOIN payers p ON p.id = c.payer_id
             LEFT JOIN beneficiaries b ON b.id = c.beneficiary_id
             LEFT JOIN renewal_tasks t ON t.certificate_id = c.id AND t.status IN ('todo', 'in_progress')
             LEFT JOIN users ta ON ta.id = t.assigned_user_id
             WHERE {$condition} AND {$visibility}{$archived}
             ORDER BY c.archived_at IS NOT NULL, c.expiry_date, c.id"
        );
        $stmt->execute($params + $visibilityParams + $nextParams);

        return array_map(fn (array $row): array => $this->presentCertificate($row, $today, $settings), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int>   $settings
     * @return array<string, mixed>
     */
    private function presentCertificate(array $row, DateTimeImmutable $today, array $settings): array
    {
        $expiry = new DateTimeImmutable((string) $row['expiry_date']);
        $daysLeft = (int) $today->diff($expiry)->format('%r%a');
        $customLead = $row['renewal_lead_days'] !== null;
        $lead = $customLead ? (int) $row['renewal_lead_days'] : $settings['renewal.warning_days'];
        $renewalFrom = $expiry->modify('-' . $lead . ' days');
        $archived = $row['archived_at'] !== null;
        $amount = (float) $row['annual_cost'];

        return [
            'id'                      => (int) $row['id'],
            'name'                    => (string) $row['name'],
            'certificate_type'        => (string) $row['certificate_type'],
            'serial_number'           => $row['serial_number'],
            'issuer'                  => $row['issuer'],
            'valid_from'              => $row['valid_from'],
            'expiry_date'             => (string) $row['expiry_date'],
            'renewal_lead_days'       => $lead,
            'renewal_lead_custom'     => $customLead,
            'renewal_from'            => $renewalFrom->format('Y-m-d'),
            'in_renewal_window'       => !$archived && $today >= $renewalFrom,
            'days_left'               => $daysLeft,
            'priority'                => $archived ? null : CertificateHelper::priorityFor(
                $daysLeft,
                $settings['renewal.critical_days'],
                $settings['renewal.warning_days']
            ),
            'status'                  => (string) $row['status'],
            'annual_cost'             => $amount,
            'annualized_cost'         => CertificateHelper::annualizedCost($amount, (string) $row['billing_cycle'], $row['valid_from'], (string) $row['expiry_date']),
            'billing_cycle'           => (string) $row['billing_cycle'],
            'currency'                => (string) $row['currency'],
            'payment_status'          => (string) $row['payment_status'],
            'owner'                   => ['id' => (int) $row['user_id'], 'name' => trim($row['owner_first_name'] . ' ' . $row['owner_last_name'])],
            'beneficiary'             => $row['beneficiary_id'] !== null ? [
                'id'   => (int) $row['beneficiary_id'],
                'name' => trim($row['beneficiary_first_name'] . ' ' . $row['beneficiary_last_name']),
            ] : null,
            'payer'                   => ['id' => (int) $row['payer_id'], 'name' => (string) $row['payer_name']],
            'previous_certificate_id' => $row['previous_certificate_id'] !== null ? (int) $row['previous_certificate_id'] : null,
            'next_certificate_id'     => $row['next_certificate_id'] !== null ? (int) $row['next_certificate_id'] : null,
            'archived_at'             => $row['archived_at'],
            'open_task'               => $row['task_id'] !== null ? [
                'id'            => (int) $row['task_id'],
                'status'        => (string) $row['task_status'],
                'priority'      => (string) $row['task_priority'],
                'due_date'      => (string) $row['task_due_date'],
                'assignee_name' => trim(($row['task_assignee_first_name'] ?? '') . ' ' . ($row['task_assignee_last_name'] ?? '')),
            ] : null,
            'invitation_count'        => (int) $row['invitation_count'],
            'last_contact_at'         => $row['last_contact_at'],
        ];
    }

    /**
     * Wszystkie zadania odnowień (otwarte i zamknięte) dla podanych certyfikatów — ścieżka realizacji.
     *
     * @param list<int> $certificateIds
     * @return list<array<string, mixed>>
     */
    private function tasks(array $certificateIds): array
    {
        if ($certificateIds === []) {
            return [];
        }

        [$in, $params] = self::inList('task_certificate', $certificateIds);
        $stmt = $this->db->prepare(
            "SELECT t.id, t.certificate_id, t.status, t.priority, t.due_date, t.resolution_note, t.closed_at, t.created_at,
                    c.name AS certificate_name, a.first_name AS assignee_first_name, a.last_name AS assignee_last_name
             FROM renewal_tasks t
             INNER JOIN certificates c ON c.id = t.certificate_id
             LEFT JOIN users a ON a.id = t.assigned_user_id
             WHERE t.certificate_id IN ({$in})
             ORDER BY t.created_at DESC, t.id DESC"
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id'               => (int) $row['id'],
            'certificate_id'   => (int) $row['certificate_id'],
            'certificate_name' => (string) $row['certificate_name'],
            'status'           => (string) $row['status'],
            'priority'         => (string) $row['priority'],
            'due_date'         => (string) $row['due_date'],
            'resolution_note'  => $row['resolution_note'],
            'closed_at'        => $row['closed_at'],
            'created_at'       => $row['created_at'],
            'assignee_name'    => trim(($row['assignee_first_name'] ?? '') . ' ' . ($row['assignee_last_name'] ?? '')),
        ], $stmt->fetchAll());
    }

    /**
     * @param list<int> $certificateIds
     * @return list<array<string, mixed>>
     */
    private function invitations(array $certificateIds): array
    {
        if ($certificateIds === []) {
            return [];
        }

        [$in, $params] = self::inList('invitation_certificate', $certificateIds);
        $stmt = $this->db->prepare(
            "SELECT i.id, i.certificate_id, i.renewal_task_id, i.recipient_type, i.recipient_email, i.recipient_name,
                    i.subject, i.status, i.sent_at, i.reminder_count, i.last_reminder_at, i.next_reminder_at, i.created_at,
                    c.name AS certificate_name
             FROM invitations i
             INNER JOIN certificates c ON c.id = i.certificate_id
             WHERE i.certificate_id IN ({$in})
             ORDER BY COALESCE(i.sent_at, i.created_at) DESC, i.id DESC"
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id'               => (int) $row['id'],
            'certificate_id'   => (int) $row['certificate_id'],
            'certificate_name' => (string) $row['certificate_name'],
            'renewal_task_id'  => $row['renewal_task_id'] !== null ? (int) $row['renewal_task_id'] : null,
            'recipient_type'   => (string) $row['recipient_type'],
            'recipient_email'  => (string) $row['recipient_email'],
            'recipient_name'   => $row['recipient_name'],
            'subject'          => (string) $row['subject'],
            'status'           => (string) $row['status'],
            'sent_at'          => $row['sent_at'],
            'reminder_count'   => (int) $row['reminder_count'],
            'last_reminder_at' => $row['last_reminder_at'],
            'next_reminder_at' => $row['next_reminder_at'],
            'created_at'       => $row['created_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Osoby powiązane z płatnikiem: przypisane do niego oraz te, których certyfikaty on opłaca.
     *
     * @return list<array<string, mixed>>
     */
    private function payerBeneficiaries(Actor $actor, int $payerId, DateTimeImmutable $today): array
    {
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
        [$linkVisibility, $linkParams] = Visibility::certificates($actor, 'lc');
        [$countVisibility, $countParams] = Visibility::certificates($actor, 'cc');
        [$expiryVisibility, $expiryParams] = Visibility::certificates($actor, 'ec');
        $archived = $actor->can('archive.view') ? '' : ' AND b.archived_at IS NULL';

        $stmt = $this->db->prepare(
            "SELECT b.id, b.first_name, b.last_name, b.email, b.phone, b.archived_at,
                    (b.payer_id = :payer_direct) AS linked_directly,
                    (SELECT COUNT(*) FROM certificates cc
                     WHERE cc.beneficiary_id = b.id AND cc.payer_id = :payer_count
                       AND cc.archived_at IS NULL AND {$countVisibility}) AS certificate_count,
                    (SELECT MIN(ec.expiry_date) FROM certificates ec
                     WHERE ec.beneficiary_id = b.id AND ec.payer_id = :payer_expiry
                       AND ec.archived_at IS NULL AND {$expiryVisibility}) AS next_expiry
             FROM beneficiaries b
             WHERE (b.payer_id = :payer_where OR EXISTS (
                        SELECT 1 FROM certificates lc
                        WHERE lc.beneficiary_id = b.id AND lc.payer_id = :payer_link AND {$linkVisibility}))
               AND {$visibility}{$archived}
             ORDER BY b.archived_at IS NOT NULL, b.last_name, b.first_name"
        );
        $stmt->execute([
            'payer_direct' => $payerId,
            'payer_count'  => $payerId,
            'payer_expiry' => $payerId,
            'payer_where'  => $payerId,
            'payer_link'   => $payerId,
        ] + $params + $linkParams + $countParams + $expiryParams);

        return array_map(static function (array $row) use ($today): array {
            $nextExpiry = $row['next_expiry'];

            return [
                'id'                => (int) $row['id'],
                'first_name'        => (string) $row['first_name'],
                'last_name'         => (string) $row['last_name'],
                'email'             => $row['email'],
                'phone'             => $row['phone'],
                'archived_at'       => $row['archived_at'],
                'linked_directly'   => (bool) $row['linked_directly'],
                'certificate_count' => (int) $row['certificate_count'],
                'next_expiry'       => $nextExpiry,
                'days_left'         => $nextExpiry !== null ? (int) $today->diff(new DateTimeImmutable((string) $nextExpiry))->format('%r%a') : null,
            ];
        }, $stmt->fetchAll());
    }

    /**
     * @param list<array<string, mixed>> $active
     * @param list<array<string, mixed>> $history
     * @param list<array<string, mixed>> $tasks
     * @param list<array<string, mixed>> $invitations
     * @param array<string, int>         $settings
     * @return array<string, mixed>
     */
    private static function summary(array $active, array $history, array $tasks, array $invitations, array $settings): array
    {
        $critical = $settings['renewal.critical_days'];
        $warning = $settings['renewal.warning_days'];
        $next = null;
        $counts = ['expired' => 0, 'critical' => 0, 'expiring_soon' => 0, 'in_renewal_window' => 0];

        foreach ($active as $certificate) {
            $days = $certificate['days_left'];
            if ($days < 0) {
                ++$counts['expired'];
            } else {
                if ($days <= $critical) {
                    ++$counts['critical'];
                }
                if ($days <= $warning) {
                    ++$counts['expiring_soon'];
                }
                if ($next === null) {
                    $next = ['certificate_id' => $certificate['id'], 'name' => $certificate['name'], 'expiry_date' => $certificate['expiry_date'], 'days_left' => $days];
                }
            }
            if ($certificate['in_renewal_window']) {
                ++$counts['in_renewal_window'];
            }
        }

        $tasksByStatus = array_fill_keys(TaskService::STATUSES, 0);
        foreach ($tasks as $task) {
            ++$tasksByStatus[$task['status']];
        }

        $lastContact = null;
        $sent = 0;
        $reminders = 0;
        foreach ($invitations as $invitation) {
            if ($invitation['sent_at'] !== null) {
                ++$sent;
            }
            $reminders += $invitation['reminder_count'];
            foreach ([$invitation['sent_at'], $invitation['last_reminder_at']] as $moment) {
                if ($moment !== null && ($lastContact === null || $moment > $lastContact)) {
                    $lastContact = $moment;
                }
            }
        }

        $renewals = count(array_filter(
            array_merge($active, $history),
            static fn (array $certificate): bool => $certificate['previous_certificate_id'] !== null
        ));

        return $counts + [
            'active_certificates'   => count($active),
            'archived_certificates' => count($history),
            'next_expiry'           => $next,
            'renewals'              => $renewals,
            'tasks_by_status'       => $tasksByStatus,
            'open_tasks'            => $tasksByStatus['todo'] + $tasksByStatus['in_progress'],
            'invitations_sent'      => $sent,
            'reminders_sent'        => $reminders,
            'last_contact_at'       => $lastContact,
        ];
    }

    /**
     * Suma kosztów w przeliczeniu na rok, osobno dla każdej waluty.
     *
     * @param list<array<string, mixed>> $certificates
     * @return list<array{currency: string, amount: float}>
     */
    private static function costTotals(array $certificates): array
    {
        $totals = [];
        foreach ($certificates as $certificate) {
            if ($certificate['archived_at'] !== null) {
                continue;
            }
            $currency = $certificate['currency'];
            $totals[$currency] = ($totals[$currency] ?? 0.0) + $certificate['annualized_cost'];
        }
        ksort($totals);

        $result = [];
        foreach ($totals as $currency => $amount) {
            $result[] = ['currency' => (string) $currency, 'amount' => round($amount, 2)];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $certificates
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function splitArchived(array $certificates): array
    {
        $active = [];
        $history = [];
        foreach ($certificates as $certificate) {
            if ($certificate['archived_at'] === null) {
                $active[] = $certificate;
            } else {
                $history[] = $certificate;
            }
        }

        return [$active, $history];
    }

    /**
     * @param array<string, int> $settings
     * @return array{critical: int, warning: int}
     */
    private static function thresholds(array $settings): array
    {
        return ['critical' => $settings['renewal.critical_days'], 'warning' => $settings['renewal.warning_days']];
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private static function inList(string $prefix, array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $name = $prefix . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->db->query('SELECT CURDATE()')->fetchColumn());
    }
}

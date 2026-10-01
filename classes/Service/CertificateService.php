<?php

declare(strict_types=1);

namespace App\Service;

use App\CertificateHelper;
use App\Rbac;
use PDO;
use PDOException;

/**
 * Ewidencja certyfikatów i usług (F1, F5): lista, szczegóły z historią, dodawanie, edycja,
 * archiwizacja i przywracanie.
 */
final class CertificateService
{
    /** Typy certyfikatów i usług ewidencji firmowej (D7). */
    public const TYPES = ['QUALIFIED_SIGNATURE', 'QUALIFIED_SEAL', 'SSL_CERTIFICATE', 'CODE_SIGNING', 'DOMAIN', 'SAAS', 'CLOUD_SUPPORT', 'OTHER'];
    /** Typy, które nie istnieją bez użytkownika certyfikatu (spójne z chk_certificates_qualified_user). */
    public const QUALIFIED_TYPES = ['QUALIFIED_SIGNATURE', 'QUALIFIED_SEAL'];
    public const STATUSES = ['pending', 'active', 'renewal_in_progress', 'expired'];
    public const BILLING_CYCLES = ['monthly', 'annual', 'multi_year'];
    public const PAYMENT_STATUSES = ['paid', 'due_soon', 'overdue', 'not_applicable'];

    /** @var list<string> */
    public const FIELDS = [
        'name', 'certificate_type', 'serial_number', 'issuer', 'valid_from', 'expiry_date', 'renewal_lead_days',
        'user_id', 'beneficiary_id', 'payer_id', 'status', 'discount_percent', 'billing_cycle',
        'payment_status', 'last_payment_date', 'auto_renew', 'notes',
    ];

    private readonly EventLogger $events;
    private readonly PayerService $payers;
    private readonly BeneficiaryService $beneficiaries;
    private readonly TimelineService $timeline;

    public function __construct(
        private readonly PDO $db,
        ?EventLogger $events = null,
        ?PayerService $payers = null,
        ?BeneficiaryService $beneficiaries = null,
    ) {
        $this->events = $events ?? new EventLogger($db);
        $this->payers = $payers ?? new PayerService($db, $this->events);
        $this->beneficiaries = $beneficiaries ?? new BeneficiaryService($db, $this->events, $this->payers);
        $this->timeline = new TimelineService($db);
    }

    /**
     * @param array{q?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, array $filters = [], bool $archived = false): array
    {
        $actor->authorize($archived ? 'archive.view' : 'certificates.view');

        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $conditions = [$archived ? 'c.archived_at IS NOT NULL' : 'c.archived_at IS NULL', $visibility];

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $conditions[] = "(c.name LIKE :q1 OR c.serial_number LIKE :q2 OR c.issuer LIKE :q3 OR p.company_name LIKE :q4
                OR CONCAT(COALESCE(b.first_name, ''), ' ', COALESCE(b.last_name, '')) LIKE :q5)";
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
        }

        $sql = self::selectSql() . ' WHERE ' . implode(' AND ', $conditions)
            . ($archived ? ' ORDER BY c.archived_at DESC' : ' ORDER BY c.expiry_date ASC, c.id ASC');

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return self::present($stmt->fetchAll());
    }

    /**
     * Szczegóły certyfikatu: dane, powiązania, łańcuch odnowień i historia zdarzeń.
     *
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $id): array
    {
        $actor->authorize('certificates.view');
        $certificate = $this->findVisible($actor, $id, true);

        $certificate['previous'] = $certificate['previous_certificate_id'] !== null
            ? $this->chainLink($actor, 'c.id = :link', (int) $certificate['previous_certificate_id'])
            : null;
        $certificate['next'] = $this->chainLink($actor, 'c.previous_certificate_id = :link', $id);
        $certificate['events'] = $this->timeline->forContext('certificate', $id);

        return $certificate;
    }

    /**
     * Dane do formularza: opiekunowie, osoby, płatnicy i słowniki.
     *
     * @return array<string, mixed>
     */
    public function formOptions(Actor $actor): array
    {
        $actor->authorize('certificates.view');

        if ($actor->can('certificates.assign_owner')) {
            $stmt = $this->db->query(
                'SELECT id, first_name, last_name, role FROM users WHERE deactivated_at IS NULL ORDER BY last_name, first_name'
            );
            $owners = array_map(static fn (array $row): array => [
                'id'   => (int) $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'role' => $row['role'],
            ], $stmt !== false ? $stmt->fetchAll() : []);
        } else {
            $owners = [['id' => $actor->id, 'name' => $actor->fullName(), 'role' => $actor->role]];
        }

        return [
            'owners'           => $owners,
            'beneficiaries'    => $this->beneficiaries->options($actor),
            'payers'           => $this->payers->options($actor),
            'types'            => array_values(array_filter(
                self::TYPES,
                static fn (string $type): bool => Rbac::canManageCertificateType($actor->role, $type)
            )),
            'statuses'         => self::STATUSES,
            'billing_cycles'   => self::BILLING_CYCLES,
            'payment_statuses' => self::PAYMENT_STATUSES,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $actor->authorize('certificates.create');
        $values = $this->validate($actor, $data, null);
        $this->assertSerialAvailable($actor, $values['issuer'], $values['serial_number'], null);

        try {
            $id = Transaction::run($this->db, function () use ($actor, $values): int {
                $stmt = $this->db->prepare(
                    'INSERT INTO certificates (name, certificate_type, serial_number, issuer, valid_from, expiry_date,
                        renewal_lead_days, user_id, beneficiary_id, payer_id, status, discount_percent, billing_cycle,
                        payment_status, last_payment_date, auto_renew, notes)
                     VALUES (:name, :certificate_type, :serial_number, :issuer, :valid_from, :expiry_date,
                        :renewal_lead_days, :user_id, :beneficiary_id, :payer_id, :status, :discount_percent, :billing_cycle,
                        :payment_status, :last_payment_date, :auto_renew, :notes)'
                );
                $stmt->execute(self::bindable($values));
                $id = (int) $this->db->lastInsertId();

                $this->events->log('certificate', $id, 'created', $actor->id, self::context($id, $values), [
                    'name'             => $values['name'],
                    'certificate_type' => $values['certificate_type'],
                    'expiry_date'      => $values['expiry_date'],
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            throw $this->translateDuplicate($e, $actor, $values);
        }

        return $this->get($actor, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('certificates.update');
        $before = $this->findVisible($actor, $id, false);
        $values = $this->validate($actor, $data, $before);
        $this->assertSerialAvailable($actor, $values['issuer'], $values['serial_number'], $id);

        $changes = EventLogger::diff($before, $values, self::FIELDS);
        if ($changes === []) {
            return $this->get($actor, $id);
        }

        try {
            Transaction::run($this->db, function () use ($actor, $id, $values, $changes): void {
                $stmt = $this->db->prepare(
                    'UPDATE certificates SET name = :name, certificate_type = :certificate_type, serial_number = :serial_number,
                        issuer = :issuer, valid_from = :valid_from, expiry_date = :expiry_date, renewal_lead_days = :renewal_lead_days,
                        user_id = :user_id, beneficiary_id = :beneficiary_id, payer_id = :payer_id, status = :status,
                        discount_percent = :discount_percent, billing_cycle = :billing_cycle,
                        payment_status = :payment_status, last_payment_date = :last_payment_date, auto_renew = :auto_renew,
                        notes = :notes
                     WHERE id = :id'
                );
                $stmt->execute(self::bindable($values) + ['id' => $id]);
                $this->events->log('certificate', $id, 'updated', $actor->id, self::context($id, $values), ['changes' => $changes]);
            });
        } catch (PDOException $e) {
            throw $this->translateDuplicate($e, $actor, $values);
        }

        return $this->get($actor, $id);
    }

    /**
     * Zmiana samych danych płatności (status, data ostatniej płatności, rabat) — dla księgowej,
     * która nie edytuje reszty rekordu. Osoby z pełnym prawem edycji też mogą z niej skorzystać.
     *
     * @param array<string, mixed> $data payment_status, last_payment_date, discount_percent
     * @return array<string, mixed>
     */
    public function updatePayment(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('certificates.update_payment');
        $before = $this->findVisible($actor, $id, false);

        $v = new Validator($data);
        $values = [
            'payment_status'    => (string) $v->enum('payment_status', true, self::PAYMENT_STATUSES),
            'last_payment_date' => $v->date('last_payment_date', false),
            'discount_percent'  => $v->discountPercent('discount_percent'),
        ];
        $v->throwIfFailed();

        $changes = EventLogger::diff($before, $values, ['payment_status', 'last_payment_date', 'discount_percent']);
        if ($changes === []) {
            return $this->get($actor, $id);
        }

        Transaction::run($this->db, function () use ($actor, $id, $before, $values, $changes): void {
            $this->db->prepare(
                'UPDATE certificates SET payment_status = :payment_status, last_payment_date = :last_payment_date,
                        discount_percent = :discount_percent
                 WHERE id = :id'
            )->execute([
                'payment_status'    => $values['payment_status'],
                'last_payment_date' => $values['last_payment_date'],
                'discount_percent'  => number_format((float) $values['discount_percent'], 2, '.', ''),
                'id'                => $id,
            ]);
            $this->events->log('certificate', $id, 'updated', $actor->id, self::context($id, $before), [
                'changes' => $changes,
                'scope'   => 'payment',
            ]);
        });

        return $this->get($actor, $id);
    }

    /**
     * Archiwizacja zamiast usuwania. Otwarte zadanie odnowienia archiwizowanego certyfikatu
     * zostaje zamknięte jako porzucone, żeby nie wisiało na liście ToDo.
     *
     * @return array<string, mixed>
     */
    public function archive(Actor $actor, int $id): array
    {
        $actor->authorize('certificates.archive');
        $certificate = $this->findVisible($actor, $id, false);

        Transaction::run($this->db, function () use ($actor, $id, $certificate): void {
            $this->db->prepare('UPDATE certificates SET archived_at = NOW() WHERE id = :id')->execute(['id' => $id]);
            $context = self::context($id, $certificate);
            $this->events->log('certificate', $id, 'archived', $actor->id, $context, ['name' => $certificate['name']]);

            $stmt = $this->db->prepare(
                "SELECT id, status FROM renewal_tasks WHERE certificate_id = :id AND status IN ('todo', 'in_progress')"
            );
            $stmt->execute(['id' => $id]);
            foreach ($stmt->fetchAll() as $task) {
                $this->db->prepare(
                    "UPDATE renewal_tasks SET status = 'abandoned', closed_at = NOW(),
                        resolution_note = COALESCE(resolution_note, :note)
                     WHERE id = :task_id"
                )->execute(['note' => \__('task.note.certificate_archived'), 'task_id' => (int) $task['id']]);
                $this->events->log('renewal_task', (int) $task['id'], 'task_closed', $actor->id, $context, [
                    'from'   => $task['status'],
                    'status' => 'abandoned',
                    'reason' => 'certificate_archived',
                ]);
            }
        });

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(Actor $actor, int $id): array
    {
        $actor->authorize('certificates.archive');
        $certificate = $this->findVisible($actor, $id, true);
        if ($certificate['archived_at'] === null) {
            throw ServiceException::conflict(\__('common.error.not_archived'));
        }

        if ($certificate['payer_archived_at'] !== null) {
            throw ServiceException::conflict(\__('certificate.error.restore_payer_archived', [
                'payer' => (string) $certificate['company_name'],
            ]));
        }

        if ($certificate['beneficiary_id'] !== null && $certificate['beneficiary_archived_at'] !== null) {
            throw ServiceException::conflict(\__('certificate.error.restore_beneficiary_archived', [
                'beneficiary' => (string) $certificate['beneficiary_name'],
            ]));
        }

        Transaction::run($this->db, function () use ($actor, $id, $certificate): void {
            $this->db->prepare('UPDATE certificates SET archived_at = NULL WHERE id = :id')->execute(['id' => $id]);
            $this->events->log('certificate', $id, 'restored', $actor->id, self::context($id, $certificate), [
                'name' => $certificate['name'],
            ]);
        });

        return $this->get($actor, $id);
    }

    /**
     * Bieżący (niezarchiwizowany) certyfikat widoczny dla konta — do użycia przez inne usługi.
     *
     * @return array<string, mixed>
     */
    public function findActive(Actor $actor, int $id): array
    {
        return $this->findVisible($actor, $id, false);
    }

    /**
     * Wynik odnowienia (cykl życia z §2.1): nowy certyfikat z tymi samymi powiązaniami i nową
     * ważnością zastępuje poprzedni, który trafia do archiwum. Łańcuch tworzy previous_certificate_id.
     *
     * Wywołujący odpowiada za transakcję i zamknięcie zadania odnowienia.
     *
     * @param array<string, mixed> $data expiry_date (wymagana), valid_from, serial_number, issuer,
     *                                   discount_percent, payment_status, last_payment_date, notes, name
     */
    public function renewFrom(Actor $actor, int $oldId, array $data): int
    {
        $actor->authorize('certificates.create');
        $old = $this->findVisible($actor, $oldId, false);

        $pick = static fn (string $key, mixed $fallback): mixed => array_key_exists($key, $data) && $data[$key] !== null ? $data[$key] : $fallback;
        $merged = [
            'name'              => $pick('name', $old['name']),
            'certificate_type'  => $old['certificate_type'],
            'serial_number'     => $pick('serial_number', null),
            'issuer'            => $pick('issuer', $old['issuer']),
            'valid_from'        => $pick('valid_from', null),
            'expiry_date'       => $pick('expiry_date', null),
            'renewal_lead_days' => $old['renewal_lead_days'],
            'user_id'           => $old['user_id'],
            'beneficiary_id'    => $old['beneficiary_id'],
            'payer_id'          => $old['payer_id'],
            'status'            => 'active',
            'discount_percent'  => $pick('discount_percent', $old['discount_percent']),
            'billing_cycle'     => $old['billing_cycle'],
            'payment_status'    => $pick('payment_status', 'paid'),
            'last_payment_date' => $pick('last_payment_date', null),
            'auto_renew'        => $old['auto_renew'],
            'notes'             => $pick('notes', null),
        ];

        $expiry = is_string($merged['expiry_date']) ? trim($merged['expiry_date']) : '';
        if ($expiry !== '' && Validator::isValidDate($expiry) && $expiry <= (string) $old['expiry_date']) {
            throw ServiceException::validation(['expiry_date' => \__('certificate.error.renewal_expiry', [
                'date' => (string) $old['expiry_date'],
            ])]);
        }

        // Stan poprzedni = stary certyfikat, więc zachowane powiązania i opiekun przechodzą walidację.
        $values = $this->validate($actor, $merged, $old);
        $this->assertSerialAvailable($actor, $values['issuer'], $values['serial_number'], null);

        return Transaction::run($this->db, function () use ($actor, $old, $oldId, $values): int {
            try {
                $stmt = $this->db->prepare(
                    'INSERT INTO certificates (name, certificate_type, serial_number, issuer, valid_from, expiry_date,
                        renewal_lead_days, user_id, beneficiary_id, payer_id, previous_certificate_id, status, discount_percent,
                        billing_cycle, payment_status, last_payment_date, auto_renew, notes)
                     VALUES (:name, :certificate_type, :serial_number, :issuer, :valid_from, :expiry_date,
                        :renewal_lead_days, :user_id, :beneficiary_id, :payer_id, :previous_certificate_id, :status, :discount_percent,
                        :billing_cycle, :payment_status, :last_payment_date, :auto_renew, :notes)'
                );
                $stmt->execute(self::bindable($values) + ['previous_certificate_id' => $oldId]);
            } catch (PDOException $e) {
                throw $this->translateDuplicate($e, $actor, $values);
            }
            $newId = (int) $this->db->lastInsertId();

            $this->db->prepare('UPDATE certificates SET archived_at = NOW() WHERE id = :id')->execute(['id' => $oldId]);

            $this->events->log('certificate', $oldId, 'renewed', $actor->id, self::context($oldId, $old), [
                'new_certificate_id' => $newId,
                'new_expiry_date'    => $values['expiry_date'],
            ]);
            $this->events->log('certificate', $newId, 'created', $actor->id, self::context($newId, $values), [
                'name'                    => $values['name'],
                'certificate_type'        => $values['certificate_type'],
                'expiry_date'             => $values['expiry_date'],
                'previous_certificate_id' => $oldId,
                'source'                  => 'renewal',
            ]);
            $this->events->log('certificate', $oldId, 'archived', $actor->id, self::context($oldId, $old), [
                'name'   => $old['name'],
                'reason' => 'renewed',
            ]);

            return $newId;
        });
    }

    /**
     * Status certyfikatu podąża za zadaniem odnowienia: praca nad zadaniem to „odnowienie w toku”,
     * a jego przerwanie przywraca status wynikający z daty wygaśnięcia.
     */
    public function syncStatusWithTask(Actor $actor, int $certificateId, string $taskStatus): void
    {
        $stmt = $this->db->prepare(
            'SELECT id, status, expiry_date, beneficiary_id, payer_id FROM certificates
             WHERE id = :id AND archived_at IS NULL'
        );
        $stmt->execute(['id' => $certificateId]);
        $certificate = $stmt->fetch();
        if ($certificate === false) {
            return;
        }

        $current = (string) $certificate['status'];
        $target = $current;
        if ($taskStatus === 'in_progress' && in_array($current, ['active', 'pending', 'expired'], true)) {
            $target = 'renewal_in_progress';
        } elseif ($taskStatus !== 'in_progress' && $current === 'renewal_in_progress') {
            $target = (string) $certificate['expiry_date'] < date('Y-m-d') ? 'expired' : 'active';
        }

        if ($target === $current) {
            return;
        }

        $this->db->prepare('UPDATE certificates SET status = :status WHERE id = :id')
            ->execute(['status' => $target, 'id' => $certificateId]);
        $this->events->log('certificate', $certificateId, 'updated', $actor->id > 0 ? $actor->id : null, self::context($certificateId, $certificate), [
            'changes' => ['status' => ['from' => $current, 'to' => $target]],
            'reason'  => 'task_' . $taskStatus,
        ]);
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $before
     * @return array<string, mixed>
     */
    public function validate(Actor $actor, array $data, ?array $before): array
    {
        $v = new Validator($data);
        $values = [
            'name'              => (string) $v->string('name', true, 255),
            'certificate_type'  => (string) $v->enum('certificate_type', true, self::TYPES),
            'serial_number'     => $v->string('serial_number', false, 128),
            'issuer'            => $v->string('issuer', false, 255),
            'valid_from'        => $v->date('valid_from', false),
            'expiry_date'       => (string) $v->date('expiry_date', true),
            'renewal_lead_days' => $v->int('renewal_lead_days', false, 0, 3650),
            'beneficiary_id'    => $v->id('beneficiary_id', false),
            'payer_id'          => $v->id('payer_id', false),
            'status'            => (string) $v->enum('status', false, self::STATUSES, 'active'),
            'discount_percent'  => $v->discountPercent('discount_percent'),
            'billing_cycle'     => (string) $v->enum('billing_cycle', false, self::BILLING_CYCLES, 'annual'),
            'payment_status'    => (string) $v->enum('payment_status', false, self::PAYMENT_STATUSES, 'paid'),
            'last_payment_date' => $v->date('last_payment_date', false),
            'auto_renew'        => $v->bool('auto_renew', false),
            'notes'             => $v->string('notes', false, 5000),
        ];

        // Opiekun rekordu: OPERATOR zakłada certyfikaty na siebie i nie przekazuje ich innym.
        $currentOwner = $before !== null ? (int) $before['user_id'] : null;
        if ($actor->can('certificates.assign_owner')) {
            $owner = $v->id('user_id', false) ?? $currentOwner ?? $actor->id;
            if ($owner !== $currentOwner && !$this->isActiveAccount($owner)) {
                $v->addError('user_id', 'certificate.error.owner_unavailable');
            }
        } else {
            $owner = $currentOwner ?? $actor->id;
        }
        $values['user_id'] = $owner;

        if ($values['valid_from'] !== null && $values['expiry_date'] !== '' && $values['valid_from'] > $values['expiry_date']) {
            $v->addError('valid_from', 'validation.date_order');
        }

        // Informatyk obsługuje tylko certyfikaty techniczne — kwalifikowane zakładają i zmieniają inne role.
        if (!Rbac::canManageCertificateType($actor->role, $values['certificate_type'])) {
            $v->addError('certificate_type', 'certificate.error.type_forbidden');
        }

        // Certyfikat kwalifikowany zawsze ma użytkownika, a użytkownik zawsze ma firmę.
        if ($values['beneficiary_id'] === null && in_array($values['certificate_type'], self::QUALIFIED_TYPES, true)) {
            $v->addError('beneficiary_id', 'certificate.error.beneficiary_required');
        }

        $beneficiary = null;
        if ($values['beneficiary_id'] !== null) {
            $beneficiary = $this->beneficiaries->findActiveVisible($actor, $values['beneficiary_id']);
            $unchanged = $before !== null && $values['beneficiary_id'] === ($before['beneficiary_id'] ?? null);
            if ($beneficiary === null && !$unchanged) {
                $v->addError('beneficiary_id', 'certificate.error.beneficiary_unavailable');
            }
        }

        // Płatnik domyślnie przechodzi z użytkownika certyfikatu, ale certyfikat może opłacać inny podmiot.
        if ($values['payer_id'] === null && $beneficiary !== null && $beneficiary['payer_id'] !== null) {
            $values['payer_id'] = (int) $beneficiary['payer_id'];
        }

        if ($values['payer_id'] === null) {
            $v->addError('payer_id', 'validation.required');
        } else {
            $unchanged = $before !== null && $values['payer_id'] === ($before['payer_id'] ?? null);
            if (!$unchanged && $this->payers->findActiveVisible($actor, $values['payer_id']) === null) {
                $v->addError('payer_id', 'certificate.error.payer_unavailable');
            }
        }

        $v->throwIfFailed();

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function findVisible(Actor $actor, int $id, bool $includeArchived): array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(
            self::selectSql() . " WHERE c.id = :id AND {$visibility} LIMIT 1"
        );
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ServiceException::notFound(\__('certificate.error.not_found'));
        }

        if ($row['archived_at'] !== null && (!$includeArchived || !$actor->can('archive.view'))) {
            throw $includeArchived
                ? ServiceException::notFound(\__('certificate.error.not_found'))
                : ServiceException::conflict(\__('common.error.archived_readonly'));
        }

        return self::present([$row])[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chainLink(Actor $actor, string $condition, int $linkId): ?array
    {
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, c.serial_number, c.valid_from, c.expiry_date, c.archived_at
             FROM certificates c WHERE {$condition} AND {$visibility} ORDER BY c.id DESC LIMIT 1"
        );
        $stmt->execute(['link' => $linkId] + $params);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['id'] = (int) $row['id'];

        return $row;
    }

    /**
     * Numer seryjny jest unikalny u danego wystawcy (klucz uq_certificates_issuer_serial).
     */
    private function assertSerialAvailable(Actor $actor, ?string $issuer, ?string $serial, ?int $exceptId): void
    {
        if ($issuer === null || $serial === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id, name FROM certificates WHERE issuer = :issuer AND serial_number = :serial AND id <> :id LIMIT 1'
        );
        $stmt->execute(['issuer' => $issuer, 'serial' => $serial, 'id' => $exceptId ?? 0]);
        $existing = $stmt->fetch();
        if ($existing !== false) {
            throw $this->serialConflict($actor, $existing);
        }
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function serialConflict(Actor $actor, array $existing): ServiceException
    {
        $errors = ['serial_number' => \__('certificate.error.serial_taken_short')];
        [$visibility, $params] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare("SELECT 1 FROM certificates c WHERE c.id = :id AND {$visibility} LIMIT 1");
        $stmt->execute(['id' => (int) $existing['id']] + $params);

        if ($stmt->fetchColumn() === false) {
            return ServiceException::conflict(\__('certificate.error.serial_taken_hidden'), [], $errors);
        }

        return ServiceException::conflict(
            \__('certificate.error.serial_taken', ['name' => (string) $existing['name']]),
            ['existing' => ['id' => (int) $existing['id'], 'name' => $existing['name']]],
            $errors
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function translateDuplicate(PDOException $e, Actor $actor, array $values): \Throwable
    {
        if (($e->errorInfo[1] ?? null) !== 1062 || $values['issuer'] === null || $values['serial_number'] === null) {
            return $e;
        }

        $stmt = $this->db->prepare('SELECT id, name FROM certificates WHERE issuer = :issuer AND serial_number = :serial LIMIT 1');
        $stmt->execute(['issuer' => $values['issuer'], 'serial' => $values['serial_number']]);
        $existing = $stmt->fetch();

        return $existing === false ? $e : $this->serialConflict($actor, $existing);
    }

    private function isActiveAccount(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE id = :id AND deactivated_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    private static function selectSql(): string
    {
        return 'SELECT
                c.id, c.name, c.certificate_type, c.serial_number, c.issuer, c.valid_from, c.expiry_date,
                c.renewal_lead_days, c.status, c.discount_percent, c.billing_cycle, c.payment_status,
                c.last_payment_date, c.auto_renew, c.notes, c.user_id, c.beneficiary_id, c.payer_id,
                c.previous_certificate_id, c.archived_at, c.created_at, c.updated_at,
                u.first_name AS user_first_name, u.last_name AS user_last_name, u.role AS user_role, u.email AS user_email,
                b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name, b.email AS beneficiary_email,
                b.archived_at AS beneficiary_archived_at,
                p.company_name, p.contact_person, p.email AS payer_email, p.tax_id AS payer_tax_id, p.archived_at AS payer_archived_at
            FROM certificates c
            INNER JOIN users u ON u.id = c.user_id
            INNER JOIN payers p ON p.id = c.payer_id
            LEFT JOIN beneficiaries b ON b.id = c.beneficiary_id';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function present(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (['id', 'user_id', 'payer_id'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            foreach (['beneficiary_id', 'previous_certificate_id', 'renewal_lead_days'] as $key) {
                $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
            }
            $row['auto_renew'] = (bool) $row['auto_renew'];
        }
        unset($row);

        return array_values(CertificateHelper::enrich($rows));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function bindable(array $values): array
    {
        $values['auto_renew'] = $values['auto_renew'] ? 1 : 0;
        $values['discount_percent'] = number_format((float) $values['discount_percent'], 2, '.', '');

        return array_intersect_key($values, array_flip(self::FIELDS));
    }

    /**
     * @param array<string, mixed> $values
     * @return array{certificate_id: int, beneficiary_id: ?int, payer_id: ?int}
     */
    private static function context(int $id, array $values): array
    {
        return [
            'certificate_id' => $id,
            'beneficiary_id' => isset($values['beneficiary_id']) ? (int) $values['beneficiary_id'] : null,
            'payer_id'       => isset($values['payer_id']) ? (int) $values['payer_id'] : null,
        ];
    }
}

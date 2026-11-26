<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Ewidencja użytkowników certyfikatów — beneficjentów (F2, F4, F5). To osoby, których dotyczy
 * certyfikat, a nie konta logowania. Beneficjent jest powiązany z płatnikiem, ale nim nie jest.
 */
final class BeneficiaryService
{
    /** @var list<string> */
    public const FIELDS = ['first_name', 'last_name', 'email', 'phone', 'payer_id', 'notes'];

    private readonly EventLogger $events;
    private readonly PayerService $payers;
    private readonly CompanyHistory $history;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null, ?PayerService $payers = null)
    {
        $this->events = $events ?? new EventLogger($db);
        $this->payers = $payers ?? new PayerService($db, $this->events);
        $this->history = new CompanyHistory($db);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, bool $archived = false): array
    {
        $actor->authorize($archived ? 'archive.view' : 'beneficiaries.view');

        [$beneficiaryVisibility, $params] = Visibility::beneficiaries($actor, 'b');
        [$certificateVisibility, $certificateParams] = Visibility::certificates($actor, 'c');
        [$company, $companyParams] = Visibility::personCompanyFilter($actor, 'b');
        $archivedCondition = $archived ? 'b.archived_at IS NOT NULL' : 'b.archived_at IS NULL';

        $sql = "
            SELECT
                b.id, b.first_name, b.last_name, b.email, b.phone, b.payer_id, b.notes,
                b.archived_at, b.created_at, b.updated_at,
                p.company_name AS payer_name,
                p.tax_id AS payer_tax_id,
                p.archived_at AS payer_archived_at,
                COALESCE(cs.certificate_count, 0) AS certificate_count,
                cs.earliest_expiry,
                (SELECT GROUP_CONCAT(ep.company_name ORDER BY ep.company_name SEPARATOR '; ')
                 FROM beneficiary_employments ee INNER JOIN payers ep ON ep.id = ee.payer_id
                 WHERE ee.beneficiary_id = b.id AND ee.ended_on IS NULL AND ee.payer_id <> b.payer_id) AS other_companies,
                (SELECT GROUP_CONCAT(DISTINCT xp.company_name ORDER BY xp.company_name SEPARATOR '; ')
                 FROM beneficiary_employments xe INNER JOIN payers xp ON xp.id = xe.payer_id
                 WHERE xe.beneficiary_id = b.id AND xe.ended_on IS NOT NULL) AS former_companies
            FROM beneficiaries b
            LEFT JOIN payers p ON p.id = b.payer_id
            LEFT JOIN (
                SELECT c.beneficiary_id, COUNT(*) AS certificate_count, MIN(c.expiry_date) AS earliest_expiry
                FROM certificates c
                WHERE c.archived_at IS NULL AND {$certificateVisibility}
                GROUP BY c.beneficiary_id
            ) cs ON cs.beneficiary_id = b.id
            WHERE {$archivedCondition} AND {$beneficiaryVisibility} AND {$company}
            ORDER BY " . ($archived ? 'b.archived_at DESC' : 'b.last_name, b.first_name') . '
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params + $certificateParams + $companyParams);

        return array_map([self::class, 'castRow'], $stmt->fetchAll());
    }

    /**
     * @return list<array{id: int, first_name: string, last_name: string, email: ?string, payer_id: ?int}>
     */
    public function options(Actor $actor): array
    {
        $actor->authorize('beneficiaries.view');
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');

        $stmt = $this->db->prepare(
            "SELECT b.id, b.first_name, b.last_name, b.email, b.payer_id
             FROM beneficiaries b
             WHERE b.archived_at IS NULL AND {$visibility}
             ORDER BY b.last_name, b.first_name"
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name'  => (string) $row['last_name'],
            'email'      => $row['email'],
            'payer_id'   => $row['payer_id'] !== null ? (int) $row['payer_id'] : null,
        ], $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $id): array
    {
        $actor->authorize('beneficiaries.view');
        $beneficiary = $this->findVisible($actor, $id, true);

        [$certificateVisibility, $certificateParams] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, c.certificate_type, c.serial_number, c.issuer, c.valid_from, c.expiry_date,
                    c.renewal_lead_days, c.status, c.payer_id, p.company_name AS payer_name
             FROM certificates c
             INNER JOIN payers p ON p.id = c.payer_id
             WHERE c.beneficiary_id = :beneficiary_id AND c.archived_at IS NULL AND {$certificateVisibility}
             ORDER BY c.expiry_date"
        );
        $stmt->execute(['beneficiary_id' => $id] + $certificateParams);
        $beneficiary['certificates'] = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['payer_id'] = (int) $row['payer_id'];

            return $row;
        }, $stmt->fetchAll());
        $beneficiary['employments'] = $this->history->employments($id);

        return $beneficiary;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $actor->authorize('beneficiaries.create');
        $values = $this->validate($actor, $data);

        $id = Transaction::run($this->db, function () use ($actor, $values): int {
            $stmt = $this->db->prepare(
                'INSERT INTO beneficiaries (first_name, last_name, email, phone, payer_id, notes, created_by_user_id)
                 VALUES (:first_name, :last_name, :email, :phone, :payer_id, :notes, :created_by_user_id)'
            );
            $stmt->execute($values + ['created_by_user_id' => $actor->id]);
            $id = (int) $this->db->lastInsertId();
            $this->history->startEmployment($id, (int) $values['payer_id'], CompanyHistory::today(), null, $actor->id);

            $this->events->log('beneficiary', $id, 'created', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $values['payer_id'],
            ], ['name' => trim($values['first_name'] . ' ' . $values['last_name'])]);

            return $id;
        });

        return $this->get($actor, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('beneficiaries.update');
        $before = $this->findVisible($actor, $id, false);
        $values = $this->validate($actor, $data, $before);

        $changes = EventLogger::diff($before, $values, self::FIELDS);
        if ($changes === []) {
            return $this->get($actor, $id);
        }

        Transaction::run($this->db, function () use ($actor, $id, $before, $values, $changes): void {
            // Zwykła edycja poprawia pomyłkę: otwarty okres dawnej firmy dostaje nową firmę (bez nowego okresu).
            if ($values['payer_id'] !== null && $values['payer_id'] !== $before['payer_id']) {
                $this->history->correctOpenEmploymentPayer($id, (int) $before['payer_id'], $values['payer_id'], $actor->id);
            }
            $stmt = $this->db->prepare(
                'UPDATE beneficiaries SET first_name = :first_name, last_name = :last_name, email = :email,
                        phone = :phone, payer_id = :payer_id, notes = :notes
                 WHERE id = :id'
            );
            $stmt->execute($values + ['id' => $id]);
            $this->events->log('beneficiary', $id, 'updated', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $values['payer_id'],
            ], ['changes' => $changes]);
        });

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(Actor $actor, int $id): array
    {
        $actor->authorize('beneficiaries.archive');
        $beneficiary = $this->findVisible($actor, $id, false);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM certificates WHERE beneficiary_id = :id AND archived_at IS NULL');
        $stmt->execute(['id' => $id]);
        $certificates = (int) $stmt->fetchColumn();
        if ($certificates > 0) {
            throw ServiceException::conflict(
                \__('beneficiary.error.archive_blocked', ['certificates' => (string) $certificates]),
                ['certificates' => $certificates]
            );
        }

        Transaction::run($this->db, function () use ($actor, $id, $beneficiary): void {
            $this->db->prepare('UPDATE beneficiaries SET archived_at = NOW() WHERE id = :id')->execute(['id' => $id]);
            $this->history->endAllEmployments($id, CompanyHistory::today());
            $this->events->log('beneficiary', $id, 'archived', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $beneficiary['payer_id'],
            ], ['name' => trim($beneficiary['first_name'] . ' ' . $beneficiary['last_name'])]);
        });

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(Actor $actor, int $id): array
    {
        $actor->authorize('beneficiaries.archive');
        $beneficiary = $this->findVisible($actor, $id, true);
        if ($beneficiary['archived_at'] === null) {
            throw ServiceException::conflict(\__('common.error.not_archived'));
        }

        if ($beneficiary['payer_id'] !== null && $beneficiary['payer_archived_at'] !== null) {
            throw ServiceException::conflict(\__('beneficiary.error.restore_payer_archived', [
                'payer' => (string) $beneficiary['payer_name'],
            ]));
        }

        Transaction::run($this->db, function () use ($actor, $id, $beneficiary): void {
            $this->db->prepare('UPDATE beneficiaries SET archived_at = NULL WHERE id = :id')->execute(['id' => $id]);
            if ($beneficiary['payer_id'] !== null) {
                $this->history->ensureEmployment($id, (int) $beneficiary['payer_id'], CompanyHistory::today(), null, $actor->id);
            }
            $this->events->log('beneficiary', $id, 'restored', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $beneficiary['payer_id'],
            ], ['name' => trim($beneficiary['first_name'] . ' ' . $beneficiary['last_name'])]);
        });

        return $this->get($actor, $id);
    }

    /**
     * Przeniesienie do innej firmy (przycisk „Przenieś”): okres w dotychczasowej firmie (domyślnie głównej)
     * kończy się w podanym dniu, a od tego dnia zaczyna się okres w nowej firmie, która staje się główna.
     * Certyfikaty zostają przy osobie i nie są archiwizowane; na życzenie (transfer_payer) certyfikaty
     * opłacane przez dawną firmę dostają nowego płatnika — z zapisem w historii płatników.
     *
     * @param array<string, mixed> $data payer_id, from_payer_id?, date?, note?, transfer_payer?
     * @return array<string, mixed>
     */
    public function moveCompany(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('beneficiaries.update');
        $person = $this->findVisible($actor, $id, false);

        $v = new Validator($data);
        $payerId = $v->id('payer_id', true);
        $fromPayerId = $v->id('from_payer_id', false) ?? (int) $person['payer_id'];
        $date = $v->date('date', false) ?? CompanyHistory::today();
        $note = $v->string('note', false, 500);
        $transfer = $v->bool('transfer_payer', false);
        if ($payerId !== null && $payerId === $fromPayerId) {
            $v->addError('payer_id', 'beneficiary.error.same_company');
        } elseif ($payerId !== null && $this->payers->findActiveVisible($actor, $payerId) === null) {
            $v->addError('payer_id', 'beneficiary.error.payer_unavailable');
        }
        $from = $this->history->openEmployment($id, $fromPayerId);
        if ($from !== null && $date < (string) $from['started_on']) {
            $v->addError('date', 'beneficiary.error.date_before_start');
        }
        $v->throwIfFailed();
        $payerId = (int) $payerId;

        Transaction::run($this->db, function () use ($actor, $id, $person, $payerId, $fromPayerId, $from, $date, $note, $transfer): void {
            if ($from !== null) {
                $this->history->endEmployment((int) $from['id'], $date);
            }
            $this->history->ensureEmployment($id, $payerId, $date, $note, $actor->id);
            $this->history->setPrimaryPayer($id, $payerId);

            $moved = 0;
            if ($transfer) {
                $stmt = $this->db->prepare(
                    'SELECT id FROM certificates WHERE beneficiary_id = :id AND payer_id = :payer_id AND archived_at IS NULL'
                );
                $stmt->execute(['id' => $id, 'payer_id' => $fromPayerId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $certificateId) {
                    $certificateId = (int) $certificateId;
                    $this->db->prepare('UPDATE certificates SET payer_id = :payer_id WHERE id = :id')
                        ->execute(['payer_id' => $payerId, 'id' => $certificateId]);
                    $this->history->recordCertificatePayer($certificateId, $payerId, $date, $note, $actor->id);
                    $this->events->log('certificate', $certificateId, 'payer_changed', $actor->id, [
                        'certificate_id' => $certificateId,
                        'beneficiary_id' => $id,
                        'payer_id'       => $payerId,
                    ], [
                        'from'   => $this->payerName($fromPayerId),
                        'to'     => $this->payerName($payerId),
                        'date'   => $date,
                        'reason' => 'company_moved',
                    ]);
                    $moved++;
                }
            }

            $this->events->log('beneficiary', $id, 'company_moved', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $payerId,
            ], array_filter([
                'name'         => trim($person['first_name'] . ' ' . $person['last_name']),
                'from'         => $this->payerName($fromPayerId),
                'to'           => $this->payerName($payerId),
                'date'         => $date,
                'note'         => $note,
                'certificates' => $moved > 0 ? $moved : null,
            ], static fn (mixed $value): bool => $value !== null));
        });

        return $this->get($actor, $id);
    }

    /**
     * Dodatkowa firma (przycisk „Dodaj firmę”): osoba pracuje równolegle w kilku firmach.
     *
     * @param array<string, mixed> $data payer_id, date?, note?, primary?
     * @return array<string, mixed>
     */
    public function addCompany(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('beneficiaries.update');
        $person = $this->findVisible($actor, $id, false);

        $v = new Validator($data);
        $payerId = $v->id('payer_id', true);
        $date = $v->date('date', false) ?? CompanyHistory::today();
        $note = $v->string('note', false, 500);
        $primary = $v->bool('primary', false);
        if ($payerId !== null && $this->history->openEmployment($id, $payerId) !== null) {
            $v->addError('payer_id', 'beneficiary.error.company_already_open');
        } elseif ($payerId !== null && $this->payers->findActiveVisible($actor, $payerId) === null) {
            $v->addError('payer_id', 'beneficiary.error.payer_unavailable');
        }
        $v->throwIfFailed();
        $payerId = (int) $payerId;

        Transaction::run($this->db, function () use ($actor, $id, $person, $payerId, $date, $note, $primary): void {
            $this->history->startEmployment($id, $payerId, $date, $note, $actor->id);
            if ($primary) {
                $this->history->setPrimaryPayer($id, $payerId);
            }
            $this->events->log('beneficiary', $id, 'company_added', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $payerId,
            ], array_filter([
                'name'    => trim($person['first_name'] . ' ' . $person['last_name']),
                'to'      => $this->payerName($payerId),
                'date'    => $date,
                'note'    => $note,
                'primary' => $primary ? true : null,
            ], static fn (mixed $value): bool => $value !== null));
        });

        return $this->get($actor, $id);
    }

    /**
     * Zakończenie pracy w firmie (przycisk „Zakończ”). Certyfikaty zostają przy osobie. Ostatniej firmy
     * nie da się zakończyć — osoba bez firmy nie istnieje; wtedy przenosi się ją albo archiwizuje.
     *
     * @param array<string, mixed> $data employment_id, date?, note?
     * @return array<string, mixed>
     */
    public function endCompany(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('beneficiaries.update');
        $person = $this->findVisible($actor, $id, false);

        $v = new Validator($data);
        $employmentId = $v->id('employment_id', true);
        $date = $v->date('date', false) ?? CompanyHistory::today();
        $note = $v->string('note', false, 500);
        $v->throwIfFailed();

        $employment = $this->history->employment((int) $employmentId);
        if ($employment === null || $employment['beneficiary_id'] !== $id) {
            throw ServiceException::notFound(\__('beneficiary.error.employment_not_found'));
        }
        if (!$employment['is_current']) {
            throw ServiceException::conflict(\__('beneficiary.error.employment_closed'));
        }
        if ($date < (string) $employment['started_on']) {
            throw ServiceException::validation(['date' => \__('beneficiary.error.date_before_start')]);
        }

        $others = array_values(array_filter(
            $this->history->openPayerIds($id),
            static fn (int $payerId): bool => $payerId !== $employment['payer_id']
        ));
        if ($others === []) {
            throw ServiceException::conflict(\__('beneficiary.error.last_company'));
        }

        Transaction::run($this->db, function () use ($actor, $id, $person, $employment, $others, $date, $note): void {
            $this->history->endEmployment($employment['id'], $date, $note);
            $newPrimary = null;
            if ($employment['payer_id'] === (int) $person['payer_id']) {
                $newPrimary = $others[0];
                $this->history->setPrimaryPayer($id, $newPrimary);
            }
            $this->events->log('beneficiary', $id, 'company_ended', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $employment['payer_id'],
            ], array_filter([
                'name'    => trim($person['first_name'] . ' ' . $person['last_name']),
                'from'    => $employment['payer_name'],
                'date'    => $date,
                'note'    => $note,
                'primary' => $newPrimary !== null ? $this->payerName($newPrimary) : null,
            ], static fn (mixed $value): bool => $value !== null));
        });

        return $this->get($actor, $id);
    }

    /**
     * Wskazanie firmy głównej spośród firm, w których osoba obecnie pracuje.
     *
     * @return array<string, mixed>
     */
    public function setPrimaryCompany(Actor $actor, int $id, int $payerId): array
    {
        $actor->authorize('beneficiaries.update');
        $person = $this->findVisible($actor, $id, false);
        if ($this->history->openEmployment($id, $payerId) === null) {
            throw ServiceException::validation(['payer_id' => \__('beneficiary.error.company_not_current')]);
        }
        if ($payerId === (int) $person['payer_id']) {
            return $this->get($actor, $id);
        }

        Transaction::run($this->db, function () use ($actor, $id, $person, $payerId): void {
            $this->history->setPrimaryPayer($id, $payerId);
            $this->events->log('beneficiary', $id, 'company_primary', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $payerId,
            ], [
                'name' => trim($person['first_name'] . ' ' . $person['last_name']),
                'from' => $this->payerName((int) $person['payer_id']),
                'to'   => $this->payerName($payerId),
            ]);
        });

        return $this->get($actor, $id);
    }

    /**
     * Poprawka pomyłki w okresie (daty, notatka) — bez zmiany firmy.
     *
     * @param array<string, mixed> $data employment_id, started_on, ended_on?, note?
     * @return array<string, mixed>
     */
    public function correctCompany(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('beneficiaries.update');
        $person = $this->findVisible($actor, $id, false);

        $v = new Validator($data);
        $employmentId = $v->id('employment_id', true);
        $startedOn = $v->date('started_on', true);
        $endedOn = $v->date('ended_on', false);
        $note = $v->string('note', false, 500);
        if ($startedOn !== null && $endedOn !== null && $endedOn < $startedOn) {
            $v->addError('ended_on', 'validation.date_order');
        }
        $v->throwIfFailed();

        $employment = $this->history->employment((int) $employmentId);
        if ($employment === null || $employment['beneficiary_id'] !== $id) {
            throw ServiceException::notFound(\__('beneficiary.error.employment_not_found'));
        }

        $openOthers = array_values(array_filter(
            $this->history->openPayerIds($id),
            static fn (int $payerId): bool => $payerId !== $employment['payer_id']
        ));
        if ($employment['is_current'] && $endedOn !== null && $openOthers === []) {
            throw ServiceException::conflict(\__('beneficiary.error.last_company'));
        }
        if (!$employment['is_current'] && $endedOn === null && $this->history->openEmployment($id, $employment['payer_id']) !== null) {
            throw ServiceException::conflict(\__('beneficiary.error.company_already_open'));
        }

        $after = ['started_on' => $startedOn, 'ended_on' => $endedOn, 'note' => $note];
        $changes = EventLogger::diff($employment, $after, ['started_on', 'ended_on', 'note']);
        if ($changes === []) {
            return $this->get($actor, $id);
        }

        Transaction::run($this->db, function () use ($actor, $id, $person, $employment, $startedOn, $endedOn, $note, $openOthers, $changes): void {
            $this->history->updateEmployment($employment['id'], (string) $startedOn, $endedOn, $note);
            if ($employment['is_current'] && $endedOn !== null && $employment['payer_id'] === (int) $person['payer_id']) {
                $this->history->setPrimaryPayer($id, $openOthers[0]);
            }
            $this->events->log('beneficiary', $id, 'company_corrected', $actor->id, [
                'beneficiary_id' => $id,
                'payer_id'       => $employment['payer_id'],
            ], [
                'name'    => trim($person['first_name'] . ' ' . $person['last_name']),
                'company' => $employment['payer_name'],
                'changes' => $changes,
            ]);
        });

        return $this->get($actor, $id);
    }

    private function payerName(int $payerId): string
    {
        $stmt = $this->db->prepare('SELECT company_name FROM payers WHERE id = :id');
        $stmt->execute(['id' => $payerId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveVisible(Actor $actor, int $id): ?array
    {
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
        $stmt = $this->db->prepare(
            "SELECT b.* FROM beneficiaries b WHERE b.id = :id AND b.archived_at IS NULL AND {$visibility} LIMIT 1"
        );
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        return $row === false ? null : self::castRow($row);
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $before bieżący stan przy edycji
     * @return array{first_name: string, last_name: string, email: ?string, phone: ?string, payer_id: ?int, notes: ?string}
     */
    public function validate(Actor $actor, array $data, ?array $before = null): array
    {
        $v = new Validator($data);
        $values = [
            'first_name' => (string) $v->string('first_name', true, 100),
            'last_name'  => (string) $v->string('last_name', true, 100),
            'email'      => $v->email('email', false),
            'phone'      => $v->phone('phone'),
            'payer_id'   => $v->id('payer_id', true),
            'notes'      => $v->string('notes', false, 5000),
        ];

        // Użytkownik certyfikatu nie istnieje bez firmy. Powiązanie z firmą, której konto nie widzi,
        // jest dozwolone tylko wtedy, gdy istniało już wcześniej (edycja innych pól nie może go zerwać).
        $payerId = $values['payer_id'];
        $unchanged = $before !== null && $payerId !== null && $payerId === $before['payer_id'];
        if ($payerId !== null && !$unchanged && $this->payers->findActiveVisible($actor, $payerId) === null) {
            $v->addError('payer_id', 'beneficiary.error.payer_unavailable');
        }

        $v->throwIfFailed();

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function findVisible(Actor $actor, int $id, bool $includeArchived): array
    {
        [$visibility, $params] = Visibility::beneficiaries($actor, 'b');
        $stmt = $this->db->prepare(
            "SELECT b.*, p.company_name AS payer_name, p.archived_at AS payer_archived_at
             FROM beneficiaries b
             LEFT JOIN payers p ON p.id = b.payer_id
             WHERE b.id = :id AND {$visibility} LIMIT 1"
        );
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ServiceException::notFound(\__('beneficiary.error.not_found'));
        }

        if ($row['archived_at'] !== null && (!$includeArchived || !$actor->can('archive.view'))) {
            throw $includeArchived
                ? ServiceException::notFound(\__('beneficiary.error.not_found'))
                : ServiceException::conflict(\__('common.error.archived_readonly'));
        }

        return self::castRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['payer_id'] = isset($row['payer_id']) ? (int) $row['payer_id'] : null;
        if (array_key_exists('certificate_count', $row)) {
            $row['certificate_count'] = (int) $row['certificate_count'];
        }
        if (array_key_exists('created_by_user_id', $row)) {
            $row['created_by_user_id'] = $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null;
        }

        return $row;
    }
}

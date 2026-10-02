<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use PDOException;

/**
 * Ewidencja płatników (F3, F5): lista, szczegóły, dodawanie, edycja, archiwizacja i przywracanie.
 * Płatnik nie jest usuwany — archiwizacja zachowuje historię i powiązania.
 */
final class PayerService
{
    /** @var list<string> */
    public const FIELDS = ['company_name', 'contact_person', 'tax_id', 'email', 'phone', 'address_line', 'postal_code', 'city'];

    private readonly EventLogger $events;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null)
    {
        $this->events = $events ?? new EventLogger($db);
    }

    /**
     * Płatnicy widoczni dla konta wraz z liczbą osób i certyfikatów (liczonych w zakresie konta).
     *
     * @return list<array<string, mixed>>
     */
    public function list(Actor $actor, bool $archived = false): array
    {
        $actor->authorize($archived ? 'archive.view' : 'payers.view');

        [$payerVisibility, $params] = Visibility::payers($actor, 'p');
        [$certificateVisibility, $certificateParams] = Visibility::certificates($actor, 'c');
        [$beneficiaryVisibility, $beneficiaryParams] = Visibility::beneficiaries($actor, 'b');
        [$company, $companyParams] = Visibility::companyFilter($actor, 'p.id');
        $archivedCondition = $archived ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL';

        $sql = "
            SELECT
                p.id, p.company_name, p.contact_person, p.tax_id, p.email, p.phone,
                p.address_line, p.postal_code, p.city, p.archived_at, p.created_at, p.updated_at,
                COALESCE(cs.certificate_count, 0) AS certificate_count,
                COALESCE(cs.average_discount, 0) AS average_discount,
                cs.earliest_expiry,
                COALESCE(bs.beneficiary_count, 0) AS beneficiary_count
            FROM payers p
            LEFT JOIN (
                SELECT c.payer_id,
                       COUNT(*) AS certificate_count,
                       AVG(c.discount_percent) AS average_discount,
                       MIN(c.expiry_date) AS earliest_expiry
                FROM certificates c
                WHERE c.archived_at IS NULL AND {$certificateVisibility}
                GROUP BY c.payer_id
            ) cs ON cs.payer_id = p.id
            LEFT JOIN (
                SELECT b.payer_id, COUNT(*) AS beneficiary_count
                FROM beneficiaries b
                WHERE b.archived_at IS NULL AND {$beneficiaryVisibility}
                GROUP BY b.payer_id
            ) bs ON bs.payer_id = p.id
            WHERE {$archivedCondition}
              AND {$payerVisibility}
              AND {$company}
            ORDER BY " . ($archived ? 'p.archived_at DESC' : 'p.company_name');

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params + $certificateParams + $beneficiaryParams + $companyParams);

        return array_map([self::class, 'castRow'], $stmt->fetchAll());
    }

    /**
     * Krótka lista do pól wyboru w formularzach (tylko aktywni, widoczni płatnicy).
     *
     * @return list<array{id: int, company_name: string, tax_id: ?string, city: ?string}>
     */
    public function options(Actor $actor): array
    {
        $actor->authorize('payers.view');
        [$visibility, $params] = Visibility::payers($actor, 'p');

        $stmt = $this->db->prepare(
            "SELECT p.id, p.company_name, p.tax_id, p.city
             FROM payers p
             WHERE p.archived_at IS NULL AND {$visibility}
             ORDER BY p.company_name"
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'company_name' => (string) $row['company_name'],
            'tax_id'       => $row['tax_id'],
            'city'         => $row['city'],
        ], $stmt->fetchAll());
    }

    /**
     * Szczegóły płatnika z powiązanymi osobami i certyfikatami.
     *
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $id): array
    {
        $actor->authorize('payers.view');
        $payer = $this->findVisible($actor, $id, true);

        [$beneficiaryVisibility, $beneficiaryParams] = Visibility::beneficiaries($actor, 'b');
        $stmt = $this->db->prepare(
            "SELECT b.id, b.first_name, b.last_name, b.email, b.phone
             FROM beneficiaries b
             WHERE b.payer_id = :payer_id AND b.archived_at IS NULL AND {$beneficiaryVisibility}
             ORDER BY b.last_name, b.first_name"
        );
        $stmt->execute(['payer_id' => $id] + $beneficiaryParams);
        $payer['beneficiaries'] = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];

            return $row;
        }, $stmt->fetchAll());

        [$certificateVisibility, $certificateParams] = Visibility::certificates($actor, 'c');
        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, c.certificate_type, c.serial_number, c.expiry_date, c.status, c.discount_percent,
                    b.first_name AS beneficiary_first_name, b.last_name AS beneficiary_last_name
             FROM certificates c
             LEFT JOIN beneficiaries b ON b.id = c.beneficiary_id
             WHERE c.payer_id = :payer_id AND c.archived_at IS NULL AND {$certificateVisibility}
             ORDER BY c.expiry_date"
        );
        $stmt->execute(['payer_id' => $id] + $certificateParams);
        $payer['certificates'] = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['discount_percent'] = (float) $row['discount_percent'];

            return $row;
        }, $stmt->fetchAll());

        return $payer;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(Actor $actor, array $data): array
    {
        $actor->authorize('payers.create');
        $values = $this->validate($data);
        $this->assertTaxIdAvailable($actor, $values['tax_id'], null);

        try {
            $id = Transaction::run($this->db, function () use ($actor, $values): int {
                $stmt = $this->db->prepare(
                    'INSERT INTO payers (company_name, contact_person, tax_id, email, phone, address_line, postal_code, city, created_by_user_id)
                     VALUES (:company_name, :contact_person, :tax_id, :email, :phone, :address_line, :postal_code, :city, :created_by_user_id)'
                );
                $stmt->execute($values + ['created_by_user_id' => $actor->id]);
                $id = (int) $this->db->lastInsertId();

                $this->events->log('payer', $id, 'created', $actor->id, ['payer_id' => $id], [
                    'company_name' => $values['company_name'],
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            throw $this->translateDuplicate($e, $actor, $values['tax_id']);
        }

        return $this->get($actor, $id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(Actor $actor, int $id, array $data): array
    {
        $actor->authorize('payers.update');
        $before = $this->findVisible($actor, $id, false);
        $values = $this->validate($data);
        $this->assertTaxIdAvailable($actor, $values['tax_id'], $id);

        $changes = EventLogger::diff($before, $values, self::FIELDS);
        if ($changes === []) {
            return $this->get($actor, $id);
        }

        try {
            Transaction::run($this->db, function () use ($actor, $id, $values, $changes): void {
                $stmt = $this->db->prepare(
                    'UPDATE payers SET company_name = :company_name, contact_person = :contact_person, tax_id = :tax_id,
                            email = :email, phone = :phone, address_line = :address_line, postal_code = :postal_code, city = :city
                     WHERE id = :id'
                );
                $stmt->execute($values + ['id' => $id]);
                $this->events->log('payer', $id, 'updated', $actor->id, ['payer_id' => $id], ['changes' => $changes]);
            });
        } catch (PDOException $e) {
            throw $this->translateDuplicate($e, $actor, $values['tax_id']);
        }

        return $this->get($actor, $id);
    }

    /**
     * Archiwizacja jest zablokowana, dopóki płatnik ma aktywne certyfikaty lub osoby —
     * inaczej bieżące rekordy wskazywałyby na płatnika z archiwum.
     *
     * @return array<string, mixed>
     */
    public function archive(Actor $actor, int $id): array
    {
        $actor->authorize('payers.archive');
        $payer = $this->findVisible($actor, $id, false);

        $certificates = $this->countActive('certificates', $id);
        $beneficiaries = $this->countActive('beneficiaries', $id);
        if ($certificates > 0 || $beneficiaries > 0) {
            throw ServiceException::conflict(\__('payer.error.archive_blocked', [
                'certificates'  => (string) $certificates,
                'beneficiaries' => (string) $beneficiaries,
            ]), ['certificates' => $certificates, 'beneficiaries' => $beneficiaries]);
        }

        Transaction::run($this->db, function () use ($actor, $id, $payer): void {
            $this->db->prepare('UPDATE payers SET archived_at = NOW() WHERE id = :id')->execute(['id' => $id]);
            $this->events->log('payer', $id, 'archived', $actor->id, ['payer_id' => $id], [
                'company_name' => $payer['company_name'],
            ]);
        });

        return $this->get($actor, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(Actor $actor, int $id): array
    {
        $actor->authorize('payers.archive');
        $payer = $this->findVisible($actor, $id, true);
        if ($payer['archived_at'] === null) {
            throw ServiceException::conflict(\__('common.error.not_archived'));
        }

        Transaction::run($this->db, function () use ($actor, $id, $payer): void {
            $this->db->prepare('UPDATE payers SET archived_at = NULL WHERE id = :id')->execute(['id' => $id]);
            $this->events->log('payer', $id, 'restored', $actor->id, ['payer_id' => $id], [
                'company_name' => $payer['company_name'],
            ]);
        });

        return $this->get($actor, $id);
    }

    /**
     * Aktywny płatnik widoczny dla konta — do sprawdzania powiązań z innych usług.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveVisible(Actor $actor, int $id): ?array
    {
        [$visibility, $params] = Visibility::payers($actor, 'p');
        $stmt = $this->db->prepare(
            "SELECT p.* FROM payers p WHERE p.id = :id AND p.archived_at IS NULL AND {$visibility} LIMIT 1"
        );
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        return $row === false ? null : self::castRow($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{company_name: ?string, contact_person: ?string, tax_id: ?string, email: ?string, phone: ?string, address_line: ?string, postal_code: ?string, city: ?string}
     */
    public function validate(array $data): array
    {
        $v = new Validator($data);
        $values = [
            'company_name'   => $v->string('company_name', true, 255),
            'contact_person' => $v->string('contact_person', true, 200),
            'tax_id'         => $v->taxId('tax_id'),
            'email'          => $v->email('email', false),
            'phone'          => $v->phone('phone'),
            'address_line'   => $v->string('address_line', false, 255),
            'postal_code'    => $v->postalCode('postal_code'),
            'city'           => $v->string('city', false, 120),
        ];
        $v->throwIfFailed();

        return $values;
    }

    /**
     * NIP jest unikalny w całej bazie (także w archiwum). Szczegóły istniejącego płatnika
     * dostaje tylko konto, które i tak go widzi — operator nie poznaje w ten sposób cudzych rekordów.
     */
    private function assertTaxIdAvailable(Actor $actor, ?string $taxId, ?int $exceptId): void
    {
        if ($taxId === null) {
            return;
        }

        $stmt = $this->db->prepare('SELECT id, company_name, archived_at FROM payers WHERE tax_id = :tax_id AND id <> :id LIMIT 1');
        $stmt->execute(['tax_id' => $taxId, 'id' => $exceptId ?? 0]);
        $existing = $stmt->fetch();
        if ($existing === false) {
            return;
        }

        throw $this->taxIdConflict($actor, $existing);
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function taxIdConflict(Actor $actor, array $existing): ServiceException
    {
        $existingId = (int) $existing['id'];
        $visible = $actor->seesAllRecords() || $this->findActiveVisible($actor, $existingId) !== null;
        $error = ['tax_id' => \__('payer.error.tax_id_taken_short')];

        if (!$visible) {
            return ServiceException::conflict(\__('payer.error.tax_id_taken_hidden'), [], $error);
        }

        $archived = $existing['archived_at'] !== null;

        return ServiceException::conflict(
            \__($archived ? 'payer.error.tax_id_taken_archived' : 'payer.error.tax_id_taken', [
                'name' => (string) $existing['company_name'],
            ]),
            ['existing' => ['id' => $existingId, 'company_name' => $existing['company_name'], 'archived' => $archived]],
            $error
        );
    }

    private function translateDuplicate(PDOException $e, Actor $actor, ?string $taxId): \Throwable
    {
        if (($e->errorInfo[1] ?? null) !== 1062 || $taxId === null) {
            return $e;
        }

        $stmt = $this->db->prepare('SELECT id, company_name, archived_at FROM payers WHERE tax_id = :tax_id LIMIT 1');
        $stmt->execute(['tax_id' => $taxId]);
        $existing = $stmt->fetch();

        return $existing === false ? $e : $this->taxIdConflict($actor, $existing);
    }

    /**
     * @return array<string, mixed>
     */
    public function findVisible(Actor $actor, int $id, bool $includeArchived): array
    {
        [$visibility, $params] = Visibility::payers($actor, 'p');
        $stmt = $this->db->prepare("SELECT p.* FROM payers p WHERE p.id = :id AND {$visibility} LIMIT 1");
        $stmt->execute(['id' => $id] + $params);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ServiceException::notFound(\__('payer.error.not_found'));
        }

        if ($row['archived_at'] !== null) {
            // Rekordy z archiwum widzi tylko rola z dostępem do archiwum; edytować ich nie można.
            if (!$includeArchived || !$actor->can('archive.view')) {
                throw $includeArchived
                    ? ServiceException::notFound(\__('payer.error.not_found'))
                    : ServiceException::conflict(\__('common.error.archived_readonly'));
            }
        }

        return self::castRow($row);
    }

    private function countActive(string $table, int $payerId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE payer_id = :id AND archived_at IS NULL");
        $stmt->execute(['id' => $payerId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        foreach (['certificate_count', 'beneficiary_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (array_key_exists('average_discount', $row)) {
            $row['average_discount'] = round((float) $row['average_discount'], 2);
        }
        if (array_key_exists('created_by_user_id', $row)) {
            $row['created_by_user_id'] = $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null;
        }

        return $row;
    }
}

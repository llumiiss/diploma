<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\PasswordPolicy;
use App\Rbac;
use App\Service\Actor;
use App\Session;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Baza dla testów usług na prawdziwym MySQL (baza testowa, patrz MysqlTestDatabase).
 * Uruchamiane tylko z RUN_INTEGRATION_TESTS=1.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        if (!MysqlTestDatabase::isEnabled()) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=1 to run MySQL integration tests.');
        }

        Session::ensureStarted();
        $_SESSION = [];

        $this->db = MysqlTestDatabase::connection();
        MysqlTestDatabase::reset($this->db);
    }

    protected function createActor(string $role = Rbac::OPERATOR, string $email = ''): Actor
    {
        static $sequence = 0;
        ++$sequence;
        $email = $email !== '' ? $email : strtolower($role) . $sequence . '@example.com';

        // Konto gotowe do pracy: hasło ustawione i adres potwierdzony (Etap 9).
        $stmt = $this->db->prepare(
            'INSERT INTO users (first_name, last_name, role, email, password_hash, email_verified_at)
             VALUES (:first, :last, :role, :email, :password_hash, NOW())'
        );
        $stmt->execute([
            'first'         => ucfirst(strtolower($role)),
            'last'          => 'Tester' . $sequence,
            'role'          => $role,
            'email'         => $email,
            'password_hash' => PasswordPolicy::hash('TesteroweHaslo123'),
        ]);

        return new Actor((int) $this->db->lastInsertId(), $role, ucfirst(strtolower($role)), 'Tester' . $sequence, $email);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertPayer(array $overrides = [], ?int $createdBy = null): int
    {
        static $sequence = 0;
        ++$sequence;
        $row = array_merge([
            'company_name'       => 'Płatnik testowy ' . $sequence,
            'contact_person'     => 'Osoba Kontaktowa',
            'tax_id'             => null,
            'created_by_user_id' => $createdBy,
        ], $overrides);

        $stmt = $this->db->prepare(
            'INSERT INTO payers (company_name, contact_person, tax_id, created_by_user_id)
             VALUES (:company_name, :contact_person, :tax_id, :created_by_user_id)'
        );
        $stmt->execute($row);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertBeneficiary(int $payerId, array $overrides = [], ?int $createdBy = null): int
    {
        $row = array_merge([
            'first_name'         => 'Jan',
            'last_name'          => 'Testowy',
            'email'              => 'jan.testowy@example.com',
            'payer_id'           => $payerId,
            'created_by_user_id' => $createdBy,
        ], $overrides);

        $stmt = $this->db->prepare(
            'INSERT INTO beneficiaries (first_name, last_name, email, payer_id, created_by_user_id)
             VALUES (:first_name, :last_name, :email, :payer_id, :created_by_user_id)'
        );
        $stmt->execute($row);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertCertificate(int $ownerId, int $payerId, array $overrides = []): int
    {
        static $sequence = 0;
        ++$sequence;
        $row = array_merge([
            'name'             => 'Certyfikat testowy ' . $sequence,
            'certificate_type' => 'QUALIFIED_SIGNATURE',
            'serial_number'    => null,
            'issuer'           => null,
            'expiry_date'      => date('Y-m-d', strtotime('+60 days')),
            'user_id'          => $ownerId,
            'beneficiary_id'   => null,
            'payer_id'         => $payerId,
            'status'           => 'active',
            'archived_at'      => null,
        ], $overrides);

        // Certyfikat kwalifikowany nie istnieje bez użytkownika (ograniczenie bazy) — w testach powstaje domyślny.
        if ($row['beneficiary_id'] === null && in_array($row['certificate_type'], \App\Service\CertificateService::QUALIFIED_TYPES, true)) {
            $row['beneficiary_id'] = $this->insertBeneficiary($payerId, [
                'first_name' => 'Użytkownik',
                'last_name'  => 'Domyślny ' . $sequence,
                'email'      => 'uzytkownik' . $sequence . '@example.com',
            ]);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO certificates (name, certificate_type, serial_number, issuer, expiry_date, user_id,
                beneficiary_id, payer_id, status, archived_at)
             VALUES (:name, :certificate_type, :serial_number, :issuer, :expiry_date, :user_id,
                :beneficiary_id, :payer_id, :status, :archived_at)'
        );
        $stmt->execute($row);

        return (int) $this->db->lastInsertId();
    }

    protected function countEvents(string $entityType, int $entityId, string $eventType): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM events WHERE entity_type = :type AND entity_id = :id AND event_type = :event'
        );
        $stmt->execute(['type' => $entityType, 'id' => $entityId, 'event' => $eventType]);

        return (int) $stmt->fetchColumn();
    }
}

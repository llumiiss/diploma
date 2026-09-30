<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\AuthManager;
use App\Rbac;
use App\Service\AccountService;
use App\Service\ServiceException;
use App\UserManager;
use Tests\Support\IntegrationTestCase;
use Tests\Support\RecordingAuthMailer;

final class AccountServiceTest extends IntegrationTestCase
{
    public function testAdminCreatesAccountAndDuplicateEmailIsRejected(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $service = new AccountService($this->db);

        $account = $service->create($admin, [
            'first_name' => 'Ewa',
            'last_name'  => 'Pawlak',
            'email'      => 'Ewa.Pawlak@Example.com',
            'role'       => Rbac::MANAGER,
        ]);

        $this->assertSame('ewa.pawlak@example.com', $account['email']);
        $this->assertSame(Rbac::MANAGER, $account['role']);
        $this->assertTrue($account['active']);
        $this->assertSame(1, $this->countEvents('user', $account['id'], 'account_created'));

        try {
            $service->create($admin, ['first_name' => 'Inna', 'last_name' => 'Osoba', 'email' => 'ewa.pawlak@example.com', 'role' => 'OPERATOR']);
            $this->fail('E-mail musi być unikalny.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('email', $e->errors);
        }
    }

    public function testOnlyAdminManagesAccounts(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);

        $this->expectException(ServiceException::class);
        (new AccountService($this->db))->list($manager);
    }

    public function testLastActiveAdminCannotBeDemotedDeactivatedOrDeactivateThemselves(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $operator = $this->createActor(Rbac::OPERATOR);
        $service = new AccountService($this->db);

        foreach ([
            fn () => $service->update($admin, $admin->id, ['first_name' => 'A', 'last_name' => 'B', 'email' => $admin->email, 'role' => Rbac::MANAGER]),
            fn () => $service->deactivate($admin, $admin->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('System musi zachować aktywnego administratora.');
            } catch (ServiceException $e) {
                $this->assertSame(409, $e->httpStatus());
            }
        }

        // Drugi administrator może zostać zdegradowany, bo pierwszy zostaje.
        $second = $service->update($admin, $operator->id, [
            'first_name' => 'Druga', 'last_name' => 'Adminka', 'email' => $operator->email, 'role' => Rbac::ADMIN,
        ]);
        $this->assertSame(Rbac::ADMIN, $second['role']);
        $demoted = $service->update($admin, $admin->id, [
            'first_name' => 'A', 'last_name' => 'B', 'email' => $admin->email, 'role' => Rbac::MANAGER,
        ]);
        $this->assertSame(Rbac::MANAGER, $demoted['role']);
    }

    public function testDeactivatedAccountCannotLogInAndCanBeReactivated(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $operator = $this->createActor(Rbac::OPERATOR, 'odchodzi@example.com');
        $service = new AccountService($this->db);
        $mail = new RecordingAuthMailer();
        $auth = new AuthManager($this->db, new UserManager($this->db), $mail);

        // Niewykorzystany link „ustaw hasło” czeka w skrzynce osoby, która odchodzi.
        $auth->requestPasswordSetLink('odchodzi@example.com');
        $this->assertCount(1, $mail->sent);
        $token = $mail->lastToken('password');

        $service->deactivate($admin, $operator->id);

        // Wyłączenie konta unieważnia link od razu — nie da się nim ustawić hasła ani wejść do systemu.
        $this->assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) FROM email_verifications WHERE user_id = {$operator->id} AND used_at IS NULL"
        )->fetchColumn());
        $this->assertSame(
            'auth.error.link_used',
            $auth->setPasswordWithToken($token, 'NoweHaslo123', 'NoweHaslo123')['error']
        );
        $this->assertCount(1, $mail->sent);

        $auth->login($operator->id);
        $this->assertFalse($auth->currentUser());

        $service->reactivate($admin, $operator->id);
        $auth->login($operator->id);
        $this->assertNotFalse($auth->currentUser());
    }

    public function testTransferMovesCertificatesAndOpenTasks(): void
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $leaving = $this->createActor(Rbac::OPERATOR);
        $successor = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer();
        $first = $this->insertCertificate($leaving->id, $payerId);
        $second = $this->insertCertificate($leaving->id, $payerId);
        $this->db->exec(
            "INSERT INTO renewal_tasks (certificate_id, assigned_user_id, status, priority, due_date) VALUES
             ({$first}, {$leaving->id}, 'todo', 'warning', CURDATE()),
             ({$second}, {$leaving->id}, 'done', 'warning', CURDATE())"
        );
        $service = new AccountService($this->db);

        $result = $service->transferCertificates($admin, $leaving->id, $successor->id);

        $this->assertSame(['certificates' => 2, 'tasks' => 1], $result);
        $this->assertSame(2, (int) $this->db->query("SELECT COUNT(*) FROM certificates WHERE user_id = {$successor->id}")->fetchColumn());
        $this->assertSame(1, $this->countEvents('certificate', $first, 'owner_changed'));
        $this->assertSame(0, $service->get($leaving->id)['certificate_count']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\CertificateService;
use App\Service\ServiceException;
use App\Service\TaskService;
use DateTimeImmutable;
use Tests\Support\IntegrationTestCase;

final class TaskServiceTest extends IntegrationTestCase
{
    private function day(int $offset): string
    {
        return (new DateTimeImmutable('today'))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function certificateStatus(int $certificateId): string
    {
        return (string) $this->db->query("SELECT status FROM certificates WHERE id = {$certificateId}")->fetchColumn();
    }

    public function testStatusFlowUpdatesCertificateAndRequiresReasonForAbandoning(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $certificateId = $this->insertCertificate($operator->id, $this->insertPayer(), ['expiry_date' => $this->day(10)]);
        $service = new TaskService($this->db);

        $task = $service->create($operator, ['certificate_id' => $certificateId]);
        $this->assertSame('todo', $task['status']);
        $this->assertSame('warning', $task['priority']);
        $this->assertSame($operator->id, $task['assigned_user_id']);

        $task = $service->changeStatus($operator, $task['id'], 'in_progress');
        $this->assertSame('in_progress', $task['status']);
        $this->assertSame('renewal_in_progress', $this->certificateStatus($certificateId));

        try {
            $service->changeStatus($operator, $task['id'], 'abandoned');
            $this->fail('Porzucenie wymaga podania powodu.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('note', $e->errors);
        }

        $task = $service->changeStatus($operator, $task['id'], 'abandoned', 'Klient rezygnuje z certyfikatu');
        $this->assertSame('abandoned', $task['status']);
        $this->assertNotNull($task['closed_at']);
        $this->assertSame('Klient rezygnuje z certyfikatu', $task['resolution_note']);
        $this->assertSame('active', $this->certificateStatus($certificateId));

        try {
            $service->changeStatus($operator, $task['id'], 'todo');
            $this->fail('Ponowne otwarcie zadania to decyzja kierownika.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }

        $manager = $this->createActor(Rbac::MANAGER);
        $this->assertSame('todo', $service->changeStatus($manager, $task['id'], 'todo')['status']);
        $this->assertSame(1, $this->countEvents('renewal_task', $task['id'], 'task_closed'));
    }

    public function testCertificateCannotHaveTwoOpenTasks(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $certificateId = $this->insertCertificate($operator->id, $this->insertPayer());
        $service = new TaskService($this->db);
        $service->create($operator, ['certificate_id' => $certificateId]);

        $this->expectException(ServiceException::class);
        $service->create($operator, ['certificate_id' => $certificateId]);
    }

    public function testOnlyManagersAssignTasksAndOnlyToActiveAccounts(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $operator = $this->createActor(Rbac::OPERATOR);
        $inactive = $this->createActor(Rbac::OPERATOR);
        $this->db->exec("UPDATE users SET deactivated_at = NOW() WHERE id = {$inactive->id}");
        $certificateId = $this->insertCertificate($manager->id, $this->insertPayer());
        $service = new TaskService($this->db);
        $task = $service->create($manager, ['certificate_id' => $certificateId]);
        $this->assertSame($manager->id, $task['assigned_user_id']);

        try {
            $service->assign($operator, $task['id'], $operator->id);
            $this->fail('Operator nie przydziela zadań.');
        } catch (ServiceException $e) {
            $this->assertSame(403, $e->httpStatus());
        }

        try {
            $service->assign($manager, $task['id'], $inactive->id);
            $this->fail('Nie można przydzielić zadania wyłączonemu kontu.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('assigned_user_id', $e->errors);
        }

        $assigned = $service->assign($manager, $task['id'], $operator->id);
        $this->assertSame($operator->id, $assigned['assigned_user_id']);
        $this->assertSame(1, $this->countEvents('renewal_task', $task['id'], 'task_assigned'));

        // Przydzielone zadanie otwiera operatorowi dostęp do cudzego certyfikatu (D8).
        $this->assertSame([$task['id']], array_column($service->list($operator), 'id'));
    }

    public function testRenewalCreatesNewCertificateArchivesOldAndClosesTask(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer([], $operator->id);
        $beneficiaryId = $this->insertBeneficiary($payerId, [], $operator->id);
        $oldId = $this->insertCertificate($operator->id, $payerId, [
            'beneficiary_id' => $beneficiaryId,
            'expiry_date'    => $this->day(5),
            'serial_number'  => 'OLD-SERIAL',
            'issuer'         => 'Certum QCA 2017',
        ]);
        $service = new TaskService($this->db);
        $task = $service->create($operator, ['certificate_id' => $oldId]);

        try {
            $service->renew($operator, $task['id'], ['expiry_date' => $this->day(1)]);
            $this->fail('Nowa data ważności musi być późniejsza niż dotychczasowa.');
        } catch (ServiceException $e) {
            $this->assertArrayHasKey('expiry_date', $e->errors);
        }

        $result = $service->renew($operator, $task['id'], [
            'expiry_date'   => $this->day(735),
            'valid_from'    => $this->day(5),
            'serial_number' => 'NEW-SERIAL',
            'note'          => 'Odnowiono na kolejne 2 lata',
        ]);

        $new = $result['certificate'];
        $this->assertSame('done', $result['task']['status']);
        $this->assertSame($oldId, $new['previous_certificate_id']);
        $this->assertSame($beneficiaryId, $new['beneficiary_id']);
        $this->assertSame($operator->id, $new['user_id']);
        $this->assertSame('Certum QCA 2017', $new['issuer']);
        $this->assertNotNull($this->db->query("SELECT archived_at FROM certificates WHERE id = {$oldId}")->fetchColumn());
        $this->assertSame(1, $this->countEvents('certificate', $oldId, 'renewed'));
        $this->assertSame([$new['id']], array_column((new CertificateService($this->db))->list($operator), 'id'));
    }

    public function testStatisticsGroupTasksByStatus(): void
    {
        $manager = $this->createActor(Rbac::MANAGER);
        $payerId = $this->insertPayer();
        foreach (['todo' => $this->day(-3), 'in_progress' => $this->day(4), 'done' => $this->day(20), 'abandoned' => $this->day(25)] as $status => $due) {
            $certificateId = $this->insertCertificate($manager->id, $payerId);
            $closed = in_array($status, ['done', 'abandoned'], true) ? 'NOW()' : 'NULL';
            $this->db->exec(
                "INSERT INTO renewal_tasks (certificate_id, assigned_user_id, status, priority, due_date, closed_at, created_at)
                 VALUES ({$certificateId}, {$manager->id}, '{$status}', 'warning', '{$due}', {$closed}, DATE_SUB(NOW(), INTERVAL 4 DAY))"
            );
        }

        $stats = (new TaskService($this->db))->stats($manager);

        $this->assertSame(['todo' => 1, 'in_progress' => 1, 'done' => 1, 'abandoned' => 1], $stats['by_status']);
        $this->assertSame(2, $stats['open']);
        $this->assertSame(1, $stats['overdue']);
        $this->assertSame(2, $stats['mine_open']);
        $this->assertSame(50.0, $stats['completion_rate']);
        $this->assertSame(4.0, $stats['average_days_to_done']);
        $this->assertCount(1, $stats['by_assignee']);
    }
}

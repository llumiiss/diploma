<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\CertificateService;
use App\Service\InvitationService;
use App\Service\RenewalScanner;
use App\Service\ServiceException;
use App\Service\TaskService;
use Tests\Support\IntegrationTestCase;
use Tests\Support\RecordingInvitationMailer;

/**
 * Certyfikat kwalifikowany po terminie: bez zaproszeń, przypomnień i odnowienia — zostaje archiwizacja.
 */
final class LapsedCertificateTest extends IntegrationTestCase
{
    public function testRuleCoversOnlyQualifiedCertificatesAfterTheirLastDay(): void
    {
        $this->assertTrue(CertificateService::isLapsed('QUALIFIED_SIGNATURE', '2026-01-31', '2026-02-01'));
        $this->assertTrue(CertificateService::isLapsed('QUALIFIED_SEAL', '2026-01-31', '2026-02-01'));
        $this->assertFalse(CertificateService::isLapsed('QUALIFIED_SIGNATURE', '2026-02-01', '2026-02-01'), 'w dniu wygaśnięcia jeszcze działa');
        $this->assertFalse(CertificateService::isLapsed('SSL_CERTIFICATE', '2020-01-01', '2026-02-01'));
        $this->assertFalse(CertificateService::isLapsed('QUALIFIED_SIGNATURE', null, '2026-02-01'));
    }

    public function testTaskOfALapsedCertificateCanOnlyBeClosedByArchiving(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $manager = $this->createActor(Rbac::MANAGER);
        $payerId = $this->insertPayer();
        $certificateId = $this->insertCertificate($operator->id, $payerId, ['expiry_date' => date('Y-m-d', strtotime('-3 days'))]);
        (new RenewalScanner($this->db))->run();

        $tasks = new TaskService($this->db);
        $list = $tasks->list($operator);
        $this->assertCount(1, $list, 'zadanie nadal widać na liście ToDo');
        $this->assertTrue($list[0]['renewal_blocked']);
        $taskId = $list[0]['id'];

        foreach (['in_progress', 'done', 'abandoned'] as $status) {
            $this->assertConflict(static fn () => $tasks->changeStatus($manager, $taskId, $status, 'notatka'));
        }
        $this->assertConflict(static fn () => $tasks->renew($operator, $taskId, ['expiry_date' => date('Y-m-d', strtotime('+1 year'))]));

        $invitations = new InvitationService($this->db, new RecordingInvitationMailer());
        $this->assertConflict(static fn () => $invitations->composeOptions($operator, $certificateId));
        $templateId = (int) $this->db->query("SELECT id FROM email_templates WHERE code = 'renewal_invitation' LIMIT 1")->fetchColumn();
        $this->assertConflict(static fn () => $invitations->send($operator, ['certificate_id' => $certificateId, 'template_id' => $templateId, 'recipient_type' => 'beneficiary']));
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM invitations')->fetchColumn());

        // Operator nie archiwizuje certyfikatów, ale przeterminowany kwalifikowany może zamknąć.
        $archived = (new CertificateService($this->db))->archive($operator, $certificateId);
        $this->assertNotNull($archived['archived_at']);
        $this->assertSame('abandoned', $this->db->query("SELECT status FROM renewal_tasks WHERE id = {$taskId}")->fetchColumn());
        $this->assertSame(1, $this->countEvents('certificate', $certificateId, 'archived'));
    }

    public function testOperatorStillCannotArchiveOtherCertificates(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $other = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer();
        $valid = $this->insertCertificate($operator->id, $payerId);
        $expiredSsl = $this->insertCertificate($operator->id, $payerId, ['certificate_type' => 'SSL_CERTIFICATE', 'expiry_date' => date('Y-m-d', strtotime('-3 days'))]);
        $foreign = $this->insertCertificate($other->id, $payerId, ['expiry_date' => date('Y-m-d', strtotime('-3 days'))]);
        $service = new CertificateService($this->db);

        $this->assertStatus(403, static fn () => $service->archive($operator, $valid));
        $this->assertStatus(403, static fn () => $service->archive($operator, $expiredSsl));
        $this->assertStatus(404, static fn () => $service->archive($operator, $foreign));
        $this->assertStatus(403, fn () => $service->archive($this->createActor(Rbac::ACCOUNTANT), $foreign));
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM certificates WHERE archived_at IS NOT NULL')->fetchColumn());
    }

    public function testPlannedRemindersAreCancelledOnceTheCertificateExpires(): void
    {
        $operator = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer();
        $this->db->exec("UPDATE payers SET email = 'firma@example.com' WHERE id = {$payerId}");
        $certificateId = $this->insertCertificate($operator->id, $payerId, ['expiry_date' => date('Y-m-d', strtotime('+5 days'))]);
        $mailer = new RecordingInvitationMailer();
        $invitations = new InvitationService($this->db, $mailer);
        $templateId = (int) $this->db->query("SELECT id FROM email_templates WHERE code = 'renewal_invitation' LIMIT 1")->fetchColumn();
        $sent = $invitations->send($operator, ['certificate_id' => $certificateId, 'template_id' => $templateId, 'recipient_type' => 'payer']);
        $this->assertSame('sent', $sent['status']);

        $this->db->exec("UPDATE certificates SET expiry_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = {$certificateId}");
        $this->db->exec('UPDATE invitations SET next_reminder_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $messages = count($mailer->sent);

        $result = $invitations->processDueReminders();

        $this->assertSame(['due' => 0, 'sent' => 0, 'failed' => 0], $result);
        $this->assertCount($messages, $mailer->sent, 'po terminie nic nie wychodzi');
        $this->assertNull($this->db->query("SELECT next_reminder_at FROM invitations WHERE id = {$sent['id']}")->fetchColumn());
        $this->assertSame(1, $this->countEvents('invitation', $sent['id'], 'reminder_cancelled'));
        $this->assertTrue($invitations->get($operator, $sent['id'])['renewal_blocked']);
        $this->assertConflict(static fn () => $invitations->remind($operator, $sent['id']));
    }

    private function assertConflict(callable $action): void
    {
        $this->assertStatus(409, $action);
    }

    private function assertStatus(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Oczekiwano odmowy ' . $status);
        } catch (ServiceException $e) {
            $this->assertSame($status, $e->httpStatus(), $e->getMessage());
        }
    }
}

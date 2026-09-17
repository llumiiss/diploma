<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\Actor;
use App\Service\AttachmentService;
use App\Service\InvitationService;
use App\Service\ServiceException;
use App\Service\TaskService;
use Tests\Support\IntegrationTestCase;
use Tests\Support\RecordingInvitationMailer;

final class InvitationServiceTest extends IntegrationTestCase
{
    private RecordingInvitationMailer $mail;
    private string $storage;
    private Actor $operator;
    private int $certificateId;
    private int $payerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new RecordingInvitationMailer();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'certisub-test-' . bin2hex(random_bytes(4));

        $this->operator = $this->createActor(Rbac::OPERATOR);
        $this->payerId = $this->insertPayer(['company_name' => 'NovaTech Sp. z o.o.', 'contact_person' => 'Katarzyna Zielińska'], $this->operator->id);
        $this->db->exec("UPDATE payers SET email = NULL WHERE id = {$this->payerId}");
        $beneficiaryId = $this->insertBeneficiary($this->payerId, [
            'first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan.kowalski@example.com',
        ], $this->operator->id);
        $this->certificateId = $this->insertCertificate($this->operator->id, $this->payerId, [
            'beneficiary_id' => $beneficiaryId,
            'serial_number'  => '5A3F9C21B7E04D18',
            'expiry_date'    => date('Y-m-d', strtotime('+14 days')),
        ]);
    }

    protected function tearDown(): void
    {
        // Bez RUN_INTEGRATION_TESTS setUp kończy się pominięciem testu, zanim powstanie katalog.
        if (!isset($this->storage)) {
            return;
        }

        foreach (glob($this->storage . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storage);
    }

    private function service(): InvitationService
    {
        return new InvitationService($this->db, $this->mail, new AttachmentService($this->db, $this->storage));
    }

    private function templateId(string $code, string $locale = 'pl'): int
    {
        $stmt = $this->db->prepare('SELECT id FROM email_templates WHERE code = :code AND locale = :locale');
        $stmt->execute(['code' => $code, 'locale' => $locale]);

        return (int) $stmt->fetchColumn();
    }

    private function attachTemplateFile(int $templateId): int
    {
        $admin = $this->createActor(Rbac::ADMIN);
        $source = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($source, "Instrukcja odnowienia certyfikatu\n");
        $attachment = (new AttachmentService($this->db, $this->storage))->store($admin, $source, 'instrukcja.txt');
        @unlink($source);
        $this->db->exec("INSERT INTO email_template_attachments (template_id, attachment_id) VALUES ({$templateId}, {$attachment['id']})");

        return $attachment['id'];
    }

    public function testSendingInvitationFillsTemplateAttachesFilesAndStartsTheRenewalTask(): void
    {
        $templateId = $this->templateId('renewal_invitation');
        $attachmentId = $this->attachTemplateFile($templateId);

        $invitation = $this->service()->send($this->operator, [
            'certificate_id' => $this->certificateId,
            'template_id'    => $templateId,
            'recipient_type' => 'beneficiary',
            'attachment_ids' => [$attachmentId],
        ]);

        $this->assertCount(1, $this->mail->sent);
        $message = $this->mail->sent[0];
        $this->assertSame('jan.kowalski@example.com', $message['to']);
        $this->assertStringContainsString('5A3F9C21B7E04D18', $message['subject']);
        $this->assertStringContainsString('Jan Kowalski', $message['html']);
        $this->assertSame('instrukcja.txt', $message['attachments'][0]['name']);

        $this->assertSame('sent', $invitation['status']);
        $this->assertNotNull($invitation['next_reminder_at']);
        $this->assertCount(1, $invitation['attachments']);
        $this->assertSame('in_progress', $this->db->query("SELECT status FROM renewal_tasks WHERE id = {$invitation['renewal_task_id']}")->fetchColumn());
        $this->assertSame('renewal_in_progress', $this->db->query("SELECT status FROM certificates WHERE id = {$this->certificateId}")->fetchColumn());
        $this->assertSame(1, $this->countEvents('invitation', $invitation['id'], 'invitation_sent'));
    }

    public function testFailedDeliveryIsRecordedAndCanBeRetried(): void
    {
        $this->mail->failWith = 'SMTP connect() failed';
        $invitation = $this->service()->send($this->operator, [
            'certificate_id' => $this->certificateId,
            'template_id'    => $this->templateId('renewal_invitation'),
            'recipient_type' => 'beneficiary',
            'attachment_ids' => [],
        ]);

        $this->assertSame('failed', $invitation['status']);
        $this->assertSame('SMTP connect() failed', $invitation['last_error']);
        $this->assertSame('todo', $this->db->query("SELECT status FROM renewal_tasks WHERE id = {$invitation['renewal_task_id']}")->fetchColumn());

        $this->mail->failWith = null;
        $retried = $this->service()->retry($this->operator, $invitation['id']);
        $this->assertSame('sent', $retried['status']);
        $this->assertNull($retried['last_error']);
    }

    public function testPayerWithoutEmailCannotReceiveInvitation(): void
    {
        $this->expectException(ServiceException::class);
        $this->service()->send($this->operator, [
            'certificate_id' => $this->certificateId,
            'template_id'    => $this->templateId('renewal_invitation'),
            'recipient_type' => 'payer',
        ]);
    }

    public function testDueRemindersRespectIntervalAndLimit(): void
    {
        $this->db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('reminders.max_count', '2'), ('reminders.interval_days', '5')");
        $service = $this->service();
        $invitation = $service->send($this->operator, [
            'certificate_id' => $this->certificateId,
            'template_id'    => $this->templateId('renewal_invitation', 'en'),
            'recipient_type' => 'beneficiary',
            'attachment_ids' => [],
        ]);

        $this->assertSame(['due' => 0, 'sent' => 0, 'failed' => 0], $service->processDueReminders());

        $this->db->exec("UPDATE invitations SET next_reminder_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$invitation['id']}");
        $this->assertSame(['due' => 1, 'sent' => 1, 'failed' => 0], $service->processDueReminders());
        $this->assertStringStartsWith('Reminder:', $this->mail->sent[1]['subject']);

        $row = $this->db->query("SELECT reminder_count, next_reminder_at FROM invitations WHERE id = {$invitation['id']}")->fetch();
        $this->assertSame(1, (int) $row['reminder_count']);
        $this->assertNotNull($row['next_reminder_at']);

        $this->db->exec("UPDATE invitations SET next_reminder_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$invitation['id']}");
        $service->processDueReminders();
        $row = $this->db->query("SELECT reminder_count, next_reminder_at FROM invitations WHERE id = {$invitation['id']}")->fetch();
        $this->assertSame(2, (int) $row['reminder_count']);
        $this->assertNull($row['next_reminder_at'], 'Po osiągnięciu limitu nie planujemy kolejnych przypomnień.');
    }

    public function testResponseAndClosingTheTaskStopReminders(): void
    {
        $service = $this->service();
        $first = $service->send($this->operator, [
            'certificate_id' => $this->certificateId,
            'template_id'    => $this->templateId('renewal_invitation'),
            'recipient_type' => 'beneficiary',
            'attachment_ids' => [],
        ]);

        $responded = $service->markResponded($this->operator, $first['id']);
        $this->assertSame('responded', $responded['status']);
        $this->assertNull($responded['next_reminder_at']);

        $second = $service->send($this->operator, [
            'certificate_id' => $this->certificateId,
            'template_id'    => $this->templateId('renewal_invitation'),
            'recipient_type' => 'beneficiary',
            'attachment_ids' => [],
        ]);
        $this->assertSame($first['renewal_task_id'], $second['renewal_task_id']);

        (new TaskService($this->db))->changeStatus($this->operator, (int) $second['renewal_task_id'], 'done', 'Odnowiono poza systemem');
        $this->assertSame('closed', $service->get($this->operator, $second['id'])['status']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Rbac;
use App\Service\RenewalScanner;
use App\Service\ServiceException;
use DateTimeImmutable;
use Tests\Support\IntegrationTestCase;

final class RenewalScannerTest extends IntegrationTestCase
{
    private function day(int $offset): string
    {
        return (new DateTimeImmutable((string) $this->db->query('SELECT CURDATE()')->fetchColumn()))
            ->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>|false
     */
    private function openTask(int $certificateId): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM renewal_tasks WHERE certificate_id = :id AND status IN ('todo', 'in_progress')");
        $stmt->execute(['id' => $certificateId]);

        return $stmt->fetch();
    }

    public function testCreatesPrioritisedTasksOnlyForCertificatesInsideTheRenewalMargin(): void
    {
        $owner = $this->createActor(Rbac::OPERATOR);
        $payerId = $this->insertPayer();

        $warning = $this->insertCertificate($owner->id, $payerId, ['expiry_date' => $this->day(20)]);
        $critical = $this->insertCertificate($owner->id, $payerId, ['expiry_date' => $this->day(3)]);
        $expired = $this->insertCertificate($owner->id, $payerId, ['expiry_date' => $this->day(-5)]);
        $longLead = $this->insertCertificate($owner->id, $payerId, ['expiry_date' => $this->day(45)]);
        $this->db->exec("UPDATE certificates SET renewal_lead_days = 60 WHERE id = {$longLead}");
        $outside = $this->insertCertificate($owner->id, $payerId, ['expiry_date' => $this->day(45)]);
        $archived = $this->insertCertificate($owner->id, $payerId, ['expiry_date' => $this->day(5), 'archived_at' => date('Y-m-d H:i:s')]);
        $handled = $this->insertCertificate($owner->id, $payerId, ['expiry_date' => $this->day(10)]);
        $this->db->exec(
            "INSERT INTO renewal_tasks (certificate_id, status, priority, due_date, closed_at)
             VALUES ({$handled}, 'abandoned', 'warning', '{$this->day(10)}', NOW())"
        );

        $result = (new RenewalScanner($this->db))->run();

        $this->assertSame(4, $result['created']);
        $this->assertSame('warning', $this->openTask($warning)['priority']);
        $this->assertSame('critical', $this->openTask($critical)['priority']);
        $this->assertSame('expired', $this->openTask($expired)['priority']);
        $this->assertSame('warning', $this->openTask($longLead)['priority']);
        $this->assertSame($owner->id, (int) $this->openTask($warning)['assigned_user_id']);
        $this->assertSame($this->day(20), $this->openTask($warning)['due_date']);
        foreach ([$outside, $archived, $handled] as $skipped) {
            $this->assertFalse($this->openTask($skipped));
        }

        $again = (new RenewalScanner($this->db))->run();
        $this->assertSame(0, $again['created']);
        $this->assertSame(0, $again['updated']);
    }

    public function testEscalatesPriorityAsExpiryApproaches(): void
    {
        $owner = $this->createActor(Rbac::OPERATOR);
        $certificateId = $this->insertCertificate($owner->id, $this->insertPayer(), ['expiry_date' => $this->day(25)]);
        $scanner = new RenewalScanner($this->db);
        $scanner->run();

        $this->db->exec("UPDATE certificates SET expiry_date = '{$this->day(2)}' WHERE id = {$certificateId}");
        $result = $scanner->run();

        $task = $this->openTask($certificateId);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('critical', $task['priority']);
        $this->assertSame($this->day(2), $task['due_date']);
        $this->assertSame(1, $this->countEvents('renewal_task', (int) $task['id'], 'task_priority_changed'));
    }

    public function testTaskOfInactiveOwnerIsUnassignedAndSettingsWidenTheMargin(): void
    {
        $owner = $this->createActor(Rbac::OPERATOR);
        $this->db->exec("UPDATE users SET deactivated_at = NOW() WHERE id = {$owner->id}");
        $certificateId = $this->insertCertificate($owner->id, $this->insertPayer(), ['expiry_date' => $this->day(45)]);

        $this->assertSame(0, (new RenewalScanner($this->db))->run()['created']);

        $this->db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('renewal.warning_days', '50')");
        $this->assertSame(1, (new RenewalScanner($this->db))->run()['created']);
        $this->assertNull($this->openTask($certificateId)['assigned_user_id']);
    }

    public function testOnlyManagersRunTheScannerFromThePanel(): void
    {
        $this->expectException(ServiceException::class);
        (new RenewalScanner($this->db))->run($this->createActor(Rbac::OPERATOR));
    }
}

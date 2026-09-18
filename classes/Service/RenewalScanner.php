<?php

declare(strict_types=1);

namespace App\Service;

use App\Settings;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Skaner odnowień (F11, F12): przegląda bieżące certyfikaty firmowe i dla tych, które weszły
 * w margines odnowienia, zakłada zadanie ToDo z priorytetem zależnym od liczby dni do wygaśnięcia.
 *
 * Margines to wymagany czas odnowienia certyfikatu (renewal_lead_days), a gdy go brak — próg
 * z ustawień (domyślnie 30 dni). Uruchamiany codziennie przez cron/renewals.php albo ręcznie
 * z panelu. Wielokrotne uruchomienie nie tworzy duplikatów: certyfikat ma najwyżej jedno otwarte
 * zadanie, a zamknięte zadanie dla tej samej daty wygaśnięcia oznacza obsłużony cykl odnowienia.
 */
final class RenewalScanner
{
    private readonly EventLogger $events;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null)
    {
        $this->events = $events ?? new EventLogger($db);
    }

    /**
     * @param Actor|null $actor null = uruchomienie systemowe (cron)
     * @return array{scanned: int, created: int, updated: int, task_ids: list<int>}
     */
    public function run(?Actor $actor = null, ?DateTimeImmutable $today = null): array
    {
        $actor?->authorize('scanner.run');

        $settings = Settings::read($this->db);
        $criticalDays = $settings['renewal.critical_days'];
        $today = $today ?? new DateTimeImmutable((string) $this->db->query('SELECT CURDATE()')->fetchColumn());

        $stmt = $this->db->prepare(
            "SELECT c.id, c.name, c.expiry_date, c.renewal_lead_days, c.user_id, c.beneficiary_id, c.payer_id,
                    u.deactivated_at AS owner_deactivated_at,
                    t.id AS task_id, t.priority AS task_priority, t.due_date AS task_due_date
             FROM certificates c
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN renewal_tasks t ON t.certificate_id = c.id AND t.status IN ('todo', 'in_progress')
             WHERE c.archived_at IS NULL
               AND c.expiry_date <= DATE_ADD(:today, INTERVAL COALESCE(c.renewal_lead_days, :default_lead) DAY)
               AND (t.id IS NOT NULL OR NOT EXISTS (
                    SELECT 1 FROM renewal_tasks handled
                    WHERE handled.certificate_id = c.id
                      AND handled.status IN ('done', 'abandoned')
                      AND handled.due_date = c.expiry_date))
             ORDER BY c.expiry_date, c.id"
        );
        $stmt->execute(['today' => $today->format('Y-m-d'), 'default_lead' => $settings['renewal.warning_days']]);
        $rows = $stmt->fetchAll();

        $userId = $actor?->id;
        $created = [];
        $updated = 0;

        foreach ($rows as $row) {
            $expiry = new DateTimeImmutable((string) $row['expiry_date']);
            $daysLeft = (int) $today->diff($expiry)->format('%r%a');
            $priority = self::priorityFor($daysLeft, $criticalDays);
            $context = [
                'certificate_id' => (int) $row['id'],
                'beneficiary_id' => $row['beneficiary_id'] !== null ? (int) $row['beneficiary_id'] : null,
                'payer_id'       => (int) $row['payer_id'],
            ];

            if ($row['task_id'] === null) {
                $taskId = $this->openTask($row, $priority, $daysLeft, $userId, $context);
                if ($taskId !== null) {
                    $created[] = $taskId;
                }
                continue;
            }

            if ($row['task_priority'] !== $priority || $row['task_due_date'] !== $row['expiry_date']) {
                Transaction::run($this->db, function () use ($row, $priority, $daysLeft, $userId, $context): void {
                    $this->db->prepare('UPDATE renewal_tasks SET priority = :priority, due_date = :due_date WHERE id = :id')
                        ->execute(['priority' => $priority, 'due_date' => $row['expiry_date'], 'id' => (int) $row['task_id']]);
                    $this->events->log('renewal_task', (int) $row['task_id'], 'task_priority_changed', $userId, $context, [
                        'from'      => $row['task_priority'],
                        'to'        => $priority,
                        'days_left' => $daysLeft,
                    ]);
                });
                ++$updated;
            }
        }

        if ($actor !== null || $created !== [] || $updated > 0) {
            $this->events->log('system', null, 'renewal_scan', $userId, [], [
                'scanned' => count($rows),
                'created' => count($created),
                'updated' => $updated,
            ]);
        }

        return ['scanned' => count($rows), 'created' => count($created), 'updated' => $updated, 'task_ids' => $created];
    }

    public static function priorityFor(int $daysLeft, int $criticalDays): string
    {
        if ($daysLeft < 0) {
            return 'expired';
        }

        return $daysLeft <= $criticalDays ? 'critical' : 'warning';
    }

    /**
     * @param array<string, mixed>                                                   $row
     * @param array{certificate_id: int, beneficiary_id: int|null, payer_id: int} $context
     */
    private function openTask(array $row, string $priority, int $daysLeft, ?int $userId, array $context): ?int
    {
        // Zadanie trafia do opiekuna certyfikatu, o ile jego konto jest aktywne — MANAGER może je przydzielić inaczej.
        $assignee = $row['owner_deactivated_at'] === null ? (int) $row['user_id'] : null;

        try {
            return Transaction::run($this->db, function () use ($row, $priority, $daysLeft, $userId, $context, $assignee): int {
                $this->db->prepare(
                    "INSERT INTO renewal_tasks (certificate_id, assigned_user_id, status, priority, due_date)
                     VALUES (:certificate_id, :assigned_user_id, 'todo', :priority, :due_date)"
                )->execute([
                    'certificate_id'   => (int) $row['id'],
                    'assigned_user_id' => $assignee,
                    'priority'         => $priority,
                    'due_date'         => $row['expiry_date'],
                ]);
                $taskId = (int) $this->db->lastInsertId();

                $this->events->log('renewal_task', $taskId, 'task_opened', $userId, $context, [
                    'priority'         => $priority,
                    'days_left'        => $daysLeft,
                    'source'           => 'scanner',
                    'assigned_user_id' => $assignee,
                ]);

                return $taskId;
            });
        } catch (PDOException $e) {
            // Równoległe uruchomienie założyło już zadanie (unikalny indeks otwartego zadania).
            if (($e->errorInfo[1] ?? null) === 1062) {
                return null;
            }
            throw $e;
        }
    }
}

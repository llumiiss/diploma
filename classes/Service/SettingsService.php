<?php

declare(strict_types=1);

namespace App\Service;

use App\Settings;
use PDO;

/**
 * Zmiana ustawień procesu odnowień przez administratora (progi i reguły przypomnień).
 */
final class SettingsService
{
    private readonly EventLogger $events;

    public function __construct(private readonly PDO $db, ?EventLogger $events = null)
    {
        $this->events = $events ?? new EventLogger($db);
    }

    /**
     * @return array<string, int>
     */
    public function values(): array
    {
        return Settings::read($this->db);
    }

    /**
     * @return array{values: array<string, int>, definitions: array<string, array{default: int, min: int, max: int}>}
     */
    public function describe(Actor $actor): array
    {
        $actor->authorize('settings.manage');

        $definitions = [];
        foreach (Settings::DEFINITIONS as $key => [$default, $min, $max]) {
            $definitions[$key] = ['default' => $default, 'min' => $min, 'max' => $max];
        }

        return ['values' => $this->values(), 'definitions' => $definitions];
    }

    /**
     * @param array<string, mixed> $data klucze jak w Settings::DEFINITIONS
     * @return array<string, int>
     */
    public function update(Actor $actor, array $data): array
    {
        $actor->authorize('settings.manage');

        $v = new Validator($data);
        $new = [];
        foreach (Settings::DEFINITIONS as $key => [, $min, $max]) {
            $value = $v->int($key, true, $min, $max);
            if ($value !== null) {
                $new[$key] = $value;
            }
        }

        if (!$v->fails() && isset($new['renewal.critical_days'], $new['renewal.warning_days'])
            && $new['renewal.critical_days'] > $new['renewal.warning_days']) {
            $v->addError('renewal.critical_days', 'settings.error.critical_above_warning');
        }
        $v->throwIfFailed();

        $before = $this->values();
        $changes = EventLogger::diff($before, $new, array_keys(Settings::DEFINITIONS));
        if ($changes === []) {
            return $before;
        }

        Transaction::run($this->db, function () use ($actor, $new, $changes): void {
            // Składnia działa w MySQL i MariaDB (bez przestarzałej funkcji VALUES()).
            $stmt = $this->db->prepare(
                'INSERT INTO settings (setting_key, setting_value, updated_by_user_id)
                 VALUES (:setting_key, :setting_value, :user_id)
                 ON DUPLICATE KEY UPDATE setting_value = :setting_value_update, updated_by_user_id = :user_id_update'
            );
            foreach (array_keys($changes) as $key) {
                $stmt->execute([
                    'setting_key'          => $key,
                    'setting_value'        => (string) $new[$key],
                    'user_id'              => $actor->id,
                    'setting_value_update' => (string) $new[$key],
                    'user_id_update'       => $actor->id,
                ]);
            }
            $this->events->log('system', null, 'settings_changed', $actor->id, [], ['changes' => $changes]);
        });

        Settings::forget();

        return $this->values();
    }
}

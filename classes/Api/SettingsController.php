<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\SettingsService;
use PDO;

/**
 * api/settings.php (ADMIN)
 *   GET  bieżące wartości z zakresami
 *   POST {action: update, data: {"renewal.warning_days": 30, …}}
 */
final class SettingsController
{
    private readonly SettingsService $service;

    public function __construct(?PDO $db = null)
    {
        $this->service = new SettingsService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            return ['settings' => $this->service->describe($actor)];
        }

        return match ($request->action()) {
            'update' => ['settings' => ['values' => $this->service->update($actor, $request->data())]],
            default  => throw Params::unknownAction(),
        };
    }
}

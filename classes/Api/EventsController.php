<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\TimelineService;
use PDO;

/**
 * api/events.php (tylko odczyt, ADMIN)
 *   GET ?entity_type=…&event_type=…&user=N|system&date_from=RRRR-MM-DD&date_to=RRRR-MM-DD&q=…
 *       &certificate_id=N&beneficiary_id=N&payer_id=N&page=1&per_page=50
 *   dziennik zdarzeń całego systemu z filtrami i stronicowaniem (F8, F17)
 */
final class EventsController
{
    private readonly TimelineService $service;

    public function __construct(?PDO $db = null, ?TimelineService $service = null)
    {
        $this->service = $service ?? new TimelineService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        $filters = [];
        foreach (['entity_type', 'event_type', 'user', 'date_from', 'date_to', 'q', 'certificate_id', 'beneficiary_id', 'payer_id'] as $key) {
            $value = $request->queryString($key);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return ['journal' => $this->service->journal(
            $actor,
            $filters,
            $request->queryInt('page') ?? 1,
            $request->queryInt('per_page') ?? TimelineService::PER_PAGE
        )];
    }
}

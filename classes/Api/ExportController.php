<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Service\Actor;
use App\Service\ExportService;
use PDO;

/**
 * api/export.php (tylko GET, MANAGER i ADMIN)
 *   ?dataset=certificates|beneficiaries|payers&format=csv|xml[&archived=1][&template=1]
 *   ?dataset=tasks|invitations&format=…
 *   ?dataset=events&format=…&entity_type=…&event_type=…&user=…&date_from=…&date_to=…&q=…   (ADMIN)
 *   ?dataset=beneficiary_card|payer_card&id=N&format=…
 *   ?dataset=schedule&months=12&payer_id=N&certificate_type=…&format=…
 * Odpowiedź to plik do pobrania; błąd — JSON z komunikatem.
 */
final class ExportController
{
    private readonly ExportService $service;

    public function __construct(?PDO $db = null, ?ExportService $service = null)
    {
        $this->service = $service ?? new ExportService($db ?? Database::getInstance()->getConnection());
    }

    public function __invoke(Request $request, Actor $actor): Response
    {
        $filters = [];
        foreach (['entity_type', 'event_type', 'user', 'date_from', 'date_to', 'q', 'certificate_id', 'beneficiary_id', 'payer_id', 'months', 'certificate_type'] as $key) {
            $value = $request->queryString($key);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $file = $this->service->export($actor, $request->queryString('dataset'), $request->queryString('format') ?: 'csv', [
            'archived' => $request->queryBool('archived'),
            'template' => $request->queryBool('template'),
            'id'       => $request->queryInt('id'),
            'filters'  => $filters,
        ]);

        return Response::download($file['body'], $file['content_type'], $file['filename']);
    }
}

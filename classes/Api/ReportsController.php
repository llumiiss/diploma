<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\ReportService;
use PDO;

/**
 * api/reports.php (tylko odczyt)
 *   GET ?view=beneficiary&id=N   karta użytkownika certyfikatu — perspektywa Użytkownika (F6)
 *   GET ?view=payer&id=N         karta płatnika — perspektywa Płatnika (F7)
 *   GET ?view=schedule&months=12&payer_id=N&certificate_type=…   harmonogram wygaśnięć
 */
final class ReportsController
{
    private readonly ReportService $service;

    public function __construct(?PDO $db = null, ?ReportService $service = null)
    {
        $this->service = $service ?? new ReportService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        return match ($request->action()) {
            'beneficiary' => ['report' => $this->service->beneficiaryCard($actor, Params::requireId($request->queryInt('id')))],
            'payer'       => ['report' => $this->service->payerCard($actor, Params::requireId($request->queryInt('id')))],
            'schedule'    => ['report' => $this->service->schedule($actor, [
                'months'           => $request->queryString('months'),
                'payer_id'         => $request->queryString('payer_id'),
                'certificate_type' => $request->queryString('certificate_type'),
            ])],
            default       => throw Params::unknownAction(),
        };
    }
}

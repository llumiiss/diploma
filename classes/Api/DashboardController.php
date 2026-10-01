<?php

declare(strict_types=1);

namespace App\Api;

use App\Cache;
use App\CertificateManager;
use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\BeneficiaryService;
use App\Service\CertificateService;
use App\Service\PayerService;
use PDO;

/**
 * api/dashboard.php
 *   GET               wskaźniki pulpitu firmowego (w zakresie danych konta)
 *   GET ?view=archive rekordy z archiwum (MANAGER, ADMIN)
 */
final class DashboardController
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->action() === 'archive') {
            $payers = new PayerService($this->db);
            $beneficiaries = new BeneficiaryService($this->db, null, $payers);

            return ['archive' => [
                'certificates'  => (new CertificateService($this->db, null, $payers, $beneficiaries))->list($actor, [], true),
                'beneficiaries' => $beneficiaries->list($actor, true),
                'payers'        => $payers->list($actor, true),
            ]];
        }

        $actor->authorize('certificates.view');

        // Klucz zawiera rolę, konto (dla zakresów zależnych od konta) i filtr firm — cache nie może pokazać
        // cudzych liczb (D8) ani liczb z innego filtra.
        $key = 'dashboard:' . $actor->role . ':' . ($actor->seesAllRecords() ? 'all' : $actor->id . ':' . ($actor->beneficiaryId ?? 0))
            . ':f' . ($actor->companyIds === null ? 'all' : md5(implode(',', $actor->companyIds)));
        $summary = Cache::remember($key, Cache::DEFAULT_TTL, function () use ($actor): array {
            $manager = new CertificateManager($this->db);

            return [
                'stats'           => $manager->getStatusStats($actor),
                'payment_summary' => $manager->getPaymentSummary($actor),
                'renewal_summary' => $manager->getRenewalSummary($actor),
            ];
        });

        return ['summary' => $summary];
    }
}

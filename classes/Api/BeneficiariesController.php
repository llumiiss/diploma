<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\BeneficiaryService;
use App\Service\CompanyHistoryService;
use PDO;

/**
 * api/beneficiaries.php
 *   GET  ?archived=1       lista użytkowników certyfikatów
 *   GET  ?id=N             szczegóły z certyfikatami
 *   GET  ?view=options     krótka lista do formularzy
 *   GET  ?view=history&q=&from=&to=&state=   archiwum: historia firm osób (Etap 12)
 *   POST {action: create|update|archive|restore, id?, data?}
 *   POST {action: move_company|add_company|end_company|set_primary_company|correct_company, id, data}
 */
final class BeneficiariesController
{
    private readonly BeneficiaryService $service;
    private readonly CompanyHistoryService $history;

    public function __construct(?PDO $db = null, ?BeneficiaryService $service = null)
    {
        $db = $db ?? Database::getInstance()->getConnection();
        $this->service = $service ?? new BeneficiaryService($db);
        $this->history = new CompanyHistoryService($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            $id = $request->queryInt('id');
            if ($id !== null) {
                return ['beneficiary' => $this->service->get($actor, $id)];
            }

            if ($request->action() === 'options') {
                return ['options' => $this->service->options($actor)];
            }

            if ($request->action() === 'history') {
                $filters = [];
                foreach (['q', 'from', 'to', 'state', 'payer_id', 'beneficiary_id'] as $key) {
                    $value = $request->queryString($key);
                    if ($value !== '') {
                        $filters[$key] = $value;
                    }
                }

                return ['history' => $this->history->search($actor, $filters)];
            }

            return ['beneficiaries' => $this->service->list($actor, $request->queryBool('archived'))];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'create'  => ['beneficiary' => $this->service->create($actor, $request->data())],
            'update'  => ['beneficiary' => $this->service->update($actor, Params::requireId($id), $request->data())],
            'archive' => ['beneficiary' => $this->service->archive($actor, Params::requireId($id))],
            'restore' => ['beneficiary' => $this->service->restore($actor, Params::requireId($id))],
            'move_company'        => ['beneficiary' => $this->service->moveCompany($actor, Params::requireId($id), $request->data())],
            'add_company'         => ['beneficiary' => $this->service->addCompany($actor, Params::requireId($id), $request->data())],
            'end_company'         => ['beneficiary' => $this->service->endCompany($actor, Params::requireId($id), $request->data())],
            'set_primary_company' => ['beneficiary' => $this->service->setPrimaryCompany($actor, Params::requireId($id), (int) ($request->data()['payer_id'] ?? 0))],
            'correct_company'     => ['beneficiary' => $this->service->correctCompany($actor, Params::requireId($id), $request->data())],
            default   => throw Params::unknownAction(),
        };
    }
}

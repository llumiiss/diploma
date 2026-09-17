<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\BeneficiaryService;
use PDO;

/**
 * api/beneficiaries.php
 *   GET  ?archived=1       lista użytkowników certyfikatów
 *   GET  ?id=N             szczegóły z certyfikatami
 *   GET  ?view=options     krótka lista do formularzy
 *   POST {action: create|update|archive|restore, id?, data?}
 */
final class BeneficiariesController
{
    private readonly BeneficiaryService $service;

    public function __construct(?PDO $db = null, ?BeneficiaryService $service = null)
    {
        $this->service = $service ?? new BeneficiaryService($db ?? Database::getInstance()->getConnection());
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

            return ['beneficiaries' => $this->service->list($actor, $request->queryBool('archived'))];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'create'  => ['beneficiary' => $this->service->create($actor, $request->data())],
            'update'  => ['beneficiary' => $this->service->update($actor, Params::requireId($id), $request->data())],
            'archive' => ['beneficiary' => $this->service->archive($actor, Params::requireId($id))],
            'restore' => ['beneficiary' => $this->service->restore($actor, Params::requireId($id))],
            default   => throw Params::unknownAction(),
        };
    }
}

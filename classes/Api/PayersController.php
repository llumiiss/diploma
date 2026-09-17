<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\PayerService;
use PDO;

/**
 * api/payers.php
 *   GET  ?archived=1       lista płatników
 *   GET  ?id=N             szczegóły z osobami i certyfikatami
 *   GET  ?view=options     krótka lista do formularzy
 *   POST {action: create|update|archive|restore, id?, data?}
 */
final class PayersController
{
    private readonly PayerService $service;

    public function __construct(?PDO $db = null, ?PayerService $service = null)
    {
        $this->service = $service ?? new PayerService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            $id = $request->queryInt('id');
            if ($id !== null) {
                return ['payer' => $this->service->get($actor, $id)];
            }

            if ($request->action() === 'options') {
                return ['options' => $this->service->options($actor)];
            }

            return ['payers' => $this->service->list($actor, $request->queryBool('archived'))];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'create'  => ['payer' => $this->service->create($actor, $request->data())],
            'update'  => ['payer' => $this->service->update($actor, Params::requireId($id), $request->data())],
            'archive' => ['payer' => $this->service->archive($actor, Params::requireId($id))],
            'restore' => ['payer' => $this->service->restore($actor, Params::requireId($id))],
            default   => throw Params::unknownAction(),
        };
    }
}

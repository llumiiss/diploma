<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\AccountService;
use App\Service\Actor;
use PDO;

/**
 * api/accounts.php (tylko ADMIN)
 *   GET  lista kont personelu
 *   POST {action: create|update|deactivate|reactivate, id?, data?}
 *   POST {action: transfer, from_user_id, to_user_id}
 */
final class AccountsController
{
    private readonly AccountService $service;

    public function __construct(?PDO $db = null, ?AccountService $service = null)
    {
        $this->service = $service ?? new AccountService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            return ['accounts' => $this->service->list($actor)];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'create'     => ['account' => $this->service->create($actor, $request->data())],
            'update'     => ['account' => $this->service->update($actor, Params::requireId($id), $request->data())],
            'deactivate' => ['account' => $this->service->deactivate($actor, Params::requireId($id))],
            'reactivate' => ['account' => $this->service->reactivate($actor, Params::requireId($id))],
            'transfer'   => ['transferred' => $this->service->transferCertificates(
                $actor,
                Params::requireId($request->bodyInt('from_user_id')),
                Params::requireId($request->bodyInt('to_user_id'))
            )],
            default      => throw Params::unknownAction(),
        };
    }
}

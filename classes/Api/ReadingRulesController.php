<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\ReadingRuleService;
use PDO;

/**
 * api/reading_rules.php (ADMIN)
 *   GET  lista szablonów odczytu i pól, które mogą wskazywać
 *   POST {action: create|update, id?, data: {name, field, label, sender_match?, subject_match?, is_active}}
 *   POST {action: delete, id}
 */
final class ReadingRulesController
{
    private readonly ReadingRuleService $service;

    public function __construct(?PDO $db = null)
    {
        $this->service = new ReadingRuleService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            return ['rules' => $this->service->list($actor), 'fields' => ReadingRuleService::fieldOptions()];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'create' => ['rule' => $this->service->create($actor, $request->data())],
            'update' => ['rule' => $this->service->update($actor, Params::requireId($id), $request->data())],
            'delete' => (function () use ($actor, $id): array {
                $this->service->delete($actor, Params::requireId($id));

                return ['deleted' => true];
            })(),
            default  => throw Params::unknownAction(),
        };
    }
}

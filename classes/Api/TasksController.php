<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\RenewalScanner;
use App\Service\TaskService;
use PDO;

/**
 * api/tasks.php
 *   GET  ?status=open|closed|all|todo|…&assignee=me|unassigned|N&priority=…&q=…&certificate_id=N   lista ToDo
 *   GET  ?id=N           szczegóły z zaproszeniami i historią
 *   GET  ?view=stats     statystyki realizacji (F18)
 *   POST {action: create, data: {certificate_id, assigned_user_id?, note?}}
 *   POST {action: status, id, status, note?}
 *   POST {action: assign, id, user_id|null}
 *   POST {action: renew, id, data: {expiry_date, valid_from?, serial_number?, …, note?}}
 *   POST {action: scan}  uruchomienie skanera odnowień (MANAGER, ADMIN)
 */
final class TasksController
{
    private readonly PDO $db;
    private readonly TaskService $service;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->service = new TaskService($this->db);
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            $id = $request->queryInt('id');
            if ($id !== null) {
                return ['task' => $this->service->get($actor, $id)];
            }

            if ($request->action() === 'stats') {
                return ['stats' => $this->service->stats($actor)];
            }

            return ['tasks' => $this->service->list($actor, [
                'status'         => $request->queryString('status') ?: 'open',
                'assignee'       => $request->queryString('assignee'),
                'priority'       => $request->queryString('priority'),
                'q'              => $request->queryString('q'),
                'certificate_id' => $request->queryInt('certificate_id'),
            ])];
        }

        $id = $request->bodyInt('id');
        $body = $request->body;

        return match ($request->action()) {
            'create' => ['task' => $this->service->create($actor, $request->data())],
            'status' => ['task' => $this->service->changeStatus(
                $actor,
                Params::requireId($id),
                is_string($body['status'] ?? null) ? $body['status'] : '',
                is_string($body['note'] ?? null) ? $body['note'] : null
            )],
            'assign' => ['task' => $this->service->assign($actor, Params::requireId($id), $request->bodyInt('user_id'))],
            'renew'  => $this->service->renew($actor, Params::requireId($id), $request->data()),
            'scan'   => ['scan' => (new RenewalScanner($this->db))->run($actor)],
            default  => throw Params::unknownAction(),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\InvitationService;
use PDO;

/**
 * api/invitations.php
 *   GET  ?status=…&due=1&certificate_id=N&task_id=N   rejestr zaproszeń
 *   GET  ?id=N                                         szczegóły z treścią i historią
 *   GET  ?view=compose&certificate_id=N                dane do okna wysyłki
 *   POST {action: preview|send, data: {certificate_id, template_id, recipient_type, attachment_ids[]}}
 *   POST {action: remind|responded|close|retry, id}
 */
final class InvitationsController
{
    private readonly InvitationService $service;

    public function __construct(?PDO $db = null, ?InvitationService $service = null)
    {
        $this->service = $service ?? new InvitationService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            $id = $request->queryInt('id');
            if ($id !== null) {
                return ['invitation' => $this->service->get($actor, $id)];
            }

            if ($request->action() === 'compose') {
                return ['compose' => $this->service->composeOptions($actor, Params::requireId($request->queryInt('certificate_id')))];
            }

            return ['invitations' => $this->service->list($actor, [
                'status'         => $request->queryString('status'),
                'due'            => $request->queryBool('due'),
                'certificate_id' => $request->queryInt('certificate_id'),
                'task_id'        => $request->queryInt('task_id'),
            ])];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'preview'   => ['preview' => self::withoutCertificate($this->service->preview($actor, $request->data()))],
            'send'      => ['invitation' => $this->service->send($actor, $request->data())],
            'remind'    => ['invitation' => $this->service->remind($actor, Params::requireId($id))],
            'responded' => ['invitation' => $this->service->markResponded($actor, Params::requireId($id))],
            'close'     => ['invitation' => $this->service->close($actor, Params::requireId($id))],
            'retry'     => ['invitation' => $this->service->retry($actor, Params::requireId($id))],
            default     => throw Params::unknownAction(),
        };
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private static function withoutCertificate(array $preview): array
    {
        unset($preview['certificate']);

        return $preview;
    }
}

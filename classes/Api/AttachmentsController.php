<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Service\Actor;
use App\Service\AttachmentService;
use PDO;

/**
 * api/attachments.php
 *   GET  lista załączników (OPERATOR+)
 *   GET  ?id=N&download=1       pobranie pliku (OPERATOR+)
 *   POST multipart: action=upload, file   (ADMIN)
 *   POST {action: delete, id}              (ADMIN, tylko nieużywane)
 */
final class AttachmentsController
{
    private readonly AttachmentService $service;

    public function __construct(?PDO $db = null, ?AttachmentService $service = null)
    {
        $this->service = $service ?? new AttachmentService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>|Response
     */
    public function __invoke(Request $request, Actor $actor): array|Response
    {
        if ($request->isRead()) {
            $id = $request->queryInt('id');
            if ($id !== null && $request->queryBool('download')) {
                $file = $this->service->download($actor, $id);
                $content = file_get_contents($file['path']);

                return Response::download($content === false ? '' : $content, $file['mime'], $file['name']);
            }

            return ['attachments' => $this->service->list($actor)];
        }

        return match ($request->action()) {
            'upload' => ['attachment' => $this->service->upload($actor, is_array($request->files['file'] ?? null) ? $request->files['file'] : [])],
            'delete' => (function () use ($actor, $request): array {
                $this->service->delete($actor, Params::requireId($request->bodyInt('id')));

                return ['deleted' => true];
            })(),
            default  => throw Params::unknownAction(),
        };
    }
}

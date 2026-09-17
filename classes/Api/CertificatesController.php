<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\CertificateService;
use PDO;

/**
 * api/certificates.php
 *   GET  ?q=…&archived=1   lista certyfikatów
 *   GET  ?id=N             szczegóły z historią
 *   GET  ?view=options     dane do formularza
 *   POST {action: create|update|archive|restore, id?, data?}
 */
final class CertificatesController
{
    private readonly CertificateService $service;

    public function __construct(?PDO $db = null, ?CertificateService $service = null)
    {
        $this->service = $service ?? new CertificateService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            $id = $request->queryInt('id');
            if ($id !== null) {
                return ['certificate' => $this->service->get($actor, $id)];
            }

            if ($request->action() === 'options') {
                return ['options' => $this->service->formOptions($actor)];
            }

            return ['certificates' => $this->service->list(
                $actor,
                ['q' => $request->queryString('q')],
                $request->queryBool('archived')
            )];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'create'  => ['certificate' => $this->service->create($actor, $request->data())],
            'update'  => ['certificate' => $this->service->update($actor, Params::requireId($id), $request->data())],
            'archive' => ['certificate' => $this->service->archive($actor, Params::requireId($id))],
            'restore' => ['certificate' => $this->service->restore($actor, Params::requireId($id))],
            default   => throw Params::unknownAction(),
        };
    }
}

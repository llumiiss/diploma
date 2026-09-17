<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\CertificateService;
use App\Service\TemplateRenderer;
use App\Service\TemplateService;
use PDO;

/**
 * api/templates.php
 *   GET  lista szablonów (ADMIN)
 *   GET  ?id=N                szczegóły z załącznikami (ADMIN)
 *   GET  ?view=options        aktywne szablony do wysyłki (OPERATOR+)
 *   POST {action: create|update, id?, data: {code, locale, name, subject, body_html, body_text, is_active, attachment_ids[]}}
 *   POST {action: preview, data: {subject, body_html, body_text, locale, certificate_id?}}
 */
final class TemplatesController
{
    private readonly PDO $db;
    private readonly TemplateService $service;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->service = new TemplateService($this->db);
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            $id = $request->queryInt('id');
            if ($id !== null) {
                return ['template' => $this->service->get($actor, $id)];
            }

            if ($request->action() === 'options') {
                return ['options' => $this->service->options($actor)];
            }

            return ['templates' => $this->service->list($actor), 'placeholders' => TemplateRenderer::PLACEHOLDERS];
        }

        $id = $request->bodyInt('id');

        return match ($request->action()) {
            'create'  => ['template' => $this->service->create($actor, $request->data())],
            'update'  => ['template' => $this->service->update($actor, Params::requireId($id), $request->data())],
            'preview' => ['preview' => $this->service->previewDraft($actor, $request->data(), new CertificateService($this->db))],
            default   => throw Params::unknownAction(),
        };
    }
}

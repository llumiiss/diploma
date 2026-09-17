<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Exchange\Columns;
use App\Exchange\EmlParser;
use App\Http\Request;
use App\Http\UploadedFile;
use App\Service\Actor;
use App\Service\EmlImportService;
use App\Service\ImportService;
use App\Service\ServiceException;
use PDO;

/**
 * api/import.php (ADMIN)
 *   GET  ?view=columns&dataset=payers|beneficiaries|certificates    kolumny pliku importu
 *   POST multipart: action=preview|commit, dataset, mode=skip|update, file[, attachment=N dla pliku .eml]
 *   POST multipart: action=eml_analyze, file
 *   POST multipart: action=eml_apply, file, actions (JSON: create_beneficiary, responded, note_certificates,
 *                   note_beneficiaries, note_payers)
 */
final class ImportController
{
    private readonly PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            $actor->authorize('import.run');
            $dataset = $request->queryString('dataset');
            if (!Columns::isImportable($dataset)) {
                throw ServiceException::badRequest(\__('exchange.error.unknown_dataset'));
            }

            return ['columns' => Columns::importColumns($dataset)];
        }

        $actor->authorize('import.run');
        $action = $request->action();
        $body = $request->body;

        return match ($action) {
            'preview', 'commit' => (function () use ($request, $actor, $action, $body): array {
                $file = UploadedFile::fromRequest($request->files, 'file', EmlParser::MAX_BYTES);
                $service = new ImportService($this->db);
                $options = [
                    'mode'       => is_string($body['mode'] ?? null) ? $body['mode'] : 'skip',
                    'attachment' => isset($body['attachment']) && is_numeric($body['attachment']) ? (int) $body['attachment'] : null,
                ];
                $dataset = is_string($body['dataset'] ?? null) ? $body['dataset'] : '';

                return ['import' => $action === 'commit'
                    ? $service->commit($actor, $dataset, $file->name, $file->content, $options)
                    : $service->preview($actor, $dataset, $file->name, $file->content, $options)];
            })(),
            'eml_analyze' => (function () use ($request, $actor): array {
                $file = UploadedFile::fromRequest($request->files, 'file', EmlParser::MAX_BYTES);

                return ['eml' => (new EmlImportService($this->db))->analyze($actor, $file->name, $file->content)];
            })(),
            'eml_apply' => (function () use ($request, $actor, $body): array {
                $file = UploadedFile::fromRequest($request->files, 'file', EmlParser::MAX_BYTES);
                $actions = is_string($body['actions'] ?? null) ? json_decode($body['actions'], true) : null;
                if (!is_array($actions)) {
                    throw ServiceException::badRequest(\__('api.error.invalid_json'));
                }

                return ['result' => (new EmlImportService($this->db))->apply($actor, $file->name, $file->content, $actions)];
            })(),
            default => throw Params::unknownAction(),
        };
    }
}

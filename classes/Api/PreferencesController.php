<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\CompanyFilterService;
use PDO;

/**
 * api/preferences.php — ustawienia panelu zapisane przy koncie.
 *   GET   ?view=company_filter                          bieżący filtr firm i firmy do wyboru
 *   POST  {action: set_company_filter, data: {mode: all|one|list, ids: [..]}}
 */
final class PreferencesController
{
    private readonly CompanyFilterService $filter;

    public function __construct(?PDO $db = null, ?CompanyFilterService $filter = null)
    {
        $this->filter = $filter ?? new CompanyFilterService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        if ($request->isRead()) {
            return ['company_filter' => $this->filter->get($actor)];
        }

        return match ($request->action()) {
            'set_company_filter' => ['company_filter' => $this->setCompanyFilter($actor, $request)],
            default              => throw Params::unknownAction(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function setCompanyFilter(Actor $actor, Request $request): array
    {
        $data = $request->data();
        $ids = $data['ids'] ?? [];

        return $this->filter->set($actor, is_string($data['mode'] ?? null) ? $data['mode'] : '', is_array($ids) ? $ids : []);
    }
}

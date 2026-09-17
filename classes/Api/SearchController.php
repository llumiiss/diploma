<?php

declare(strict_types=1);

namespace App\Api;

use App\Database;
use App\Http\Request;
use App\Service\Actor;
use App\Service\SearchService;
use PDO;

/**
 * api/search.php (tylko odczyt)
 *   GET ?q=tekst&limit=6   wyszukiwarka globalna: certyfikaty, osoby i płatnicy z powiązaniami (F16)
 */
final class SearchController
{
    private readonly SearchService $service;

    public function __construct(?PDO $db = null, ?SearchService $service = null)
    {
        $this->service = $service ?? new SearchService($db ?? Database::getInstance()->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Actor $actor): array
    {
        return ['search' => $this->service->search(
            $actor,
            $request->queryString('q'),
            $request->queryInt('limit') ?? SearchService::DEFAULT_LIMIT
        )];
    }
}

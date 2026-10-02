<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Filtr firm operatora (Etap 10): ten sam użytkownik może pracować na wszystkich firmach,
 * na jednej wybranej albo na liście wybranych. Wybór jest zapisany przy koncie (users.company_filter)
 * i zawęża listy oraz wskaźniki w panelu — zob. Visibility::companyFilter. Nie jest to kontrola
 * dostępu: rekordy spoza wyboru nadal da się otworzyć (np. z wyszukiwarki), a wybrać można tylko
 * firmy, które konto widzi.
 */
final class CompanyFilterService
{
    public const MODES = ['all', 'one', 'list'];
    public const MAX_COMPANIES = 200;

    private readonly PayerService $payers;

    public function __construct(private readonly PDO $db, ?PayerService $payers = null)
    {
        $this->payers = $payers ?? new PayerService($db);
    }

    /**
     * Zapisany wybór razem z listą firm do wyboru (tylko aktywne i widoczne dla konta).
     *
     * @return array{mode: string, ids: list<int>, available: list<array{id: int, company_name: string, tax_id: ?string, city: ?string}>}
     */
    public function get(Actor $actor): array
    {
        $actor->authorize('payers.view');

        $stmt = $this->db->prepare('SELECT company_filter FROM users WHERE id = :id');
        $stmt->execute(['id' => $actor->id]);
        $raw = $stmt->fetchColumn();
        $ids = Actor::parseCompanyFilter(is_string($raw) ? $raw : null);
        $mode = 'all';
        if ($ids !== null) {
            $decoded = json_decode((string) $raw, true);
            $mode = is_array($decoded) && ($decoded['mode'] ?? '') === 'one' ? 'one' : 'list';
        }

        return [
            'mode'      => $mode,
            'ids'       => $ids ?? [],
            'available' => $this->payers->options($actor),
        ];
    }

    /**
     * @param list<mixed> $ids
     * @return array{mode: string, ids: list<int>, available: list<array{id: int, company_name: string, tax_id: ?string, city: ?string}>}
     */
    public function set(Actor $actor, string $mode, array $ids): array
    {
        $actor->authorize('payers.view');
        if ($actor->isSystem()) {
            throw ServiceException::badRequest(\__('api.error.unknown_action'));
        }

        if (!in_array($mode, self::MODES, true)) {
            throw ServiceException::validation(['mode' => \__('validation.choice')]);
        }

        if ($mode === 'all') {
            $this->db->prepare('UPDATE users SET company_filter = NULL WHERE id = :id')->execute(['id' => $actor->id]);

            return $this->get($actor);
        }

        $clean = [];
        foreach ($ids as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }
        $clean = array_values($clean);

        if ($clean === [] || count($clean) > self::MAX_COMPANIES || ($mode === 'one' && count($clean) !== 1)) {
            throw ServiceException::validation(['ids' => \__($mode === 'one' ? 'filter.error.one' : 'filter.error.list', ['max' => (string) self::MAX_COMPANIES])]);
        }

        $available = array_column($this->payers->options($actor), 'id');
        foreach ($clean as $id) {
            if (!in_array($id, $available, true)) {
                throw ServiceException::validation(['ids' => \__('filter.error.unavailable')]);
            }
        }

        $this->db->prepare('UPDATE users SET company_filter = :filter WHERE id = :id')->execute([
            'filter' => json_encode(['mode' => $mode, 'ids' => $clean]),
            'id'     => $actor->id,
        ]);

        return $this->get($actor);
    }
}

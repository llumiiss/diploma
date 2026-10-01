<?php

declare(strict_types=1);

namespace App\Service;

use App\Rbac;

/**
 * Zakres danych widocznych dla konta — decyzja D8 (docs/MAPA_PROJEKTU.md §8) rozszerzona w Etapie 10
 * o role stanowisk (App\Rbac). Zakres wynika z roli (Rbac::scope()):
 *
 *  - all (ADMIN, DIRECTOR, MANAGER, ACCOUNTANT) — dane całej organizacji,
 *  - own (OPERATOR) — certyfikaty, których konto jest opiekunem albo ma przydzielone zadanie odnowienia;
 *    użytkownicy certyfikatów i firmy, które konto samo wprowadziło albo które są powiązane z jego
 *    widocznymi certyfikatami,
 *  - technical (IT) — wszystkie certyfikaty techniczne (bez kwalifikowanych); osoby i firmy jak wyżej,
 *  - personal (EMPLOYEE) — tylko własne certyfikaty (opiekun, przydzielone zadanie albo certyfikat
 *    użytkownika powiązanego z kontem) oraz firmy, do których te certyfikaty należą. Pracownik nie widzi
 *    rekordów, które tylko „wprowadził”, bo niczego nie wprowadza.
 *
 * Metody zwracają warunek SQL i parametry. Każde wywołanie nadaje parametrom i aliasom
 * unikalne nazwy, bo przy wyłączonej emulacji PDO ten sam parametr nie może wystąpić dwa razy.
 */
final class Visibility
{
    private static int $counter = 0;

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    public static function certificates(Actor $actor, string $alias = 'c'): array
    {
        if ($actor->seesAllRecords()) {
            return ['1 = 1', []];
        }

        return self::certificateCondition($actor, $alias);
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    public static function beneficiaries(Actor $actor, string $alias = 'b'): array
    {
        if ($actor->seesAllRecords()) {
            return ['1 = 1', []];
        }

        return self::beneficiaryCondition($actor, $alias);
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    public static function payers(Actor $actor, string $alias = 'p'): array
    {
        if ($actor->seesAllRecords()) {
            return ['1 = 1', []];
        }

        $n = self::next();
        $certAlias = 'vis_pc' . $n;
        $benAlias = 'vis_pb' . $n;
        [$certSql, $certParams] = self::certificateCondition($actor, $certAlias);
        [$benSql, $benParams] = self::beneficiaryCondition($actor, $benAlias);

        $parts = [];
        $params = [];
        if (self::mayCreateRecords($actor)) {
            $created = 'vis_p' . $n;
            $parts[] = "{$alias}.created_by_user_id = :{$created}";
            $params[$created] = $actor->id;
        }
        $parts[] = "EXISTS (SELECT 1 FROM certificates {$certAlias} WHERE {$certAlias}.payer_id = {$alias}.id"
            . " AND {$certAlias}.archived_at IS NULL AND {$certSql})";
        $parts[] = "EXISTS (SELECT 1 FROM beneficiaries {$benAlias} WHERE {$benAlias}.payer_id = {$alias}.id"
            . " AND {$benAlias}.archived_at IS NULL AND {$benSql})";

        return ['(' . implode(' OR ', $parts) . ')', $params + $certParams + $benParams];
    }

    /**
     * Warunek zawężający listę do wybranych firm (filtr firm operatora — Etap 10). Dotyczy tylko
     * odczytu list i wskaźników; zapisy sprawdzają widoczność rekordu niezależnie od filtra.
     *
     * @param string $column pełne odwołanie do kolumny z identyfikatorem firmy, np. „c.payer_id”
     * @return array{0: string, 1: array<string, int>}
     */
    public static function companyFilter(Actor $actor, string $column): array
    {
        $ids = $actor->companyIds;
        if ($ids === null) {
            return ['1 = 1', []];
        }
        if ($ids === []) {
            return ['1 = 0', []];
        }

        $n = self::next();
        $names = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $name = 'vis_cf' . $n . '_' . $i;
            $names[] = ':' . $name;
            $params[$name] = $id;
        }

        return ["{$column} IN (" . implode(', ', $names) . ')', $params];
    }

    /**
     * Czy rola wprowadza rekordy, a więc ma widzieć także te, które sama dodała (D8).
     */
    private static function mayCreateRecords(Actor $actor): bool
    {
        return $actor->can('beneficiaries.create') || $actor->can('payers.create') || $actor->can('certificates.create');
    }

    /**
     * Warunek widoczności certyfikatu dla ról o ograniczonym zakresie.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private static function certificateCondition(Actor $actor, string $alias): array
    {
        if ($actor->scope() === Rbac::SCOPE_TECHNICAL) {
            $types = "'" . implode("', '", Rbac::TECHNICAL_TYPES) . "'";

            return ["{$alias}.certificate_type IN ({$types})", []];
        }

        $n = self::next();
        $owner = 'vis_o' . $n;
        $assignee = 'vis_a' . $n;
        $task = 'vis_t' . $n;

        $parts = [
            "{$alias}.user_id = :{$owner}",
            "EXISTS (SELECT 1 FROM renewal_tasks {$task} WHERE {$task}.certificate_id = {$alias}.id"
            . " AND {$task}.assigned_user_id = :{$assignee})",
        ];
        $params = [$owner => $actor->id, $assignee => $actor->id];

        // Pracownik widzi też certyfikaty, w których on sam jest użytkownikiem certyfikatu.
        if ($actor->scope() === Rbac::SCOPE_PERSONAL && $actor->beneficiaryId !== null) {
            $linked = 'vis_l' . $n;
            $parts[] = "{$alias}.beneficiary_id = :{$linked}";
            $params[$linked] = $actor->beneficiaryId;
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private static function beneficiaryCondition(Actor $actor, string $alias): array
    {
        $n = self::next();
        $certAlias = 'vis_bc' . $n;
        [$certSql, $certParams] = self::certificateCondition($actor, $certAlias);

        $parts = [];
        $params = [];
        if (self::mayCreateRecords($actor)) {
            $created = 'vis_b' . $n;
            $parts[] = "{$alias}.created_by_user_id = :{$created}";
            $params[$created] = $actor->id;
        }
        if ($actor->scope() === Rbac::SCOPE_PERSONAL && $actor->beneficiaryId !== null) {
            $linked = 'vis_bl' . $n;
            $parts[] = "{$alias}.id = :{$linked}";
            $params[$linked] = $actor->beneficiaryId;
        }
        $parts[] = "EXISTS (SELECT 1 FROM certificates {$certAlias} WHERE {$certAlias}.beneficiary_id = {$alias}.id"
            . " AND {$certAlias}.archived_at IS NULL AND {$certSql})";

        return ['(' . implode(' OR ', $parts) . ')', $params + $certParams];
    }

    private static function next(): int
    {
        return ++self::$counter;
    }
}

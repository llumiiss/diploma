<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Zakres danych widocznych dla konta — decyzja D8 (docs/MAPA_PROJEKTU.md §8).
 *
 * MANAGER i ADMIN widzą dane całej organizacji. OPERATOR widzi:
 *  - certyfikaty, których jest opiekunem, oraz te, do których przydzielono mu zadanie odnowienia,
 *  - użytkowników certyfikatów, których sam wprowadził albo którzy są powiązani z jego
 *    widocznymi certyfikatami,
 *  - płatników, których sam wprowadził albo którzy są powiązani z jego widocznymi
 *    certyfikatami lub widocznymi użytkownikami certyfikatów.
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

        return self::certificateCondition($actor->id, $alias);
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    public static function beneficiaries(Actor $actor, string $alias = 'b'): array
    {
        if ($actor->seesAllRecords()) {
            return ['1 = 1', []];
        }

        return self::beneficiaryCondition($actor->id, $alias);
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
        $created = 'vis_p' . $n;
        $certAlias = 'vis_pc' . $n;
        $benAlias = 'vis_pb' . $n;
        [$certSql, $certParams] = self::certificateCondition($actor->id, $certAlias);
        [$benSql, $benParams] = self::beneficiaryCondition($actor->id, $benAlias);

        $sql = "({$alias}.created_by_user_id = :{$created}"
            . " OR EXISTS (SELECT 1 FROM certificates {$certAlias} WHERE {$certAlias}.payer_id = {$alias}.id"
            . " AND {$certAlias}.archived_at IS NULL AND {$certSql})"
            . " OR EXISTS (SELECT 1 FROM beneficiaries {$benAlias} WHERE {$benAlias}.payer_id = {$alias}.id"
            . " AND {$benAlias}.archived_at IS NULL AND {$benSql}))";

        return [$sql, [$created => $actor->id] + $certParams + $benParams];
    }

    /**
     * Warunek dla zapytań, które przyjmują identyfikator opiekuna zamiast obiektu Actor
     * (odczyty wskaźników pulpitu w CertificateManager).
     *
     * @return array{0: string, 1: array<string, int>}
     */
    public static function certificatesForUserId(int $userId, string $alias = 'c'): array
    {
        return self::certificateCondition($userId, $alias);
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private static function certificateCondition(int $userId, string $alias): array
    {
        $n = self::next();
        $owner = 'vis_o' . $n;
        $assignee = 'vis_a' . $n;
        $task = 'vis_t' . $n;

        $sql = "({$alias}.user_id = :{$owner}"
            . " OR EXISTS (SELECT 1 FROM renewal_tasks {$task} WHERE {$task}.certificate_id = {$alias}.id"
            . " AND {$task}.assigned_user_id = :{$assignee}))";

        return [$sql, [$owner => $userId, $assignee => $userId]];
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private static function beneficiaryCondition(int $userId, string $alias): array
    {
        $n = self::next();
        $created = 'vis_b' . $n;
        $certAlias = 'vis_bc' . $n;
        [$certSql, $certParams] = self::certificateCondition($userId, $certAlias);

        $sql = "({$alias}.created_by_user_id = :{$created}"
            . " OR EXISTS (SELECT 1 FROM certificates {$certAlias} WHERE {$certAlias}.beneficiary_id = {$alias}.id"
            . " AND {$certAlias}.archived_at IS NULL AND {$certSql}))";

        return [$sql, [$created => $userId] + $certParams];
    }

    private static function next(): int
    {
        return ++self::$counter;
    }
}

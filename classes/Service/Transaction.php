<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use Throwable;

final class Transaction
{
    /**
     * Wykonuje operację w transakcji. Gdy transakcja jest już otwarta (np. import wielu
     * rekordów albo test), operacja dołącza do niej zamiast otwierać zagnieżdżoną.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(PDO $db, callable $callback): mixed
    {
        if ($db->inTransaction()) {
            return $callback();
        }

        $db->beginTransaction();
        try {
            $result = $callback();
            $db->commit();

            return $result;
        } catch (Throwable $e) {
            self::rollBackIfOpen($db);
            throw $e;
        }
    }

    /**
     * MySQL zamyka transakcję niejawnie przy poleceniach DDL — wtedy nie ma już czego wycofywać.
     */
    private static function rollBackIfOpen(PDO $db): void
    {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

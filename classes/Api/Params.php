<?php

declare(strict_types=1);

namespace App\Api;

use App\Service\ServiceException;

final class Params
{
    /**
     * @throws ServiceException gdy żądanie nie wskazuje rekordu
     */
    public static function requireId(?int $id): int
    {
        if ($id === null) {
            throw ServiceException::badRequest(\__('api.error.missing_id'));
        }

        return $id;
    }

    public static function unknownAction(): ServiceException
    {
        return ServiceException::badRequest(\__('api.error.unknown_action'));
    }
}

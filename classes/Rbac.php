<?php

declare(strict_types=1);

namespace App;

/**
 * Zhierarchizowany plan kont: ADMIN > MANAGER > OPERATOR.
 * Wyższa ranga obejmuje uprawnienia niższych.
 */
final class Rbac
{
    public const ADMIN = 'ADMIN';
    public const MANAGER = 'MANAGER';
    public const OPERATOR = 'OPERATOR';

    /** @var array<string, int> */
    private const RANKS = [
        self::OPERATOR => 1,
        self::MANAGER  => 2,
        self::ADMIN    => 3,
    ];

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return array_keys(self::RANKS);
    }

    public static function isRole(?string $role): bool
    {
        return isset(self::RANKS[self::normalize($role)]);
    }

    public static function rank(?string $role): int
    {
        return self::RANKS[self::normalize($role)] ?? 0;
    }

    public static function atLeast(?string $role, string $required): bool
    {
        return self::rank($role) >= self::rank($required);
    }

    /**
     * Czy rola widzi dane całej organizacji (wszystkie certyfikaty, osoby, płatników),
     * czy tylko własne rekordy.
     */
    public static function seesAllRecords(?string $role): bool
    {
        return self::atLeast($role, self::MANAGER);
    }

    private static function normalize(?string $role): string
    {
        return strtoupper(trim((string) $role));
    }
}

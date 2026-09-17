<?php

declare(strict_types=1);

namespace App;

/**
 * Zhierarchizowany plan kont: ADMIN > MANAGER > OPERATOR.
 * Wyższa ranga obejmuje uprawnienia niższych.
 *
 * Uprawnienia do akcji (docs/MAPA_PROJEKTU.md §2.3) są przypisane do najniższej roli,
 * która może je wykonać. Zakres danych (które rekordy widać) opisuje osobno
 * seesAllRecords() i klasa App\Service\Visibility.
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
     * Uprawnienie → minimalna rola.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        // Ewidencja (Etap 2) — OPERATOR pracuje na rekordach, które widzi.
        'certificates.view'     => self::OPERATOR,
        'certificates.create'   => self::OPERATOR,
        'certificates.update'   => self::OPERATOR,
        'certificates.assign_owner' => self::MANAGER,
        'certificates.archive'  => self::MANAGER,
        'beneficiaries.view'    => self::OPERATOR,
        'beneficiaries.create'  => self::OPERATOR,
        'beneficiaries.update'  => self::OPERATOR,
        'beneficiaries.archive' => self::MANAGER,
        'payers.view'           => self::OPERATOR,
        'payers.create'         => self::OPERATOR,
        'payers.update'         => self::OPERATOR,
        'payers.archive'        => self::MANAGER,
        'archive.view'          => self::MANAGER,
        // Proces odnowień (Etap 3).
        'tasks.view'            => self::OPERATOR,
        'tasks.update'          => self::OPERATOR,
        'tasks.assign'          => self::MANAGER,
        'tasks.stats'           => self::MANAGER,
        'invitations.view'      => self::OPERATOR,
        'invitations.send'      => self::OPERATOR,
        'templates.manage'      => self::ADMIN,
        'attachments.manage'    => self::ADMIN,
        'scanner.run'           => self::MANAGER,
        // Raporty i przegląd (Etap 4).
        'reports.view'          => self::OPERATOR,
        'search.use'            => self::OPERATOR,
        'events.view_all'       => self::ADMIN,
        // Wymiana danych (Etap 5).
        'export.run'            => self::MANAGER,
        'import.run'            => self::ADMIN,
        // Administracja.
        'accounts.manage'       => self::ADMIN,
        'settings.manage'       => self::ADMIN,
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

    /**
     * Czy rola może wykonać akcję. Nieznane uprawnienie oznacza brak dostępu,
     * żeby literówka w kodzie nie otwierała funkcji wszystkim.
     */
    public static function can(?string $role, string $permission): bool
    {
        $required = self::PERMISSIONS[$permission] ?? null;

        return $required !== null && self::rank($role) > 0 && self::atLeast($role, $required);
    }

    /**
     * Lista uprawnień roli — przekazywana do interfejsu, który na jej podstawie ukrywa przyciski.
     * Właściwa kontrola odbywa się zawsze po stronie serwera.
     *
     * @return list<string>
     */
    public static function permissionsFor(?string $role): array
    {
        return array_values(array_filter(
            array_keys(self::PERMISSIONS),
            static fn (string $permission): bool => self::can($role, $permission)
        ));
    }

    public static function normalize(?string $role): string
    {
        return strtoupper(trim((string) $role));
    }
}

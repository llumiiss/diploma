<?php

declare(strict_types=1);

namespace App;

/**
 * Role kont personelu i macierz uprawnień.
 *
 * Do Etapu 9 role tworzyły hierarchię ADMIN > MANAGER > OPERATOR. Od Etapu 10 są to profile
 * stanowisk w firmie — każdy ma własny zestaw uprawnień do akcji (can()) i własny zakres danych
 * (scope()), więc księgowa nie musi być „wyższa” od operatora, żeby widzieć płatności całej firmy:
 *
 *   ADMIN       administrator systemu — wszystkie uprawnienia, dane całej organizacji
 *   DIRECTOR    szef — podgląd całej organizacji, raporty, statystyki, eksport, przydział opiekunów i zadań
 *   MANAGER     menedżer — ewidencja całej organizacji, archiwum, przydziały, raporty, eksport
 *   ACCOUNTANT  księgowa — podgląd całej organizacji, płatności i rabaty, dane firm, raporty, eksport
 *   IT          informatyk — certyfikaty techniczne (SSL, podpis kodu, domeny, SaaS, chmura) całej organizacji
 *   OPERATOR    operator — własne i przydzielone certyfikaty oraz osoby i firmy z nimi powiązane (D8),
 *               obsługa wniosków przesłanych e-mailem
 *   EMPLOYEE    pracownik — wyłącznie własne certyfikaty (jako opiekun albo jako użytkownik certyfikatu
 *               powiązany z kontem) i firmy, do których te certyfikaty należą; tylko odczyt
 *
 * Zakres danych (które rekordy widać) realizuje App\Service\Visibility na podstawie scope().
 * Ukrycie przycisku w interfejsie nie jest kontrolą dostępu — każda usługa woła Actor::authorize().
 */
final class Rbac
{
    public const ADMIN = 'ADMIN';
    public const DIRECTOR = 'DIRECTOR';
    public const MANAGER = 'MANAGER';
    public const ACCOUNTANT = 'ACCOUNTANT';
    public const IT = 'IT';
    public const OPERATOR = 'OPERATOR';
    public const EMPLOYEE = 'EMPLOYEE';

    /** Dane całej organizacji. */
    public const SCOPE_ALL = 'all';
    /** Certyfikaty, których konto jest opiekunem albo ma przydzielone zadanie, oraz osoby i firmy z nimi powiązane (D8). */
    public const SCOPE_OWN = 'own';
    /** Wszystkie certyfikaty techniczne (poza kwalifikowanymi) oraz osoby i firmy z nimi powiązane. */
    public const SCOPE_TECHNICAL = 'technical';
    /** Wyłącznie własne certyfikaty (opiekun albo powiązany użytkownik certyfikatu) i ich firmy. */
    public const SCOPE_PERSONAL = 'personal';

    /** Typy certyfikatów obsługiwane przez informatyka. */
    public const TECHNICAL_TYPES = ['SSL_CERTIFICATE', 'CODE_SIGNING', 'DOMAIN', 'SAAS', 'CLOUD_SUPPORT', 'OTHER'];

    /**
     * Wszystkie uprawnienia systemu. Nieznane uprawnienie oznacza brak dostępu dla każdego,
     * także administratora — literówka w kodzie nie otwiera funkcji.
     *
     * @var list<string>
     */
    private const ALL = [
        // Ewidencja (Etap 2).
        'certificates.view', 'certificates.create', 'certificates.update', 'certificates.update_payment',
        'certificates.assign_owner', 'certificates.archive',
        'beneficiaries.view', 'beneficiaries.create', 'beneficiaries.update', 'beneficiaries.archive',
        'payers.view', 'payers.create', 'payers.update', 'payers.archive',
        'archive.view',
        // Proces odnowień (Etap 3).
        'tasks.view', 'tasks.update', 'tasks.assign', 'tasks.stats',
        'invitations.view', 'invitations.send',
        'templates.manage', 'attachments.manage', 'scanner.run',
        // Raporty i przegląd (Etap 4).
        'reports.view', 'search.use', 'events.view_all',
        // Wymiana danych (Etap 5).
        'export.run', 'import.run',
        // Wnioski o certyfikat przesłane e-mailem (Etap 10).
        'registrations.view', 'registrations.review', 'registrations.intake',
        // Powiadomienia wewnętrzne (Etap 10).
        'notifications.use', 'notifications.broadcast',
        // Administracja.
        'accounts.manage', 'settings.manage',
    ];

    private const MANAGER_CAN = [
        'certificates.view', 'certificates.create', 'certificates.update', 'certificates.update_payment',
        'certificates.assign_owner', 'certificates.archive',
        'beneficiaries.view', 'beneficiaries.create', 'beneficiaries.update', 'beneficiaries.archive',
        'payers.view', 'payers.create', 'payers.update', 'payers.archive',
        'archive.view',
        'tasks.view', 'tasks.update', 'tasks.assign', 'tasks.stats',
        'invitations.view', 'invitations.send',
        'scanner.run',
        'reports.view', 'search.use',
        'export.run',
        'registrations.view', 'registrations.review', 'registrations.intake',
        'notifications.use',
    ];

    private const OPERATOR_CAN = [
        'certificates.view', 'certificates.create', 'certificates.update', 'certificates.update_payment',
        'beneficiaries.view', 'beneficiaries.create', 'beneficiaries.update',
        'payers.view', 'payers.create', 'payers.update',
        'tasks.view', 'tasks.update',
        'invitations.view', 'invitations.send',
        'reports.view', 'search.use',
        'registrations.view', 'registrations.review', 'registrations.intake',
        'notifications.use',
    ];

    private const DIRECTOR_CAN = [
        'certificates.view', 'certificates.assign_owner',
        'beneficiaries.view',
        'payers.view',
        'archive.view',
        'tasks.view', 'tasks.assign', 'tasks.stats',
        'invitations.view',
        'reports.view', 'search.use',
        'export.run',
        'registrations.view',
        'notifications.use',
    ];

    private const ACCOUNTANT_CAN = [
        'certificates.view', 'certificates.update_payment',
        'beneficiaries.view',
        'payers.view', 'payers.update',
        'reports.view', 'search.use',
        'export.run',
        'notifications.use',
    ];

    private const IT_CAN = [
        'certificates.view', 'certificates.create', 'certificates.update',
        'beneficiaries.view',
        'payers.view',
        'tasks.view', 'tasks.update',
        'invitations.view', 'invitations.send',
        'reports.view', 'search.use',
        'notifications.use',
    ];

    private const EMPLOYEE_CAN = [
        'certificates.view',
        'beneficiaries.view',
        'payers.view',
        'tasks.view',
        'reports.view', 'search.use',
        'notifications.use',
    ];

    /**
     * Rola → zakres danych i lista uprawnień (null = wszystkie). Kolejność kluczy to kolejność
     * w listach wyboru: od najszerszych uprawnień do najwęższych.
     *
     * @var array<string, array{scope: string, can: list<string>|null}>
     */
    private const PROFILES = [
        self::ADMIN      => ['scope' => self::SCOPE_ALL, 'can' => null],
        self::DIRECTOR   => ['scope' => self::SCOPE_ALL, 'can' => self::DIRECTOR_CAN],
        self::MANAGER    => ['scope' => self::SCOPE_ALL, 'can' => self::MANAGER_CAN],
        self::ACCOUNTANT => ['scope' => self::SCOPE_ALL, 'can' => self::ACCOUNTANT_CAN],
        self::IT         => ['scope' => self::SCOPE_TECHNICAL, 'can' => self::IT_CAN],
        self::OPERATOR   => ['scope' => self::SCOPE_OWN, 'can' => self::OPERATOR_CAN],
        self::EMPLOYEE   => ['scope' => self::SCOPE_PERSONAL, 'can' => self::EMPLOYEE_CAN],
    ];

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return array_keys(self::PROFILES);
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return self::ALL;
    }

    public static function isRole(?string $role): bool
    {
        return isset(self::PROFILES[self::normalize($role)]);
    }

    public static function isAdmin(?string $role): bool
    {
        return self::normalize($role) === self::ADMIN;
    }

    /**
     * Zakres danych roli; nieznana rola nie widzi niczego (SCOPE_PERSONAL bez powiązań to puste listy).
     */
    public static function scope(?string $role): string
    {
        return self::PROFILES[self::normalize($role)]['scope'] ?? self::SCOPE_PERSONAL;
    }

    /**
     * Czy rola widzi dane całej organizacji (wszystkie certyfikaty, osoby i firmy).
     */
    public static function seesAllRecords(?string $role): bool
    {
        return self::isRole($role) && self::scope($role) === self::SCOPE_ALL;
    }

    /**
     * Czy rola może wykonać akcję. Nieznane uprawnienie albo rola oznacza brak dostępu.
     */
    public static function can(?string $role, string $permission): bool
    {
        $profile = self::PROFILES[self::normalize($role)] ?? null;
        if ($profile === null || !in_array($permission, self::ALL, true)) {
            return false;
        }

        return $profile['can'] === null || in_array($permission, $profile['can'], true);
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
            self::ALL,
            static fn (string $permission): bool => self::can($role, $permission)
        ));
    }

    /**
     * Czy rola, która nie ma pełnych uprawnień do certyfikatów, może pracować na danym ich typie
     * (informatyk obsługuje tylko certyfikaty techniczne).
     */
    public static function canManageCertificateType(?string $role, string $type): bool
    {
        if (self::scope($role) !== self::SCOPE_TECHNICAL) {
            return true;
        }

        return in_array($type, self::TECHNICAL_TYPES, true);
    }

    public static function normalize(?string $role): string
    {
        return strtoupper(trim((string) $role));
    }
}

<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;
use PDO;

/**
 * Limit prób logowania (tabela login_attempts) — przy logowaniu hasłem to jedyna
 * zapora przed zgadywaniem haseł metodą siłową i przed rozpylaniem jednego hasła
 * na wiele kont (password spraying).
 *
 * Limit liczy się z bazy, nie z sesji, więc nie da się go obejść czyszczeniem ciasteczek:
 *   - 5 nieudanych prób na adres e-mail w ciągu 15 minut,
 *   - 20 nieudanych prób z jednego adresu IP w tym samym oknie (chroni pozostałe konta).
 *
 * Udane logowanie zeruje licznik dla adresu e-mail.
 */
final class LoginThrottle
{
    public const MAX_FAILURES_PER_EMAIL = 5;
    public const MAX_FAILURES_PER_IP = 20;
    public const WINDOW_SECONDS = 900;

    public function __construct(private readonly PDO $db)
    {
    }

    public function isBlocked(string $email, ?string $ip): bool
    {
        $since = self::since();

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND successful = 0 AND created_at >= :since'
        );
        $stmt->execute(['email' => $email, 'since' => $since]);
        if ((int) $stmt->fetchColumn() >= self::MAX_FAILURES_PER_EMAIL) {
            return true;
        }

        if ($ip === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE request_ip = :ip AND successful = 0 AND created_at >= :since'
        );
        $stmt->execute(['ip' => $ip, 'since' => $since]);

        return (int) $stmt->fetchColumn() >= self::MAX_FAILURES_PER_IP;
    }

    public function record(string $email, ?string $ip, bool $successful): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (email, request_ip, successful, created_at)
             VALUES (:email, :request_ip, :successful, :created_at)'
        );
        $stmt->execute([
            'email'      => $email,
            'request_ip' => $ip,
            'successful' => $successful ? 1 : 0,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        if ($successful) {
            $this->clearFailures($email);
        }
    }

    /**
     * Zeruje licznik nieudanych prób. Wywołuje to udane logowanie oraz ustawienie nowego
     * hasła linkiem z wiadomości — komunikat o blokadzie wprost proponuje tę drugą drogę,
     * więc po zmianie hasła konto musi być dostępne od razu.
     */
    public function reset(string $email): void
    {
        $this->clearFailures($email);
    }

    /**
     * Po udanym logowaniu nieudane próby przestają się liczyć — inaczej kilka pomyłek
     * blokowałoby konto osobie, która już się poprawnie zalogowała.
     */
    private function clearFailures(string $email): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM login_attempts WHERE email = :email AND successful = 0 AND created_at >= :since'
        );
        $stmt->execute(['email' => $email, 'since' => self::since()]);
    }

    private static function since(): string
    {
        return (new DateTimeImmutable('-' . self::WINDOW_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
    }
}

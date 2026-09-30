<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;
use PDO;

/**
 * Jednorazowe tokeny z wiadomości e-mail (tabela email_verifications).
 *
 * Dwa zastosowania:
 *   EMAIL_VERIFY  — potwierdzenie adresu po rejestracji (konto nieaktywne do potwierdzenia),
 *   PASSWORD_SET  — ustawienie hasła: konto założone przez administratora oraz „nie pamiętam hasła”.
 *
 * Zasady:
 *   - token to 32 losowe bajty z random_bytes (256 bitów), w linku jako 64 znaki hex,
 *   - w bazie jest wyłącznie skrót SHA-256; wyciek kopii bazy nie pozwala użyć linku,
 *     a skrót (w przeciwieństwie do bcrypt) da się wyszukać jednym zapytaniem po indeksie,
 *   - token jest jednorazowy (used_at) i ma termin ważności (expires_at),
 *   - nowy token unieważnia poprzednie tego samego rodzaju — w skrzynce działa tylko najnowszy link.
 */
final class VerificationTokens
{
    public const PURPOSE_EMAIL_VERIFY = 'EMAIL_VERIFY';
    public const PURPOSE_PASSWORD_SET = 'PASSWORD_SET';

    /** Ważność linku potwierdzającego adres i linku do ustawienia hasła (w godzinach). */
    public const EMAIL_VERIFY_TTL_HOURS = 24;
    public const PASSWORD_SET_TTL_HOURS = 2;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Zapisuje nowy token i zwraca jego jawną postać — jedyny moment, gdy jest ona znana.
     */
    public function issue(int $userId, string $email, string $purpose, ?string $ip = null): string
    {
        $purpose = self::normalizePurpose($purpose);
        $token = bin2hex(random_bytes(32));
        $hours = $purpose === self::PURPOSE_PASSWORD_SET ? self::PASSWORD_SET_TTL_HOURS : self::EMAIL_VERIFY_TTL_HOURS;

        $this->invalidate($email, $purpose);

        $stmt = $this->db->prepare(
            'INSERT INTO email_verifications (user_id, email, purpose, token_hash, request_ip, expires_at, created_at)
             VALUES (:user_id, :email, :purpose, :token_hash, :request_ip, :expires_at, :created_at)'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'email'      => $email,
            'purpose'    => $purpose,
            'token_hash' => self::hash($token),
            'request_ip' => $ip,
            'expires_at' => (new DateTimeImmutable('+' . $hours . ' hours'))->format('Y-m-d H:i:s'),
            'created_at' => self::now(),
        ]);

        return $token;
    }

    /**
     * Zużywa token: sprawdza rodzaj, termin i to, czy nie był już użyty, a potem oznacza go
     * jako wykorzystany. Zwraca wiersz tokenu albo klucz błędu.
     *
     * @return array{ok: true, token: array<string, mixed>}|array{ok: false, error: string}
     */
    public function consume(string $token, string $purpose): array
    {
        $found = $this->find($token, $purpose);
        if (!$found['ok']) {
            return $found;
        }

        if (!$this->markUsed((int) $found['token']['id'])) {
            // Gdy dwa żądania trafią na ten sam token, wygrywa to, które zaktualizowało wiersz.
            return ['ok' => false, 'error' => 'auth.error.link_used'];
        }

        return $found;
    }

    /**
     * Sprawdza token, nie zużywając go — formularz „ustaw hasło” musi wiedzieć, czy link jest
     * ważny, zanim poprosi o hasło, a token ma zniknąć dopiero po zapisaniu nowego hasła.
     *
     * @return array{ok: true, token: array<string, mixed>}|array{ok: false, error: string}
     */
    public function find(string $token, string $purpose): array
    {
        $token = trim($token);
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return ['ok' => false, 'error' => 'auth.error.link_invalid'];
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, email, purpose, expires_at, used_at
             FROM email_verifications
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hash($token)]);
        $row = $stmt->fetch();

        if ($row === false || $row['purpose'] !== self::normalizePurpose($purpose)) {
            return ['ok' => false, 'error' => 'auth.error.link_invalid'];
        }

        if ($row['used_at'] !== null) {
            return ['ok' => false, 'error' => 'auth.error.link_used'];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'auth.error.link_expired'];
        }

        return ['ok' => true, 'token' => $row];
    }

    public function markUsed(int $tokenId): bool
    {
        $stmt = $this->db->prepare('UPDATE email_verifications SET used_at = :used_at WHERE id = :id AND used_at IS NULL');
        $stmt->execute(['used_at' => self::now(), 'id' => $tokenId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Unieważnia niewykorzystane tokeny danego rodzaju (albo wszystkie, gdy rodzaj pominięty).
     */
    public function invalidate(string $email, ?string $purpose = null): void
    {
        $sql = 'UPDATE email_verifications SET used_at = :used_at WHERE email = :email AND used_at IS NULL';
        $params = ['used_at' => self::now(), 'email' => $email];

        if ($purpose !== null) {
            $sql .= ' AND purpose = :purpose';
            $params['purpose'] = self::normalizePurpose($purpose);
        }

        $this->db->prepare($sql)->execute($params);
    }

    /**
     * Unieważnia tokeny konta — używane przy wyłączaniu konta przez administratora.
     */
    public function invalidateForUser(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE email_verifications SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL'
        );
        $stmt->execute(['used_at' => self::now(), 'user_id' => $userId]);
    }

    /**
     * Liczba wiadomości wysłanych na ten adres w oknie czasowym — podstawa limitu ponownych wysyłek.
     */
    public function countRecentForEmail(string $email, int $windowSeconds): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM email_verifications WHERE email = :email AND created_at >= :since'
        );
        $stmt->execute(['email' => $email, 'since' => self::since($windowSeconds)]);

        return (int) $stmt->fetchColumn();
    }

    public function countRecentForIp(string $ip, int $windowSeconds): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM email_verifications WHERE request_ip = :ip AND created_at >= :since'
        );
        $stmt->execute(['ip' => $ip, 'since' => self::since($windowSeconds)]);

        return (int) $stmt->fetchColumn();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function normalizePurpose(string $purpose): string
    {
        $purpose = strtoupper(trim($purpose));

        return $purpose === self::PURPOSE_PASSWORD_SET ? self::PURPOSE_PASSWORD_SET : self::PURPOSE_EMAIL_VERIFY;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private static function since(int $windowSeconds): string
    {
        return (new DateTimeImmutable('-' . $windowSeconds . ' seconds'))->format('Y-m-d H:i:s');
    }
}

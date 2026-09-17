<?php

declare(strict_types=1);

namespace App;

use PDO;

final class AuthManager
{
    private const OTP_LENGTH = 6;
    private const OTP_TTL_MINUTES = 10;
    private const OTP_MAX_ATTEMPTS = 5;
    /** Limit próśb o kod: na adres e-mail i (luźniejszy) na adres IP w oknie czasowym. */
    private const OTP_SEND_LIMIT = 3;
    private const OTP_SEND_LIMIT_PER_IP = 10;
    private const OTP_SEND_WINDOW_SECONDS = 900;
    private const SESSION_USER_KEY = 'user_id';
    private const SESSION_PENDING_EMAIL = 'auth_pending_email';
    private const SESSION_PENDING_REDIRECT = 'auth_pending_redirect';

    /** @var list<string> */
    private const ALLOWED_REDIRECTS = ['dashboard.php', 'dashboard-personal.php'];

    private PDO $db;
    private UserManager $users;
    private OtpMailer $mail;

    public function __construct(
        ?PDO $connection = null,
        ?UserManager $users = null,
        ?OtpMailer $mail = null
    ) {
        $this->db = $connection ?? Database::getInstance()->getConnection();
        $this->users = $users ?? new UserManager($this->db);
        $this->mail = $mail ?? new EmailService();
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION[self::SESSION_USER_KEY]) && (int) $_SESSION[self::SESSION_USER_KEY] > 0;
    }

    /**
     * Zalogowane konto — albo false, gdy sesji nie ma, konto usunięto lub administrator je wyłączył.
     * Wyłączenie działa natychmiast: przy następnym żądaniu sesja jest kończona.
     *
     * @return array<string, mixed>|false
     */
    public function currentUser(): array|false
    {
        if (!$this->isAuthenticated()) {
            return false;
        }

        $user = $this->users->findById((int) $_SESSION[self::SESSION_USER_KEY]);
        if ($user === false || !UserManager::isActive($user)) {
            $this->logout();

            return false;
        }

        return $user;
    }

    /**
     * @return array{ok: bool, sent?: bool, error?: string}
     */
    public function requestOtp(string $email, string $redirect = 'dashboard.php', ?string $ip = null): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            return ['ok' => false, 'error' => 'auth.error.invalid_email'];
        }

        // Nieznane i wyłączone konto wyglądają tak samo — odpowiedź nie zdradza, które adresy istnieją.
        $user = $this->users->findByEmail($email);
        if ($user === false || !UserManager::isActive($user)) {
            $this->simulateOtpDeliveryDelay();

            return ['ok' => true, 'sent' => false];
        }

        $result = $this->sendOtpForUser((int) $user['id'], $email, $redirect, self::normalizeIp($ip));

        return array_merge($result, ['sent' => $result['ok']]);
    }

    /**
     * @return array{ok: bool, error?: string, redirect?: string}
     */
    public function verifyOtp(string $email, string $code): array
    {
        Session::ensureStarted();

        $email = $this->normalizeEmail($email);
        $code = trim($code);

        if ($email === '' || !preg_match('/^\d{' . self::OTP_LENGTH . '}$/', $code)) {
            return ['ok' => false, 'error' => 'auth.error.invalid_code'];
        }

        $pendingEmail = $this->getPendingEmail() ?? '';
        if ($pendingEmail !== '' && $pendingEmail !== $email) {
            return ['ok' => false, 'error' => 'auth.error.session_expired'];
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, code_hash, expires_at, used_at, attempt_count
             FROM login_otps
             WHERE email = :email AND used_at IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $otp = $stmt->fetch();

        if ($otp === false) {
            return ['ok' => false, 'error' => 'auth.error.code_not_found'];
        }

        if ($pendingEmail === '') {
            $_SESSION[self::SESSION_PENDING_EMAIL] = $email;
        }

        if ($otp['used_at'] !== null) {
            return ['ok' => false, 'error' => 'auth.error.code_used'];
        }

        if (strtotime((string) $otp['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'auth.error.code_expired'];
        }

        if ((int) ($otp['attempt_count'] ?? 0) >= self::OTP_MAX_ATTEMPTS) {
            return ['ok' => false, 'error' => 'auth.error.too_many_attempts'];
        }

        if (!password_verify($code, (string) $otp['code_hash'])) {
            $this->recordFailedOtpAttempt((int) $otp['id'], (int) ($otp['attempt_count'] ?? 0));
            return ['ok' => false, 'error' => 'auth.error.invalid_code'];
        }

        $user = $this->users->findById((int) $otp['user_id']);
        if ($user === false || !UserManager::isActive($user)) {
            return ['ok' => false, 'error' => 'auth.error.account_inactive'];
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $mark = $this->db->prepare('UPDATE login_otps SET used_at = :used_at WHERE id = :id');
        $mark->execute(['used_at' => $now, 'id' => $otp['id']]);

        $this->login((int) $otp['user_id']);

        $redirect = $_SESSION[self::SESSION_PENDING_REDIRECT] ?? 'dashboard.php';
        unset($_SESSION[self::SESSION_PENDING_EMAIL], $_SESSION[self::SESSION_PENDING_REDIRECT]);

        return ['ok' => true, 'redirect' => $this->sanitizeRedirect($redirect)];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function sendOtpForUser(int $userId, string $email, string $redirect, ?string $ip): array
    {
        Session::ensureStarted();

        if (!$this->canSendOtp($email, $ip)) {
            return ['ok' => false, 'error' => 'auth.error.rate_limited'];
        }

        $redirect = $this->sanitizeRedirect($redirect);
        $code = $this->generateCode();
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $expiresAt = (new \DateTimeImmutable('+' . self::OTP_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        $this->invalidatePendingOtps($email);

        // created_at zapisujemy z PHP, bo limit porównuje czasy z tej samej strefy co reszta aplikacji.
        $stmt = $this->db->prepare(
            'INSERT INTO login_otps (user_id, email, request_ip, code_hash, expires_at, created_at)
             VALUES (:user_id, :email, :request_ip, :code_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'email'      => $email,
            'request_ip' => $ip,
            'code_hash'  => $codeHash,
            'expires_at' => $expiresAt,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $_SESSION[self::SESSION_PENDING_EMAIL] = $email;
        $_SESSION[self::SESSION_PENDING_REDIRECT] = $redirect;

        if (!$this->mail->sendOtpCode($email, $code)) {
            unset($_SESSION[self::SESSION_PENDING_EMAIL], $_SESSION[self::SESSION_PENDING_REDIRECT]);

            return ['ok' => false, 'error' => 'auth.error.mail_failed'];
        }

        return ['ok' => true];
    }

    public function login(int $userId): void
    {
        $_SESSION[self::SESSION_USER_KEY] = $userId;
        session_regenerate_id(true);
    }

    public function logout(): void
    {
        unset(
            $_SESSION[self::SESSION_USER_KEY],
            $_SESSION[self::SESSION_PENDING_EMAIL],
            $_SESSION[self::SESSION_PENDING_REDIRECT]
        );
    }

    public function getPendingEmail(): ?string
    {
        $email = $_SESSION[self::SESSION_PENDING_EMAIL] ?? null;

        if (!is_string($email) || $email === '') {
            return null;
        }

        $normalized = $this->normalizeEmail($email);

        return $normalized !== '' ? $normalized : null;
    }

    public function clearPendingOtp(): void
    {
        unset(
            $_SESSION[self::SESSION_PENDING_EMAIL],
            $_SESSION[self::SESSION_PENDING_REDIRECT]
        );
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function deleteCurrentAccount(): array
    {
        if (!$this->isAuthenticated()) {
            return ['ok' => false, 'error' => 'auth.error.generic'];
        }

        $userId = (int) $_SESSION[self::SESSION_USER_KEY];

        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'auth.error.generic'];
        }

        if ($this->users->countOwnedCertificates($userId) > 0) {
            return ['ok' => false, 'error' => 'auth.error.delete_blocked'];
        }

        if (!$this->users->deleteAccountCompletely($userId)) {
            return ['ok' => false, 'error' => 'auth.error.generic'];
        }

        $this->logout();
        $this->clearPendingOtp();

        return ['ok' => true];
    }

    public function requireAuth(string $redirectTarget): void
    {
        if ($this->currentUser() !== false) {
            return;
        }

        $loginUrl = 'login.php?redirect=' . urlencode($this->sanitizeRedirect($redirectTarget));
        header('Location: ' . $loginUrl);
        exit;
    }

    public function sanitizeRedirect(string $redirect): string
    {
        $path = parse_url($redirect, PHP_URL_PATH);
        $basename = $path ? basename((string) $path) : basename($redirect);

        if (!in_array($basename, self::ALLOWED_REDIRECTS, true)) {
            return 'dashboard.php';
        }

        $query = parse_url($redirect, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return $basename;
        }

        parse_str($query, $params);
        if (!isset($params['lang']) || !in_array($params['lang'], Translator::SUPPORTED, true)) {
            return $basename;
        }

        return $basename . '?lang=' . urlencode($params['lang']);
    }

    private function normalizeEmail(string $email): string
    {
        $email = trim(strtolower($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    private function invalidatePendingOtps(string $email): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE login_otps SET used_at = :used_at WHERE email = :email AND used_at IS NULL'
        );
        $stmt->execute(['used_at' => $now, 'email' => $email]);
    }

    private function recordFailedOtpAttempt(int $otpId, int $currentAttempts): void
    {
        $attempts = $currentAttempts + 1;
        $stmt = $this->db->prepare(
            'UPDATE login_otps SET attempt_count = :attempt_count WHERE id = :id'
        );
        $stmt->execute([
            'attempt_count' => min($attempts, self::OTP_MAX_ATTEMPTS),
            'id'            => $otpId,
        ]);
    }

    /**
     * Limit próśb o kod liczony z historii w bazie (§5 pkt 9): każda prośba zapisuje wiersz
     * w login_otps, więc limitu nie da się obejść czyszczeniem ciasteczka sesji.
     */
    private function canSendOtp(string $email, ?string $ip): bool
    {
        $since = (new \DateTimeImmutable('-' . self::OTP_SEND_WINDOW_SECONDS . ' seconds'))->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_otps WHERE email = :email AND created_at >= :since');
        $stmt->execute(['email' => $email, 'since' => $since]);
        if ((int) $stmt->fetchColumn() >= self::OTP_SEND_LIMIT) {
            return false;
        }

        if ($ip === null) {
            return true;
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_otps WHERE request_ip = :ip AND created_at >= :since');
        $stmt->execute(['ip' => $ip, 'since' => $since]);

        return (int) $stmt->fetchColumn() < self::OTP_SEND_LIMIT_PER_IP;
    }

    private static function normalizeIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false ? substr($ip, 0, 45) : null;
    }

    private function simulateOtpDeliveryDelay(): void
    {
        usleep(random_int(150_000, 450_000));
    }
}

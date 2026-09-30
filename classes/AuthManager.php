<?php

declare(strict_types=1);

namespace App;

use App\Auth\AuthConfig;
use App\Auth\LoginThrottle;
use App\Auth\PasswordPolicy;
use App\Auth\VerificationTokens;
use PDO;

/**
 * Uwierzytelnianie: e-mail + hasło, z potwierdzeniem adresu e-mail linkiem z wiadomości (Etap 9).
 *
 * Przepływ rejestracji:
 *   1. register()       — sprawdza dane, zapisuje konto z hasłem i adresem NIEpotwierdzonym,
 *                         wystawia jednorazowy token i wysyła wiadomość (SMTP + OAuth2),
 *   2. verifyEmail()    — zużywa token z linku i ustawia users.email_verified_at,
 *   3. login()          — od tej pory zwykłe logowanie hasłem.
 *
 * Konto bez potwierdzonego adresu nie może się zalogować. Konto założone przez administratora
 * nie ma hasła — dostaje wiadomość z linkiem „ustaw hasło” (ten sam mechanizm obsługuje
 * „nie pamiętam hasła”).
 *
 * Zasady bezpieczeństwa przyjęte w tej klasie:
 *   - hasła trzymamy wyłącznie jako skrót z password_hash(); przy logowaniu password_verify(),
 *     a przy zmianie domyślnego algorytmu PHP skrót jest po cichu przeliczany,
 *   - komunikaty nie zdradzają, które adresy istnieją: rejestracja i „ustaw hasło” zawsze
 *     odpowiadają tak samo, a błędne hasło i nieznany adres dają ten sam komunikat,
 *   - nieudane próby logowania i wysyłki wiadomości mają limity liczone w bazie (LoginThrottle),
 *   - po zalogowaniu i po ustawieniu hasła zmieniamy identyfikator sesji (ochrona przed
 *     podszyciem się pod sesję).
 */
final class AuthManager
{
    private const SESSION_USER_KEY = 'user_id';
    private const SESSION_PENDING_REDIRECT = 'auth_pending_redirect';

    /** Limit wiadomości z linkiem: na adres e-mail i (luźniejszy) na adres IP w oknie czasowym. */
    private const MAIL_LIMIT_PER_EMAIL = 3;
    private const MAIL_LIMIT_PER_IP = 10;
    private const MAIL_LIMIT_WINDOW_SECONDS = 900;

    /** @var list<string> */
    private const ALLOWED_REDIRECTS = ['dashboard.php'];

    private PDO $db;
    private UserManager $users;
    private AuthMailer $mail;
    private VerificationTokens $tokens;
    private LoginThrottle $throttle;

    public function __construct(
        ?PDO $connection = null,
        ?UserManager $users = null,
        ?AuthMailer $mail = null,
        ?VerificationTokens $tokens = null,
        ?LoginThrottle $throttle = null
    ) {
        $this->db = $connection ?? Database::getInstance()->getConnection();
        $this->users = $users ?? new UserManager($this->db);
        $this->mail = $mail ?? new EmailService();
        $this->tokens = $tokens ?? new VerificationTokens($this->db);
        $this->throttle = $throttle ?? new LoginThrottle($this->db);
    }

    // ---------------------------------------------------------------- sesja

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
        if ($user === false || !UserManager::isActive($user) || !UserManager::isEmailVerified($user)) {
            $this->logout();

            return false;
        }

        return $user;
    }

    public function login(int $userId): void
    {
        $_SESSION[self::SESSION_USER_KEY] = $userId;
        session_regenerate_id(true);
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_USER_KEY], $_SESSION[self::SESSION_PENDING_REDIRECT]);
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

    // ----------------------------------------------------------- rejestracja

    public function selfRegistrationEnabled(): bool
    {
        return AuthConfig::selfRegistrationEnabled();
    }

    /**
     * Rejestracja: imię, nazwisko, e-mail, hasło (dwa razy).
     *
     * Odpowiedź jest zawsze taka sama, niezależnie od tego, czy adres był już zajęty —
     * inaczej formularz stałby się narzędziem do sprawdzania, kto ma konto w systemie.
     * Gdy adres jest zajęty, wiadomość nie wychodzi (właściciel konta nie dostaje spamu),
     * a osoba widzi komunikat „sprawdź skrzynkę”.
     *
     * @param array<string, string> $data Klucze: first_name, last_name, email, password, password_confirm.
     * @return array{ok: bool, error?: string, field?: string}
     */
    public function register(array $data, ?string $ip = null): array
    {
        if (!$this->selfRegistrationEnabled()) {
            return ['ok' => false, 'error' => 'auth.error.registration_disabled'];
        }

        $firstName = self::cleanName($data['first_name'] ?? '');
        $lastName = self::cleanName($data['last_name'] ?? '');
        $email = $this->normalizeEmail($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirm'] ?? '');

        if ($firstName === '' || $lastName === '') {
            return ['ok' => false, 'error' => 'auth.error.name_required', 'field' => 'first_name'];
        }

        if ($email === '') {
            return ['ok' => false, 'error' => 'auth.error.invalid_email', 'field' => 'email'];
        }

        if ($password !== $confirm) {
            return ['ok' => false, 'error' => 'auth.error.password_mismatch', 'field' => 'password_confirm'];
        }

        $passwordError = PasswordPolicy::validate($password, $email);
        if ($passwordError !== null) {
            return ['ok' => false, 'error' => $passwordError, 'field' => 'password'];
        }

        $ip = self::normalizeIp($ip);
        if (!$this->canSendMail($email, $ip)) {
            return ['ok' => false, 'error' => 'auth.error.rate_limited'];
        }

        $existing = $this->users->findByEmail($email);

        if ($existing !== false) {
            // Konto czekające na potwierdzenie adresu: wysyłamy link jeszcze raz. Tak wygląda
            // ponowna próba po zgubionej wiadomości albo po błędzie wysyłki, a danych konta
            // (imienia, nazwiska, hasła) formularz NIE nadpisuje — inaczej dałoby się je podmienić
            // cudzym adresem. Link trafia wyłącznie do właściciela skrzynki, więc nic to nie zdradza.
            if (UserManager::isActive($existing) && !UserManager::isEmailVerified($existing)) {
                $sent = $this->sendVerification(
                    (int) $existing['id'],
                    $email,
                    trim((string) $existing['first_name'] . ' ' . (string) $existing['last_name']),
                    $ip
                );

                return $sent ? ['ok' => true] : ['ok' => false, 'error' => 'auth.error.mail_failed'];
            }

            // Adres zajęty przez działające konto: nic nie zmieniamy, nic nie wysyłamy,
            // a komunikat jest taki sam jak przy udanej rejestracji.
            $this->simulateDeliveryDelay();

            return ['ok' => true];
        }

        $userId = $this->users->createWithPassword(
            $firstName,
            $lastName,
            $email,
            PasswordPolicy::hash($password),
            AuthConfig::defaultRole()
        );

        if ($userId === false) {
            return ['ok' => false, 'error' => 'auth.error.generic'];
        }

        if (!$this->sendVerification($userId, $email, trim($firstName . ' ' . $lastName), $ip)) {
            return ['ok' => false, 'error' => 'auth.error.mail_failed'];
        }

        return ['ok' => true];
    }

    /**
     * Ponowna wysyłka linku potwierdzającego. Odpowiedź nie zdradza, czy konto istnieje
     * i czy jest już potwierdzone.
     *
     * @return array{ok: bool, error?: string}
     */
    public function resendVerification(string $email, ?string $ip = null): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            return ['ok' => false, 'error' => 'auth.error.invalid_email'];
        }

        $ip = self::normalizeIp($ip);
        if (!$this->canSendMail($email, $ip)) {
            return ['ok' => false, 'error' => 'auth.error.rate_limited'];
        }

        $user = $this->users->findByEmail($email);

        if (
            $user === false
            || !UserManager::isActive($user)
            || UserManager::isEmailVerified($user)
            || !UserManager::hasPassword($user)
        ) {
            $this->simulateDeliveryDelay();

            return ['ok' => true];
        }

        $this->sendVerification(
            (int) $user['id'],
            $email,
            trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
            $ip
        );

        return ['ok' => true];
    }

    /**
     * Potwierdzenie adresu linkiem z wiadomości. Nie loguje — po potwierdzeniu
     * osoba loguje się normalnie e-mailem i hasłem.
     *
     * @return array{ok: bool, error?: string, email?: string}
     */
    public function verifyEmail(string $token): array
    {
        $result = $this->tokens->consume($token, VerificationTokens::PURPOSE_EMAIL_VERIFY);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error']];
        }

        $user = $this->users->findById((int) $result['token']['user_id']);
        if ($user === false || !UserManager::isActive($user)) {
            return ['ok' => false, 'error' => 'auth.error.account_inactive'];
        }

        if (!UserManager::isEmailVerified($user)) {
            $this->users->markEmailVerified((int) $user['id']);
        }

        return ['ok' => true, 'email' => (string) $user['email']];
    }

    // -------------------------------------------------------------- logowanie

    /**
     * Zwykłe logowanie: e-mail + hasło. Wymaga potwierdzonego adresu.
     *
     * Nieznany adres, brak hasła i złe hasło dają ten sam komunikat (auth.error.invalid_credentials),
     * bo różne komunikaty pozwoliłyby ustalić listę kont. Wyjątkiem jest adres niepotwierdzony:
     * tu komunikat jest konkretny, bo inaczej osoba po rejestracji nie wie, co zrobić — i tak
     * wie już, że konto istnieje, skoro właśnie je zakładała.
     *
     * @return array{ok: bool, error?: string, redirect?: string, email?: string}
     */
    public function attemptLogin(string $email, string $password, string $redirect = 'dashboard.php', ?string $ip = null): array
    {
        Session::ensureStarted();

        $email = $this->normalizeEmail($email);
        $ip = self::normalizeIp($ip);

        if ($email === '' || $password === '') {
            return ['ok' => false, 'error' => 'auth.error.invalid_credentials'];
        }

        if ($this->throttle->isBlocked($email, $ip)) {
            return ['ok' => false, 'error' => 'auth.error.login_blocked'];
        }

        $user = $this->users->findByEmail($email);

        if ($user === false || !UserManager::hasPassword($user)) {
            // Porównanie na sztucznym skrócie: czas odpowiedzi nie zdradza, czy konto istnieje.
            password_verify($password, '$2y$12$' . str_repeat('x', 53));
            $this->throttle->record($email, $ip, false);

            return ['ok' => false, 'error' => 'auth.error.invalid_credentials'];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->throttle->record($email, $ip, false);

            return ['ok' => false, 'error' => 'auth.error.invalid_credentials'];
        }

        // Poniżej hasło jest już poprawne, więc to nie próba jego zgadywania — takich
        // odmów nie liczymy do limitu. Inaczej osoba, która po rejestracji kilka razy
        // spróbuje się zalogować przed potwierdzeniem adresu, zablokowałaby sobie konto.
        if (!UserManager::isActive($user)) {
            return ['ok' => false, 'error' => 'auth.error.account_inactive'];
        }

        if (!UserManager::isEmailVerified($user)) {
            return ['ok' => false, 'error' => 'auth.error.email_not_verified', 'email' => $email];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->setPasswordHash((int) $user['id'], PasswordPolicy::hash($password));
        }

        $this->throttle->record($email, $ip, true);
        $this->users->recordLogin((int) $user['id']);
        $this->login((int) $user['id']);

        return ['ok' => true, 'redirect' => $this->sanitizeRedirect($redirect)];
    }

    // ------------------------------------------------------- ustawienie hasła

    /**
     * Wysyła link „ustaw hasło”: dla konta założonego przez administratora (jeszcze bez hasła)
     * i dla osoby, która hasła nie pamięta. Odpowiedź zawsze neutralna.
     *
     * @return array{ok: bool, error?: string}
     */
    public function requestPasswordSetLink(string $email, ?string $ip = null): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            return ['ok' => false, 'error' => 'auth.error.invalid_email'];
        }

        $ip = self::normalizeIp($ip);
        if (!$this->canSendMail($email, $ip)) {
            return ['ok' => false, 'error' => 'auth.error.rate_limited'];
        }

        $user = $this->users->findByEmail($email);
        if ($user === false || !UserManager::isActive($user)) {
            $this->simulateDeliveryDelay();

            return ['ok' => true];
        }

        $token = $this->tokens->issue(
            (int) $user['id'],
            $email,
            VerificationTokens::PURPOSE_PASSWORD_SET,
            $ip
        );

        $this->mail->sendPasswordSetLink(
            $email,
            trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
            AppUrl::to('set-password.php', ['token' => $token])
        );

        return ['ok' => true];
    }

    /**
     * Ustawia hasło na podstawie tokenu z wiadomości. Dostęp do skrzynki jest dowodem
     * posiadania adresu, więc ustawienie hasła potwierdza zarazem adres (patrz
     * UserManager::setPasswordHash) i od razu loguje.
     *
     * @return array{ok: bool, error?: string, field?: string, redirect?: string}
     */
    public function setPasswordWithToken(string $token, string $password, string $confirm): array
    {
        Session::ensureStarted();

        // Najpierw sprawdzamy token BEZ zużywania go: gdyby ginął już przy literówce w haśle,
        // osoba musiałaby prosić o nowy link po każdej pomyłce.
        $found = $this->tokens->find($token, VerificationTokens::PURPOSE_PASSWORD_SET);
        if (!$found['ok']) {
            return ['ok' => false, 'error' => $found['error']];
        }

        $email = (string) $found['token']['email'];

        if ($password !== $confirm) {
            return ['ok' => false, 'error' => 'auth.error.password_mismatch', 'field' => 'password_confirm'];
        }

        $passwordError = PasswordPolicy::validate($password, $email);
        if ($passwordError !== null) {
            return ['ok' => false, 'error' => $passwordError, 'field' => 'password'];
        }

        $user = $this->users->findById((int) $found['token']['user_id']);
        if ($user === false || !UserManager::isActive($user)) {
            return ['ok' => false, 'error' => 'auth.error.account_inactive'];
        }

        // Hasło jest poprawne — dopiero teraz token przestaje działać.
        if (!$this->tokens->markUsed((int) $found['token']['id'])) {
            return ['ok' => false, 'error' => 'auth.error.link_used'];
        }

        if (!$this->users->setPasswordHash((int) $user['id'], PasswordPolicy::hash($password))) {
            return ['ok' => false, 'error' => 'auth.error.generic'];
        }

        // Pozostałe linki tej osoby przestają działać — po zmianie hasła stare wiadomości są bezwartościowe.
        $this->tokens->invalidate($email);
        // Nowe hasło zdejmuje blokadę po nieudanych próbach (tak brzmi komunikat o limicie).
        $this->throttle->reset($email);
        $this->users->recordLogin((int) $user['id']);
        $this->login((int) $user['id']);

        return ['ok' => true, 'redirect' => 'dashboard.php'];
    }

    /**
     * Czy link „ustaw hasło” jest jeszcze ważny — pytanie formularza przed pokazaniem pól.
     *
     * @return array{ok: bool, error?: string}
     */
    public function checkPasswordToken(string $token): array
    {
        $found = $this->tokens->find($token, VerificationTokens::PURPOSE_PASSWORD_SET);

        return $found['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $found['error']];
    }

    // ------------------------------------------------------------ konto własne

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

        return ['ok' => true];
    }

    // ----------------------------------------------------------------- pomocne

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

    private function sendVerification(int $userId, string $email, string $name, ?string $ip): bool
    {
        $token = $this->tokens->issue($userId, $email, VerificationTokens::PURPOSE_EMAIL_VERIFY, $ip);

        return $this->mail->sendEmailVerification(
            $email,
            $name,
            AppUrl::to('verify-email.php', ['token' => $token])
        );
    }

    /**
     * Limit wysyłek liczony z historii tokenów w bazie: nie da się go obejść nową sesją.
     */
    private function canSendMail(string $email, ?string $ip): bool
    {
        if ($this->tokens->countRecentForEmail($email, self::MAIL_LIMIT_WINDOW_SECONDS) >= self::MAIL_LIMIT_PER_EMAIL) {
            return false;
        }

        if ($ip === null) {
            return true;
        }

        return $this->tokens->countRecentForIp($ip, self::MAIL_LIMIT_WINDOW_SECONDS) < self::MAIL_LIMIT_PER_IP;
    }

    private function normalizeEmail(string $email): string
    {
        $email = trim(strtolower($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }

    private static function normalizeIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false ? substr($ip, 0, 45) : null;
    }

    /**
     * Przy neutralnej odpowiedzi udajemy pracę wysyłki, żeby czas odpowiedzi nie ujawniał,
     * czy wiadomość faktycznie poszła.
     */
    private function simulateDeliveryDelay(): void
    {
        usleep(random_int(150_000, 450_000));
    }
}

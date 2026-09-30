<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\AuthConfig;
use App\Auth\LoginThrottle;
use App\Auth\PasswordPolicy;
use App\Auth\VerificationTokens;
use App\AuthManager;
use App\Session;
use App\UserManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingAuthMailer;
use Tests\Support\SqliteTestDatabase;

/**
 * Logowanie hasłem z potwierdzeniem adresu e-mail (Etap 9).
 */
final class AuthManagerTest extends TestCase
{
    private RecordingAuthMailer $mail;

    protected function setUp(): void
    {
        Session::destroy();
        Session::ensureStarted();
        $_SESSION = [];
        $this->mail = new RecordingAuthMailer();
    }

    protected function tearDown(): void
    {
        AuthConfig::useConfig(null);
        Session::destroy();
        $_SESSION = [];
    }

    private function auth(PDO $db): AuthManager
    {
        return new AuthManager(
            $db,
            new UserManager($db),
            $this->mail,
            new VerificationTokens($db),
            new LoginThrottle($db)
        );
    }

    /**
     * @return array<string, string>
     */
    private static function form(array $overrides = []): array
    {
        return array_merge([
            'first_name'       => 'Anna',
            'last_name'        => 'Nowak',
            'email'            => 'anna.nowak@example.com',
            'password'         => 'TajneHaslo123',
            'password_confirm' => 'TajneHaslo123',
        ], $overrides);
    }

    // ----------------------------------------------------------- rejestracja

    public function testRegistrationCreatesUnverifiedAccountAndSendsLink(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);

        $this->assertTrue($auth->register(self::form())['ok']);

        $user = (new UserManager($db))->findByEmail('anna.nowak@example.com');
        $this->assertNotFalse($user);
        $this->assertNull($user['email_verified_at'], 'konto czeka na potwierdzenie adresu');
        $this->assertTrue(UserManager::hasPassword($user));
        $this->assertNotSame('TajneHaslo123', $user['password_hash'], 'hasło nie może być zapisane jawnie');

        $this->assertCount(1, $this->mail->sent);
        $this->assertSame('verify', $this->mail->sent[0]['type']);
        $this->assertStringContainsString('verify-email.php?token=', $this->mail->sent[0]['link']);
    }

    public function testUnverifiedAccountCannotSignIn(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());

        $result = $auth->attemptLogin('anna.nowak@example.com', 'TajneHaslo123');

        $this->assertFalse($result['ok']);
        $this->assertSame('auth.error.email_not_verified', $result['error']);
        $this->assertFalse($auth->isAuthenticated());
    }

    public function testCorrectPasswordOnAnUnconfirmedAccountDoesNotCountTowardsTheLimit(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());

        // Kilka prób przed potwierdzeniem adresu nie może zablokować konta —
        // hasło jest poprawne, więc to nie zgadywanie.
        for ($attempt = 1; $attempt <= LoginThrottle::MAX_FAILURES_PER_EMAIL + 1; ++$attempt) {
            $this->assertSame(
                'auth.error.email_not_verified',
                $auth->attemptLogin('anna.nowak@example.com', 'TajneHaslo123', 'dashboard.php', '198.51.100.7')['error']
            );
        }

        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());

        $auth->verifyEmail($this->mail->lastToken('verify'));
        $this->assertTrue($auth->attemptLogin('anna.nowak@example.com', 'TajneHaslo123', 'dashboard.php', '198.51.100.7')['ok']);
    }

    public function testVerifiedAccountSignsInWithPassword(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());

        $verify = $auth->verifyEmail($this->mail->lastToken('verify'));
        $this->assertTrue($verify['ok']);

        $result = $auth->attemptLogin('anna.nowak@example.com', 'TajneHaslo123');

        $this->assertTrue($result['ok']);
        $this->assertSame('dashboard.php', $result['redirect']);
        $this->assertNotFalse($auth->currentUser());

        $user = (new UserManager($db))->findByEmail('anna.nowak@example.com');
        $this->assertNotFalse($user);
        $this->assertNotNull($user['email_verified_at']);
        $this->assertNotNull($user['last_login_at'], 'ostatnie logowanie zapisuje się w users');
    }

    public function testVerificationLinkWorksOnlyOnce(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());
        $token = $this->mail->lastToken('verify');

        $this->assertTrue($auth->verifyEmail($token)['ok']);
        $second = $auth->verifyEmail($token);

        $this->assertFalse($second['ok']);
        $this->assertSame('auth.error.link_used', $second['error']);
    }

    public function testExpiredVerificationLinkIsRejected(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());
        $token = $this->mail->lastToken('verify');

        $db->exec("UPDATE email_verifications SET expires_at = '2000-01-01 00:00:00'");

        $result = $auth->verifyEmail($token);
        $this->assertFalse($result['ok']);
        $this->assertSame('auth.error.link_expired', $result['error']);
    }

    public function testMadeUpTokenIsRejected(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);

        $this->assertSame('auth.error.link_invalid', $auth->verifyEmail(str_repeat('a', 64))['error']);
        $this->assertSame('auth.error.link_invalid', $auth->verifyEmail('nie-token')['error']);
    }

    public function testRegistrationWithTakenEmailChangesNothingAndLooksTheSame(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'anna.nowak@example.com', 'Anna', 'Nowak', 'InneHaslo123');
        $auth = $this->auth($db);

        $result = $auth->register(self::form());

        $this->assertTrue($result['ok'], 'komunikat nie zdradza, że adres jest zajęty');
        $this->assertSame([], $this->mail->sent, 'właściciel konta nie dostaje wiadomości');
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());

        // Hasło właściciela konta zostaje bez zmian.
        $this->assertTrue($auth->attemptLogin('anna.nowak@example.com', 'InneHaslo123')['ok']);
    }

    public function testSecondRegistrationOnAnUnconfirmedAddressSendsTheLinkAgain(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());
        $firstToken = $this->mail->lastToken('verify');

        // Osoba nie dostała wiadomości i wypełnia formularz ponownie — innym imieniem i hasłem.
        $result = $auth->register(self::form([
            'first_name'       => 'Podszywacz',
            'password'         => 'PodmienioneHaslo1',
            'password_confirm' => 'PodmienioneHaslo1',
        ]));

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $this->mail->sent, 'link potwierdzający wychodzi ponownie');
        $this->assertNotSame($firstToken, $this->mail->lastToken('verify'), 'nowy token unieważnia poprzedni');
        $this->assertSame('auth.error.link_used', $auth->verifyEmail($firstToken)['error']);

        // Dane konta zostają z pierwszej rejestracji — druga próba ich nie nadpisuje.
        $user = (new UserManager($db))->findByEmail('anna.nowak@example.com');
        $this->assertNotFalse($user);
        $this->assertSame('Anna', $user['first_name']);
        $this->assertFalse(password_verify('PodmienioneHaslo1', (string) $user['password_hash']));

        $this->assertTrue($auth->verifyEmail($this->mail->lastToken('verify'))['ok']);
        $this->assertTrue($auth->attemptLogin('anna.nowak@example.com', 'TajneHaslo123')['ok']);
    }

    public function testRegistrationRejectsWeakPasswordAndMismatch(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);

        $short = $auth->register(self::form(['password' => 'krotkie1', 'password_confirm' => 'krotkie1']));
        $this->assertSame('auth.error.password_too_short', $short['error']);

        $mismatch = $auth->register(self::form(['password_confirm' => 'InneHaslo123']));
        $this->assertSame('auth.error.password_mismatch', $mismatch['error']);

        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame([], $this->mail->sent);
    }

    public function testRegistrationIsRateLimitedPerEmail(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());

        // Kolejne prośby o link na ten sam adres: limit liczony z historii tokenów w bazie.
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $this->assertTrue($auth->resendVerification('anna.nowak@example.com')['ok']);
        }

        $blocked = $auth->resendVerification('anna.nowak@example.com');
        $this->assertFalse($blocked['ok']);
        $this->assertSame('auth.error.rate_limited', $blocked['error']);
    }

    public function testRegistrationIsRefusedWhenTheSwitchIsOff(): void
    {
        // Decyzja D3: instalacja może działać bez rejestracji publicznej — konta zakłada ADMIN.
        AuthConfig::useConfig(['self_registration' => false]);
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);

        $result = $auth->register(self::form());

        $this->assertFalse($result['ok']);
        $this->assertSame('auth.error.registration_disabled', $result['error']);
        $this->assertFalse($auth->selfRegistrationEnabled());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame([], $this->mail->sent);
    }

    public function testNewAccountsGetTheLowestRole(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());

        $user = (new UserManager($db))->findByEmail('anna.nowak@example.com');
        $this->assertNotFalse($user);
        $this->assertSame('OPERATOR', $user['role']);
    }

    // -------------------------------------------------------------- logowanie

    public function testWrongPasswordAndUnknownEmailGiveTheSameAnswer(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'znany@example.com');
        $auth = $this->auth($db);

        $wrongPassword = $auth->attemptLogin('znany@example.com', 'ZupelnieInne123');
        $unknownEmail = $auth->attemptLogin('nieznany@example.com', 'ZupelnieInne123');

        $this->assertSame('auth.error.invalid_credentials', $wrongPassword['error']);
        $this->assertSame('auth.error.invalid_credentials', $unknownEmail['error']);
    }

    public function testLoginIsBlockedAfterFiveFailedAttempts(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'znany@example.com', 'Jan', 'Kowalski', 'PoprawneHaslo123');
        $auth = $this->auth($db);

        for ($attempt = 1; $attempt <= LoginThrottle::MAX_FAILURES_PER_EMAIL; ++$attempt) {
            $this->assertSame(
                'auth.error.invalid_credentials',
                $auth->attemptLogin('znany@example.com', 'zle' . $attempt, 'dashboard.php', '198.51.100.7')['error']
            );
        }

        $blocked = $auth->attemptLogin('znany@example.com', 'PoprawneHaslo123', 'dashboard.php', '198.51.100.7');
        $this->assertSame('auth.error.login_blocked', $blocked['error'], 'blokada działa też dla poprawnego hasła');
        $this->assertFalse($auth->isAuthenticated());
    }

    public function testSuccessfulLoginClearsFailedAttempts(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'znany@example.com', 'Jan', 'Kowalski', 'PoprawneHaslo123');
        $auth = $this->auth($db);

        $auth->attemptLogin('znany@example.com', 'pomylka', 'dashboard.php', '198.51.100.7');
        $this->assertTrue($auth->attemptLogin('znany@example.com', 'PoprawneHaslo123', 'dashboard.php', '198.51.100.7')['ok']);

        $this->assertSame(
            0,
            (int) $db->query('SELECT COUNT(*) FROM login_attempts WHERE successful = 0')->fetchColumn()
        );
    }

    public function testDeactivatedAccountCannotSignIn(): void
    {
        $db = SqliteTestDatabase::create();
        $id = SqliteTestDatabase::seedUser($db, 'wylaczony@example.com');
        $db->exec('UPDATE users SET deactivated_at = CURRENT_TIMESTAMP WHERE id = ' . $id);
        $auth = $this->auth($db);

        $result = $auth->attemptLogin('wylaczony@example.com', 'TajneHaslo123');

        $this->assertFalse($result['ok']);
        $this->assertSame('auth.error.account_inactive', $result['error']);
    }

    public function testAccountWithoutPasswordCannotSignIn(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'bez.hasla@example.com', 'Bez', 'Hasla', '');
        $auth = $this->auth($db);

        $result = $auth->attemptLogin('bez.hasla@example.com', 'CokolwiekTutaj1');

        $this->assertSame('auth.error.invalid_credentials', $result['error']);
    }

    // -------------------------------------------------------- ustawienie hasła

    public function testPasswordLinkLetsAnInvitedAccountSetPasswordAndSignIn(): void
    {
        $db = SqliteTestDatabase::create();
        // Konto założone przez administratora: bez hasła, adres jeszcze niepotwierdzony.
        SqliteTestDatabase::seedUser($db, 'zaproszony@example.com', 'Ewa', 'Pawlak', '', false);
        $auth = $this->auth($db);

        $this->assertTrue($auth->requestPasswordSetLink('zaproszony@example.com')['ok']);
        $this->assertSame('password', $this->mail->sent[0]['type']);

        $token = $this->mail->lastToken('password');
        $result = $auth->setPasswordWithToken($token, 'NoweHaslo123', 'NoweHaslo123');

        $this->assertTrue($result['ok']);
        $this->assertTrue($auth->isAuthenticated(), 'ustawienie hasła od razu loguje');

        $user = (new UserManager($db))->findByEmail('zaproszony@example.com');
        $this->assertNotFalse($user);
        $this->assertNotNull($user['email_verified_at'], 'link z e-maila potwierdza też adres');
        $this->assertTrue(password_verify('NoweHaslo123', (string) $user['password_hash']));
    }

    public function testWeakPasswordDoesNotBurnThePasswordLink(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'zaproszony@example.com', 'Ewa', 'Pawlak', '', false);
        $auth = $this->auth($db);
        $auth->requestPasswordSetLink('zaproszony@example.com');
        $token = $this->mail->lastToken('password');

        $this->assertSame(
            'auth.error.password_too_short',
            $auth->setPasswordWithToken($token, 'krotkie1', 'krotkie1')['error']
        );

        // Ten sam link nadal działa — pomyłka w haśle nie zmusza do proszenia o nową wiadomość.
        $this->assertTrue($auth->setPasswordWithToken($token, 'NoweHaslo123', 'NoweHaslo123')['ok']);
    }

    public function testNewPasswordLiftsTheLoginBlock(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'zablokowany@example.com', 'Jan', 'Kowalski', 'StareHaslo123');
        $auth = $this->auth($db);

        for ($attempt = 1; $attempt <= LoginThrottle::MAX_FAILURES_PER_EMAIL; ++$attempt) {
            $auth->attemptLogin('zablokowany@example.com', 'zle' . $attempt, 'dashboard.php', '198.51.100.7');
        }
        $this->assertSame(
            'auth.error.login_blocked',
            $auth->attemptLogin('zablokowany@example.com', 'StareHaslo123', 'dashboard.php', '198.51.100.7')['error']
        );

        // Komunikat o blokadzie proponuje ustawienie nowego hasła — po nim konto musi działać od razu.
        $auth->requestPasswordSetLink('zablokowany@example.com');
        $this->assertTrue(
            $auth->setPasswordWithToken($this->mail->lastToken('password'), 'CalkiemNoweHaslo9', 'CalkiemNoweHaslo9')['ok']
        );
        $auth->logout();

        $this->assertTrue(
            $auth->attemptLogin('zablokowany@example.com', 'CalkiemNoweHaslo9', 'dashboard.php', '198.51.100.7')['ok']
        );
    }

    public function testPasswordLinkWorksOnlyOnce(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'zaproszony@example.com', 'Ewa', 'Pawlak', '', false);
        $auth = $this->auth($db);
        $auth->requestPasswordSetLink('zaproszony@example.com');
        $token = $this->mail->lastToken('password');

        $this->assertTrue($auth->setPasswordWithToken($token, 'NoweHaslo123', 'NoweHaslo123')['ok']);
        $this->assertSame(
            'auth.error.link_used',
            $auth->setPasswordWithToken($token, 'JeszczeInne123', 'JeszczeInne123')['error']
        );
    }

    public function testPasswordLinkForUnknownEmailLooksTheSameAndSendsNothing(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);

        $this->assertTrue($auth->requestPasswordSetLink('nieznany@example.com')['ok']);
        $this->assertSame([], $this->mail->sent);
    }

    public function testNewPasswordInvalidatesOtherPendingLinks(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);
        $auth->register(self::form());
        $verifyToken = $this->mail->lastToken('verify');

        $auth->requestPasswordSetLink('anna.nowak@example.com');
        $passwordToken = $this->mail->lastToken('password');
        $this->assertTrue($auth->setPasswordWithToken($passwordToken, 'NoweHaslo123', 'NoweHaslo123')['ok']);

        $this->assertSame('auth.error.link_used', $auth->verifyEmail($verifyToken)['error']);
    }

    // ------------------------------------------------------------ pozostałe

    public function testMailFailureIsReportedOnRegistration(): void
    {
        $db = SqliteTestDatabase::create();
        $this->mail->shouldFail = true;
        $auth = $this->auth($db);

        $result = $auth->register(self::form());

        $this->assertFalse($result['ok']);
        $this->assertSame('auth.error.mail_failed', $result['error']);
    }

    public function testRedirectOnlyAllowsKnownTargets(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = $this->auth($db);

        $this->assertSame('dashboard.php', $auth->sanitizeRedirect('https://zlosliwy.example/phishing'));
        $this->assertSame('dashboard.php?lang=en', $auth->sanitizeRedirect('dashboard.php?lang=en'));
    }

    public function testPasswordIsRehashedWhenTheAlgorithmCostChanges(): void
    {
        $db = SqliteTestDatabase::create();
        $id = SqliteTestDatabase::seedUser($db, 'stary.skrot@example.com');
        // Skrót o niższym koszcie niż domyślny — password_needs_rehash() to wykryje.
        $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([
            'hash' => password_hash('TajneHaslo123', PASSWORD_BCRYPT, ['cost' => 4]),
            'id'   => $id,
        ]);
        $auth = $this->auth($db);

        $this->assertTrue($auth->attemptLogin('stary.skrot@example.com', 'TajneHaslo123')['ok']);

        $hash = (string) $db->query('SELECT password_hash FROM users WHERE id = ' . $id)->fetchColumn();
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
        $this->assertTrue(password_verify('TajneHaslo123', $hash));
    }

    public function testAccountDeletionIsBlockedWhileCertificatesExist(): void
    {
        $db = SqliteTestDatabase::create();
        $id = SqliteTestDatabase::seedUser($db, 'opiekun@example.com');
        SqliteTestDatabase::seedCertificate($db, $id);
        $auth = $this->auth($db);
        $auth->login($id);

        $result = $auth->deleteCurrentAccount();

        $this->assertFalse($result['ok']);
        $this->assertSame('auth.error.delete_blocked', $result['error']);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testAccountWithoutCertificatesIsDeleted(): void
    {
        $db = SqliteTestDatabase::create();
        $id = SqliteTestDatabase::seedUser($db, 'bez.certyfikatow@example.com');
        $auth = $this->auth($db);
        $auth->login($id);

        $this->assertTrue($auth->deleteCurrentAccount()['ok']);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertFalse($auth->isAuthenticated());
    }

    public function testPasswordsAreStoredAsHashesOnly(): void
    {
        $this->assertNotSame('TajneHaslo123', PasswordPolicy::hash('TajneHaslo123'));
        $this->assertTrue(password_verify('TajneHaslo123', PasswordPolicy::hash('TajneHaslo123')));
    }
}

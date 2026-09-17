<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AuthManager;
use App\Session;
use App\UserManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingOtpMailer;
use Tests\Support\SqliteTestDatabase;

final class AuthManagerTest extends TestCase
{
    private RecordingOtpMailer $mail;

    protected function setUp(): void
    {
        Session::destroy();
        Session::ensureStarted();
        $_SESSION = [];
        $this->mail = new RecordingOtpMailer();
    }

    protected function tearDown(): void
    {
        Session::destroy();
        $_SESSION = [];
    }

    public function testRequestOtpForUnknownEmailDoesNotRevealAccount(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        $result = $auth->requestOtp('unknown@example.com');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['sent']);
        $this->assertSame([], $this->mail->sent);
        $this->assertNull($auth->getPendingEmail());
    }

    public function testRequestOtpForKnownUserSendsCode(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'known@example.com');
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        $result = $auth->requestOtp('known@example.com');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['sent']);
        $this->assertCount(1, $this->mail->sent);
        $this->assertSame('known@example.com', $this->mail->sent[0]['email']);
        $this->assertSame('known@example.com', $auth->getPendingEmail());
    }

    public function testCodeRequestLimitIsCountedInTheDatabaseAndSurvivesANewSession(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'known@example.com');
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        foreach (range(1, 3) as $attempt) {
            $this->assertTrue($auth->requestOtp('known@example.com', 'dashboard.php', '198.51.100.7')['sent'], 'próba ' . $attempt);
        }

        // Wyczyszczenie sesji (nowe ciasteczko) nie resetuje limitu — liczy go historia w bazie.
        Session::destroy();
        Session::ensureStarted();
        $_SESSION = [];

        $blocked = $auth->requestOtp('known@example.com', 'dashboard.php', '198.51.100.7');
        $this->assertFalse($blocked['ok']);
        $this->assertSame('auth.error.rate_limited', $blocked['error']);
        $this->assertCount(3, $this->mail->sent);
        $this->assertSame('198.51.100.7', $db->query('SELECT request_ip FROM login_otps ORDER BY id LIMIT 1')->fetchColumn());
    }

    public function testOneAddressCannotRequestCodesForManyAccounts(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = new AuthManager($db, new UserManager($db), $this->mail);
        foreach (range(1, 5) as $number) {
            SqliteTestDatabase::seedUser($db, 'osoba' . $number . '@example.com');
        }

        $sent = 0;
        foreach (range(1, 4) as $number) {
            foreach (range(1, 3) as $attempt) {
                if (!empty($auth->requestOtp('osoba' . $number . '@example.com', 'dashboard.php', '203.0.113.9')['sent'])) {
                    ++$sent;
                }
            }
        }

        // Limit na adres e-mail to 3 wysyłki, na adres IP — 10 w tym samym oknie czasowym.
        $this->assertSame(10, $sent);
        $this->assertSame('auth.error.rate_limited', $auth->requestOtp('osoba5@example.com', 'dashboard.php', '203.0.113.9')['error']);
        $this->assertTrue($auth->requestOtp('osoba5@example.com', 'dashboard.php', '203.0.113.10')['sent']);
    }

    public function testVerifyOtpLogsUserIn(): void
    {
        $db = SqliteTestDatabase::create();
        $userId = SqliteTestDatabase::seedUser($db, 'login@example.com');
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        $auth->requestOtp('login@example.com');
        $code = $this->mail->sent[0]['code'];

        $result = $auth->verifyOtp('login@example.com', $code);

        $this->assertTrue($result['ok']);
        $this->assertSame('dashboard.php', $result['redirect']);
        $this->assertTrue($auth->isAuthenticated());
        $this->assertSame($userId, (int) $_SESSION['user_id']);
    }

    public function testVerifyOtpRejectsInvalidCode(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'login@example.com');
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        $auth->requestOtp('login@example.com');

        $result = $auth->verifyOtp('login@example.com', '000000');

        $this->assertFalse($result['ok']);
        $this->assertFalse($auth->isAuthenticated());
    }

    public function testMailFailureIsReportedAndLeavesNoPendingLogin(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'known@example.com');
        $failingMail = new class () implements \App\OtpMailer {
            public function sendOtpCode(string $toEmail, string $code): bool
            {
                return false;
            }
        };
        $auth = new AuthManager($db, new UserManager($db), $failingMail);

        $result = $auth->requestOtp('known@example.com');

        $this->assertFalse($result['ok']);
        $this->assertSame('auth.error.mail_failed', $result['error']);
        $this->assertNull($auth->getPendingEmail());
    }

    public function testDeactivatedAccountLooksLikeUnknownEmail(): void
    {
        $db = SqliteTestDatabase::create();
        $userId = SqliteTestDatabase::seedUser($db, 'inactive@example.com');
        $db->exec("UPDATE users SET deactivated_at = '2026-09-17 10:00:00' WHERE id = {$userId}");
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        $result = $auth->requestOtp('inactive@example.com');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['sent']);
        $this->assertSame([], $this->mail->sent);
    }

    public function testDeactivationEndsAnExistingSession(): void
    {
        $db = SqliteTestDatabase::create();
        $userId = SqliteTestDatabase::seedUser($db, 'session@example.com');
        $auth = new AuthManager($db, new UserManager($db), $this->mail);
        $auth->login($userId);
        $this->assertNotFalse($auth->currentUser());

        $db->exec("UPDATE users SET deactivated_at = '2026-09-17 10:00:00' WHERE id = {$userId}");

        $this->assertFalse($auth->currentUser());
        $this->assertFalse($auth->isAuthenticated());
    }

    public function testVerifyOtpWorksWhenSessionPendingWasLost(): void
    {
        $db = SqliteTestDatabase::create();
        $userId = SqliteTestDatabase::seedUser($db, 'login@example.com');
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        $auth->requestOtp('login@example.com');
        $code = $this->mail->sent[0]['code'];
        $auth->clearPendingOtp();

        $result = $auth->verifyOtp('login@example.com', $code);

        $this->assertTrue($result['ok']);
        $this->assertTrue($auth->isAuthenticated());
        $this->assertSame($userId, (int) $_SESSION['user_id']);
    }

    public function testClearPendingOtpAllowsLeavingVerifyStep(): void
    {
        $db = SqliteTestDatabase::create();
        SqliteTestDatabase::seedUser($db, 'login@example.com');
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        $auth->requestOtp('login@example.com');
        $this->assertSame('login@example.com', $auth->getPendingEmail());

        $auth->clearPendingOtp();
        $this->assertNull($auth->getPendingEmail());
    }

    public function testSanitizeRedirectBlocksOpenRedirect(): void
    {
        $db = SqliteTestDatabase::create();
        $auth = new AuthManager($db, new UserManager($db), $this->mail);

        $this->assertSame(
            'dashboard.php',
            $auth->sanitizeRedirect('https://evil.com/dashboard.php')
        );
    }

    public function testDeleteCurrentAccountIsBlockedWhenUserOwnsSubscriptions(): void
    {
        $db = SqliteTestDatabase::create();
        $userId = SqliteTestDatabase::seedUser($db, 'owner@example.com');
        SqliteTestDatabase::seedCertificate($db, $userId);
        $users = new UserManager($db);
        $auth = new AuthManager($db, $users, $this->mail);
        $auth->login($userId);

        $result = $auth->deleteCurrentAccount();

        $this->assertFalse($result['ok']);
        $this->assertSame('auth.error.delete_blocked', $result['error']);
        $this->assertNotFalse($users->findById($userId));
        $this->assertTrue($auth->isAuthenticated());
    }

    public function testDeleteCurrentAccountRemovesAccountWithoutSubscriptions(): void
    {
        $db = SqliteTestDatabase::create();
        $userId = SqliteTestDatabase::seedUser($db, 'leaving@example.com');
        $users = new UserManager($db);
        $auth = new AuthManager($db, $users, $this->mail);
        $auth->login($userId);

        $result = $auth->deleteCurrentAccount();

        $this->assertTrue($result['ok']);
        $this->assertFalse($users->findById($userId));
        $this->assertFalse($auth->isAuthenticated());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Csrf;
use App\Database;
use App\MigrationRunner;
use App\Session;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private ?PDO $db = null;

    protected function setUp(): void
    {
        if (!getenv('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=1 to run MySQL integration tests.');
        }

        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }
    }

    public function testRunPendingLeavesDatabaseUpToDate(): void
    {
        $runner = new MigrationRunner($this->db);
        $runner->runPending();

        $this->assertSame([], $runner->status());

        $stmt = $this->db->query(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'login_otps' LIMIT 1"
        );
        $this->assertNotFalse($stmt->fetchColumn());
    }
}

final class AddSubscriptionApiTest extends TestCase
{
    protected function setUp(): void
    {
        Session::destroy();
        Session::ensureStarted();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Session::destroy();
        $_SESSION = [];
    }

    public function testApiRejectsMissingCsrfToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        ob_start();
        include dirname(__DIR__, 2) . '/api/add_subscription.php';
        $output = ob_get_clean();

        $this->assertSame(403, http_response_code());
        $payload = json_decode($output, true);
        $this->assertFalse($payload['success']);
    }

    public function testApiRejectsInvalidCsrfToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid';

        ob_start();
        include dirname(__DIR__, 2) . '/api/add_subscription.php';
        $output = ob_get_clean();

        $this->assertSame(403, http_response_code());
    }

    public function testApiRejectsUnauthenticatedRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();

        ob_start();
        include dirname(__DIR__, 2) . '/api/add_subscription.php';
        $output = ob_get_clean();

        $this->assertSame(401, http_response_code());
        $payload = json_decode($output, true);
        $this->assertFalse($payload['success']);
    }
}

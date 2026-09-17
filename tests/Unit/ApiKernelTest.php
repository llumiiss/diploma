<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Api\PersonalManagerController;
use App\Http\ApiKernel;
use App\Http\Request;
use App\ManagerSubscriptionManager;
use App\Service\Actor;
use App\Service\ServiceException;
use App\Session;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Wspólna obsługa endpointów JSON — zastępuje nieuruchamiany wcześniej AddSubscriptionApiTest (§5 pkt 21).
 */
final class ApiKernelTest extends TestCase
{
    private const USER = ['id' => 7, 'role' => 'OPERATOR', 'first_name' => 'Tomasz', 'last_name' => 'Wróbel', 'email' => 't@example.com'];

    protected function setUp(): void
    {
        Session::ensureStarted();
        $_SESSION = [];
    }

    private function kernel(array|false $user = self::USER, bool $csrfValid = true): ApiKernel
    {
        return new ApiKernel(static fn (): array|false => $user, static fn (?string $token): bool => $csrfValid);
    }

    public function testWriteWithoutValidCsrfTokenIsRejectedBeforeAuthentication(): void
    {
        $response = $this->kernel(false, false)->handle(
            new Request('POST', [], ['action' => 'create']),
            static fn (): array => []
        );

        $this->assertSame(403, $response->status);
        $this->assertFalse($response->payload['success']);
    }

    public function testUnauthenticatedRequestGets401(): void
    {
        $response = $this->kernel(false)->handle(new Request('GET'), static fn (): array => []);

        $this->assertSame(401, $response->status);
    }

    public function testReadDoesNotRequireCsrfToken(): void
    {
        $response = $this->kernel(self::USER, false)->handle(
            new Request('GET'),
            static fn (Request $request, Actor $actor): array => ['who' => $actor->fullName()]
        );

        $this->assertSame(200, $response->status);
        $this->assertSame(['success' => true, 'who' => 'Tomasz Wróbel'], $response->payload);
    }

    public function testMethodAndMalformedJsonAreRejected(): void
    {
        $this->assertSame(405, $this->kernel()->handle(new Request('DELETE'), static fn (): array => [])->status);
        $this->assertSame(400, $this->kernel()->handle(new Request('POST', [], [], 'token', true), static fn (): array => [])->status);
    }

    public function testServiceErrorsAreMappedToStatusCodesWithFieldErrors(): void
    {
        $validation = $this->kernel()->handle(new Request('POST'), static function (): array {
            throw ServiceException::validation(['name' => 'Pole wymagane']);
        });
        $this->assertSame(422, $validation->status);
        $this->assertEquals((object) ['name' => 'Pole wymagane'], $validation->payload['errors']);

        $conflict = $this->kernel()->handle(new Request('POST'), static function (): array {
            throw ServiceException::conflict('Istnieje', ['existing' => ['id' => 3]]);
        });
        $this->assertSame(409, $conflict->status);
        $this->assertSame(['id' => 3], $conflict->payload['existing']);
    }

    public function testUnexpectedErrorDoesNotLeakDetails(): void
    {
        $previous = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $response = $this->kernel()->handle(new Request('GET'), static function (): array {
                throw new \RuntimeException('SQLSTATE secret details');
            });
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $this->assertSame(500, $response->status);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->payload['message']);
    }

    public function testPersonalManagerRejectsImpossibleDate(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $controller = new PersonalManagerController(new ManagerSubscriptionManager($db));

        $response = $this->kernel()->handle(new Request('POST', [], [
            'nazwa_uslugi'             => 'Netflix',
            'koszt_pln'                => '43,00',
            'data_nastepnej_platnosci' => '2026-02-31',
        ]), $controller);

        $this->assertSame(422, $response->status);
        $this->assertObjectHasProperty('data_nastepnej_platnosci', $response->payload['errors']);
    }

    public function testPersonalManagerSavesValidSubscription(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec(
            'CREATE TABLE manager_subskrypcji (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, nazwa_uslugi TEXT NOT NULL,
                mail_subskrypcji TEXT NULL, username_konta TEXT NULL, koszt_pln REAL NOT NULL,
                data_nastepnej_platnosci TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $controller = new PersonalManagerController(new ManagerSubscriptionManager($db));

        $response = $this->kernel()->handle(new Request('POST', [], [
            'nazwa_uslugi'             => 'Spotify',
            'mail_subskrypcji'         => 'Ja@Example.com',
            'koszt_pln'                => '23,99',
            'data_nastepnej_platnosci' => '2026-10-01',
        ]), $controller);

        $this->assertSame(200, $response->status);
        $this->assertSame('Spotify', $response->payload['subscription']['nazwa_uslugi']);
        $this->assertSame('ja@example.com', $response->payload['subscription']['mail_subskrypcji']);
        $this->assertSame(7, (int) $response->payload['subscription']['user_id']);
    }
}

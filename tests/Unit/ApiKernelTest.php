<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ApiKernel;
use App\Http\Request;
use App\Service\Actor;
use App\Service\ServiceException;
use App\Session;
use PHPUnit\Framework\TestCase;

/**
 * Wspólna obsługa endpointów JSON: metoda, CSRF, sesja i mapowanie błędów na kody HTTP.
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
}

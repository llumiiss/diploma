<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\OAuth2TokenProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Dostawca tokenów XOAUTH2. Testy nie odpytują dostawcy poczty — token dostępowy
 * podkładamy w pliku cache, tak jak zapisałaby go poprzednia wysyłka.
 */
final class OAuth2TokenProviderTest extends TestCase
{
    private string $cacheDir = '';

    /** @var array<string, string> */
    private array $config = [
        'provider'      => 'google',
        'user_email'    => 'skrzynka@example.com',
        'client_id'     => 'klient-123',
        'client_secret' => 'sekret',
        'refresh_token' => 'odswiezajacy',
    ];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/certisub-oauth2-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    private function seedCachedToken(string $token, int $expiresAt): void
    {
        $identity = $this->config['user_email'] . '|' . $this->config['client_id'];
        file_put_contents(
            $this->cacheDir . '/' . hash('sha256', $identity) . '.json',
            (string) json_encode(['access_token' => $token, 'expires_at' => $expiresAt])
        );
    }

    public function testBuildsTheXoauth2StringFromACachedToken(): void
    {
        $this->seedCachedToken('token-abc', time() + 1800);
        $provider = new OAuth2TokenProvider($this->config, $this->cacheDir);

        $decoded = base64_decode($provider->getOauth64(), true);

        $this->assertSame("user=skrzynka@example.com\001auth=Bearer token-abc\001\001", $decoded);
    }

    public function testExpiredCachedTokenIsNotReused(): void
    {
        $this->seedCachedToken('token-stary', time() - 10);
        // Bez sieci odświeżenie musi się nie udać — ważne, że nie podaje wygasłego tokenu.
        $provider = new OAuth2TokenProvider(
            $this->config + ['token_endpoint' => 'http://127.0.0.1:9/token'],
            $this->cacheDir
        );

        $this->expectException(RuntimeException::class);
        $provider->getOauth64();
    }

    public function testMissingCredentialsAreReportedClearly(): void
    {
        $provider = new OAuth2TokenProvider(
            ['user_email' => 'skrzynka@example.com', 'client_id' => '', 'refresh_token' => ''],
            $this->cacheDir
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/client_id|refresh_token/');
        $provider->getOauth64();
    }

    public function testMissingMailboxIsReportedBeforeAnyNetworkCall(): void
    {
        $provider = new OAuth2TokenProvider(['client_id' => 'x', 'refresh_token' => 'y'], $this->cacheDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/user_email/');
        $provider->getOauth64();
    }
}

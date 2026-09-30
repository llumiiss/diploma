<?php

declare(strict_types=1);

namespace App\Mail;

use App\MailConfig;
use PHPMailer\PHPMailer\OAuthTokenProvider;
use RuntimeException;

/**
 * Autoryzacja SMTP przez OAuth2 (mechanizm XOAUTH2) — używana przy wysyłce wiadomości
 * z linkiem potwierdzającym adres e-mail.
 *
 * Zamiast hasła do skrzynki aplikacja trzyma trzy dane wydane przez dostawcę poczty:
 * client_id, client_secret i refresh_token. Przed wysyłką wymienia refresh_token na
 * krótkotrwały access_token (grant „refresh_token”, RFC 6749 §6) i podaje go serwerowi
 * SMTP w ciągu XOAUTH2. Hasło użytkownika nigdy nie trafia do konfiguracji.
 *
 * Token dostępowy jest zapisywany w storage/cache/oauth2 razem z czasem wygaśnięcia,
 * więc kolejne wysyłki w ciągu godziny nie odpytują dostawcy ponownie. Plik z tokenem
 * jest poza katalogiem publicznym (storage/.htaccess: Require all denied).
 *
 * Refresh token zdobywa się raz, poleceniem: php scripts/oauth2-token.php
 *
 * @see https://developers.google.com/gmail/imap/xoauth2-protocol
 */
final class OAuth2TokenProvider implements OAuthTokenProvider
{
    /** Domyślne punkty wymiany tokenu — nadpisywalne kluczem oauth2.token_endpoint. */
    private const TOKEN_ENDPOINTS = [
        'google'    => 'https://oauth2.googleapis.com/token',
        'microsoft' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
    ];

    /** Token odświeżamy z zapasem, żeby nie wygasł w trakcie rozmowy z serwerem SMTP. */
    private const EXPIRY_MARGIN_SECONDS = 60;

    /** @var array<string, mixed> */
    private array $config;

    private ?string $accessToken = null;
    private int $expiresAt = 0;

    /**
     * @param array<string, mixed>|null $config Blok „oauth2” konfiguracji poczty; null = z pliku.
     */
    public function __construct(?array $config = null, private readonly ?string $cacheDirectory = null)
    {
        $this->config = $config ?? MailConfig::oauth2();
    }

    /**
     * Ciąg XOAUTH2 w postaci wymaganej przez SMTP: user=<adres>^Aauth=Bearer <token>^A^A
     */
    public function getOauth64(): string
    {
        $user = $this->string('user_email');
        if ($user === '') {
            throw new RuntimeException('OAuth2: brak adresu skrzynki (mail.oauth2.user_email).');
        }

        return base64_encode('user=' . $user . "\001auth=Bearer " . $this->accessToken() . "\001\001");
    }

    /**
     * Ważny token dostępowy: z pamięci procesu, z pliku cache albo świeżo wymieniony.
     */
    public function accessToken(): string
    {
        $now = time();

        if ($this->accessToken !== null && $this->expiresAt > $now + self::EXPIRY_MARGIN_SECONDS) {
            return $this->accessToken;
        }

        $cached = $this->readCache();
        if ($cached !== null && $cached['expires_at'] > $now + self::EXPIRY_MARGIN_SECONDS) {
            $this->accessToken = $cached['access_token'];
            $this->expiresAt = $cached['expires_at'];

            return $this->accessToken;
        }

        $fresh = $this->refresh();
        $this->accessToken = $fresh['access_token'];
        $this->expiresAt = $fresh['expires_at'];
        $this->writeCache($fresh);

        return $this->accessToken;
    }

    /**
     * Wymiana refresh_token → access_token.
     *
     * @return array{access_token: string, expires_at: int}
     */
    private function refresh(): array
    {
        $clientId = $this->string('client_id');
        $clientSecret = $this->string('client_secret');
        $refreshToken = $this->string('refresh_token');

        if ($clientId === '' || $refreshToken === '') {
            throw new RuntimeException('OAuth2: brak client_id lub refresh_token w config/mail.local.php.');
        }

        $payload = http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        [$status, $body] = $this->post($this->tokenEndpoint(), $payload);

        /** @var array<string, mixed>|null $data */
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('OAuth2: nieczytelna odpowiedź serwera tokenów (HTTP ' . $status . ').');
        }

        if ($status !== 200 || !isset($data['access_token']) || !is_string($data['access_token'])) {
            $error = (string) ($data['error_description'] ?? $data['error'] ?? 'nieznany błąd');

            throw new RuntimeException('OAuth2: odświeżenie tokenu nie udało się — ' . $error);
        }

        $lifetime = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;

        return [
            'access_token' => $data['access_token'],
            'expires_at'   => time() + max($lifetime, 60),
        ];
    }

    /**
     * @return array{int, string} Kod HTTP i treść odpowiedzi.
     */
    private function post(string $url, string $payload): array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('OAuth2: nie udało się zainicjować połączenia HTTP.');
            }

            curl_setopt_array($handle, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);

            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);

            if ($body === false) {
                throw new RuntimeException('OAuth2: połączenie z serwerem tokenów nie udało się — ' . $error);
            }

            return [$status, (string) $body];
        }

        // Instalacje bez rozszerzenia cURL (rzadkie, ale Laragon pozwala je wyłączyć).
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $payload,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('OAuth2: połączenie z serwerem tokenów nie udało się.');
        }

        // $http_response_header ustawia sam wrapper HTTP, gdy żądanie doszło do serwera.
        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $body];
    }

    private function tokenEndpoint(): string
    {
        $custom = $this->string('token_endpoint');
        if ($custom !== '') {
            return $custom;
        }

        $provider = strtolower($this->string('provider'));
        $endpoint = self::TOKEN_ENDPOINTS[$provider] ?? self::TOKEN_ENDPOINTS['google'];

        $tenant = $this->string('tenant_id');

        return str_replace('{tenant}', $tenant !== '' ? $tenant : 'common', $endpoint);
    }

    /**
     * @return array{access_token: string, expires_at: int}|null
     */
    private function readCache(): ?array
    {
        $file = $this->cacheFile();
        if ($file === null || !is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['access_token'], $data['expires_at'])) {
            return null;
        }

        if (!is_string($data['access_token'])) {
            return null;
        }

        return ['access_token' => $data['access_token'], 'expires_at' => (int) $data['expires_at']];
    }

    /**
     * @param array{access_token: string, expires_at: int} $token
     */
    private function writeCache(array $token): void
    {
        $file = $this->cacheFile();
        if ($file === null) {
            return;
        }

        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }

        if (@file_put_contents($file, json_encode($token), LOCK_EX) !== false) {
            @chmod($file, 0600);
        }
    }

    /**
     * Osobny plik na każdą skrzynkę — bez adresu w nazwie, żeby nie zdradzać jej na dysku.
     */
    private function cacheFile(): ?string
    {
        $directory = $this->cacheDirectory ?? dirname(__DIR__, 2) . '/storage/cache/oauth2';
        $identity = $this->string('user_email') . '|' . $this->string('client_id');

        if (trim($identity, '|') === '') {
            return null;
        }

        return $directory . '/' . hash('sha256', $identity) . '.json';
    }

    private function string(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}

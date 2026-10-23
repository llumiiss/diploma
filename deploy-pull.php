<?php

declare(strict_types=1);

/**
 * Wdrożenie kodu z GitHuba na hostingu bez SSH (InfinityFree) — zamiast ręcznego FTP.
 * Pobiera paczkę gałęzi z repozytorium, rozpakowuje ją i podmienia pliki aplikacji.
 *
 *   https://twoja-domena/deploy-pull.php?token=...              — wdraża gałąź master
 *   https://twoja-domena/deploy-pull.php?token=...&dry=1        — tylko pokazuje, co by się zmieniło
 *   https://twoja-domena/deploy-pull.php?token=...&ref=etap-11  — inna gałąź lub tag
 *
 * Czego NIE nadpisuje: config/*.local.php (sekrety), storage/, logs/ — oraz nie wgrywa
 * tests/, docs/ i plików narzędzi deweloperskich (lista w App\Deploy\CodeDeployer).
 * Czego NIE robi: nie usuwa plików nieobecnych w paczce i nie rusza vendor/ (biblioteki
 * zmieniają się rzadko — przy zmianie composer.lock wgraj vendor/ przez FTP).
 *
 * Token w config/deploy.local.php (klucz `pull_token`), tak jak przy deploy-migrate.php.
 * Po zakończeniu prac zmień token na nowy losowy albo usuń ten plik z serwera.
 */

require __DIR__ . '/bootstrap.php';

use App\Deploy\CodeDeployer;
use App\Deploy\DeployException;
use App\Deploy\TarArchive;

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

const REPOSITORY = 'llumiiss/diploma';
const DEFAULT_REF = 'master';

$deployConfigFile = __DIR__ . '/config/deploy.local.php';
if (!is_file($deployConfigFile)) {
    http_response_code(403);
    exit("Brak config/deploy.local.php — skopiuj config/deploy.local.php.example i ustaw własny token.\n");
}

/** @var array<string, string> $deployConfig */
$deployConfig = require $deployConfigFile;
$expectedToken = (string) ($deployConfig['pull_token'] ?? '');
$token = (string) ($_GET['token'] ?? '');

if ($expectedToken === '' || $expectedToken === 'CHANGE_ME' || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    exit("Forbidden.\n");
}

$ref = (string) ($_GET['ref'] ?? DEFAULT_REF);
if (preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $ref) !== 1) {
    http_response_code(400);
    exit("Nieprawidłowa nazwa gałęzi.\n");
}

$dryRun = ($_GET['dry'] ?? '') === '1';
$url = 'https://codeload.github.com/' . REPOSITORY . '/tar.gz/refs/heads/' . $ref;

printf("Repozytorium: %s\nGałąź:        %s\nTryb:         %s\n\n", REPOSITORY, $ref, $dryRun ? 'podgląd (bez zapisu)' : 'wdrożenie');

$started = microtime(true);

try {
    [$status, $body] = download($url);

    if ($status === 404) {
        http_response_code(502);
        exit("GitHub nie zna gałęzi „{$ref}” (HTTP 404).\n");
    }

    if ($status !== 200) {
        http_response_code(502);
        exit("Nie udało się pobrać paczki (HTTP {$status}). Sprawdź, czy hosting pozwala na wychodzące HTTPS.\n");
    }

    if (strlen($body) > CodeDeployer::MAX_ARCHIVE_BYTES) {
        http_response_code(502);
        exit("Paczka jest większa niż " . (CodeDeployer::MAX_ARCHIVE_BYTES / 1048576) . " MB — przerwane.\n");
    }

    printf("Paczka: %.1f kB\n", strlen($body) / 1024);

    $deployer = new CodeDeployer(__DIR__);
    $report = $deployer->deploy(TarArchive::fromGzip($body), $dryRun);

    printf(
        "\nPliki %s: %d\nBez zmian: %d\nPominięte (testy, dokumentacja, narzędzia): %d\nChronione (nie nadpisane): %d\n",
        $dryRun ? 'do zmiany' : 'zapisane',
        count($report['written']),
        $report['unchanged'],
        $report['skipped'],
        count($report['protected'])
    );

    if ($report['rejected'] !== []) {
        printf("Odrzucone ścieżki (poza katalogiem aplikacji): %d\n", count($report['rejected']));
    }

    if ($report['written'] !== []) {
        echo "\n";
        foreach ($report['written'] as $path) {
            echo '  ', $dryRun ? '~' : '+', ' ', $path, "\n";
        }
    }

    if (!$dryRun) {
        printf("\nWyczyszczony cache: %d plików\n", $deployer->clearCache());
    }

    printf("\nGotowe w %.1f s.\n", microtime(true) - $started);

    if (!$dryRun) {
        echo "\nSprawdź teraz: zaległe migracje (deploy-migrate.php) i stronę główną.\n";
    }
} catch (DeployException $e) {
    http_response_code(500);
    exit("\nBŁĄD: " . $e->getMessage() . "\n");
} catch (Throwable $e) {
    http_response_code(500);
    exit("\nBŁĄD (" . $e::class . '): ' . $e->getMessage() . "\n");
}

/**
 * @return array{0: int, 1: string}
 */
function download(string $url): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            return [0, ''];
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'CertiSub-Deploy/1.0',
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, is_string($body) ? $body : ''];
    }

    $context = stream_context_create(['http' => [
        'method'          => 'GET',
        'timeout'         => 120,
        'follow_location' => 1,
        'max_redirects'   => 3,
        'ignore_errors'   => true,
        'header'          => "User-Agent: CertiSub-Deploy/1.0\r\n",
    ]]);

    $handle = @fopen($url, 'rb', false, $context);
    if ($handle === false) {
        return [0, ''];
    }

    $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
    $body = stream_get_contents($handle);
    fclose($handle);

    $status = 0;
    foreach (is_array($headers) ? $headers : [] as $header) {
        if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return [$status, is_string($body) ? $body : ''];
}

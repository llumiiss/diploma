<?php

declare(strict_types=1);

namespace App\Deploy;

/**
 * Wdrożenie kodu z paczki (archiwum gałęzi z GitHuba) na katalog aplikacji.
 *
 * Hosting bez SSH (InfinityFree) aktualizuje się ręcznie po FTP — przy kilku poprawkach dziennie
 * to główny hamulec. Ta klasa robi to samo co ręczne wgranie plików: bierze wpisy z archiwum,
 * odrzuca to, czego nigdy nie wolno nadpisać, i zapisuje resztę.
 *
 * Zasady, które trzymają wdrożenie bezpiecznym:
 *  - **nie nadpisujemy konfiguracji z sekretami** (`config/*.local.php`) ani danych i logów
 *    (`storage/`, `logs/`) — paczka ich nie zawiera, ale lista wykluczeń jest po to, żeby
 *    przypadkowy plik w repozytorium niczego nie skasował,
 *  - **ścieżki wychodzące z katalogu aplikacji są odrzucane** (`..`, ścieżki bezwzględne, dysk
 *    Windows) — archiwum przychodzi z sieci, więc traktujemy je jak dane obce,
 *  - plik o niezmienionej treści jest pomijany, żeby raport pokazywał faktyczne zmiany,
 *  - wdrożenie nie usuwa plików, których nie ma w paczce (bezpieczniej na współdzielonym hostingu).
 */
final class CodeDeployer
{
    /**
     * Czego nie ruszamy nigdy. Dopasowanie po przedrostku ścieżki wewnątrz paczki
     * albo po wzorcu `fnmatch` dla pojedynczych plików.
     *
     * @var list<string>
     */
    public const PROTECTED_PATHS = [
        'config/*.local.php',
        'config/deploy.local.php',
        'storage/',
        'logs/',
        '.git/',
        '.github/',
        '.claude/',
        'node_modules/',
    ];

    /**
     * Czego nie wgrywamy, bo na serwerze jest tylko ciężarem (produkcja ich nie uruchamia).
     *
     * @var list<string>
     */
    public const SKIPPED_PATHS = [
        'tests/',
        'docs/',
        '.gitattributes',
        '.gitignore',
        '.php-cs-fixer.dist.php',
        'phpstan.neon',
        'phpunit.xml.dist',
        'package.json',
        'package-lock.json',
        'tailwind.config.js',
        'composer.json',
        'composer.lock',
    ];

    /**
     * Pliki pilnujące katalogów — wolno je wgrać także pod `storage/` i `logs/`.
     *
     * @var list<string>
     */
    public const DIRECTORY_GUARDS = ['.htaccess', '.gitkeep'];

    /** Największa rozsądna paczka — zabezpieczenie przed wyczerpaniem pamięci na hostingu. */
    public const MAX_ARCHIVE_BYTES = 40 * 1024 * 1024;

    /**
     * @param string $root katalog aplikacji (webroot)
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Zapisuje pliki z archiwum. `$dryRun` tylko liczy zmiany, nie zapisując niczego.
     *
     * @return array{written: list<string>, unchanged: int, skipped: int, protected: list<string>, rejected: list<string>, bytes: int}
     *
     * @throws DeployException
     */
    public function deploy(TarArchive $archive, bool $dryRun = false): array
    {
        $written = [];
        $protected = [];
        $rejected = [];
        $unchanged = 0;
        $skipped = 0;
        $bytes = 0;

        foreach ($archive->entries() as $entry) {
            $path = self::stripArchiveRoot($entry['path']);

            if ($path === '' || $entry['type'] !== 'file') {
                continue;
            }

            if (!self::isSafePath($path)) {
                $rejected[] = $entry['path'];
                continue;
            }

            if (self::isProtected($path)) {
                $protected[] = $path;
                continue;
            }

            if (self::matches($path, self::SKIPPED_PATHS)) {
                ++$skipped;
                continue;
            }

            $target = $this->root . '/' . $path;

            if (is_file($target) && file_get_contents($target) === $entry['content']) {
                ++$unchanged;
                continue;
            }

            if (!$dryRun) {
                $this->write($target, $entry['content']);
            }

            $written[] = $path;
            $bytes += $entry['size'];
        }

        if ($written === [] && $unchanged === 0) {
            throw new DeployException('Archiwum nie zawiera plików aplikacji — sprawdź adres paczki.');
        }

        sort($written);
        sort($protected);

        return [
            'written'   => $written,
            'unchanged' => $unchanged,
            'skipped'   => $skipped,
            'protected' => array_values(array_unique($protected)),
            'rejected'  => $rejected,
            'bytes'     => $bytes,
        ];
    }

    /**
     * Plik, którego wdrożenie nie może nadpisać. Wyjątkiem są pliki pilnujące katalogów
     * (`.htaccess` blokujący dostęp po HTTP i `.gitkeep`): to nie dane użytkownika, a brak
     * `storage/.htaccess` na serwerze otwierałby załączniki światu — wdrożenie musi móc je przywrócić.
     */
    public static function isProtected(string $path): bool
    {
        if (!self::matches($path, self::PROTECTED_PATHS)) {
            return false;
        }

        return !in_array(basename($path), self::DIRECTORY_GUARDS, true);
    }

    /**
     * Archiwa z GitHuba mają jeden katalog na wierzchu (`diploma-master/`) — ścieżki liczymy od jego wnętrza.
     */
    public static function stripArchiveRoot(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $slash = strpos($path, '/');

        return $slash === false ? '' : substr($path, $slash + 1);
    }

    /**
     * Ścieżka musi zostać wewnątrz katalogu aplikacji.
     */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        // Ścieżka bezwzględna albo z literą dysku.
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $patterns
     */
    public static function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '/')) {
                if (str_starts_with($path, $pattern)) {
                    return true;
                }

                continue;
            }

            if ($path === $pattern || fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zapis „najpierw obok, potem zamiana” — przerwane wdrożenie nie zostawia połowy pliku,
     * który PHP próbowałby wykonać.
     *
     * @throws DeployException
     */
    private function write(string $target, string $content): void
    {
        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new DeployException('Nie mogę utworzyć katalogu ' . $directory);
        }

        $temporary = $target . '.deploy-' . bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $content) === false) {
            throw new DeployException('Nie mogę zapisać pliku ' . $target . ' (brak prawa zapisu?)');
        }

        if (!@rename($temporary, $target)) {
            @unlink($temporary);

            throw new DeployException('Nie mogę podmienić pliku ' . $target);
        }
    }

    /**
     * Usuwa pliki cache po wdrożeniu — nowy kod nie może czytać wyników policzonych starym.
     *
     * @return int liczba usuniętych plików
     */
    public function clearCache(): int
    {
        $removed = 0;

        foreach (glob($this->root . '/storage/cache/*') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Limit zapytań do zewnętrznego rejestru na konto.
 *
 * Przycisk „pobierz dane po NIP” wysyła zapytanie do Ministerstwa Finansów, a rejestr ma własne
 * limity i nie jest nasz — jedno konto klikające bez opamiętania mogłoby odciąć od rejestru całą
 * instalację. Licznik trzymamy w plikach (katalog cache), bo to jedyna rzecz w aplikacji, która
 * nie musi przeżyć wdrożenia ani być spójna między serwerami — po wyczyszczeniu cache limit
 * zaczyna się od nowa i nic złego się nie dzieje.
 *
 * Okno jest kroczące w prostej postaci: licznik z czasem pierwszego zapytania, zerowany po
 * upływie okna. Wystarcza, żeby powstrzymać pętlę i przypadkowe trzymanie klawisza.
 */
final class LookupThrottle
{
    public const DEFAULT_LIMIT = 20;
    public const DEFAULT_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly string $directory,
        private readonly int $limit = self::DEFAULT_LIMIT,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {
    }

    /**
     * Zapisuje zapytanie i mówi, czy zmieściło się w limicie. Gdy katalog jest niedostępny
     * (hosting tylko do odczytu), przepuszczamy — limit jest zabezpieczeniem, nie kontrolą dostępu.
     */
    public function allow(string $key): bool
    {
        $file = $this->file($key);

        if ($file === null) {
            return true;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            return true;
        }

        $now = time();
        $state = $this->read($file);

        if ($state === null || $now - $state['since'] >= $this->windowSeconds) {
            $state = ['since' => $now, 'count' => 0];
        }

        ++$state['count'];
        @file_put_contents($file, (string) json_encode($state), LOCK_EX);

        return $state['count'] <= $this->limit;
    }

    /**
     * Ile zapytań zostało w bieżącym oknie (do pokazania w komunikacie).
     */
    public function remaining(string $key): int
    {
        $file = $this->file($key);
        $state = $file === null ? null : $this->read($file);

        if ($state === null || time() - $state['since'] >= $this->windowSeconds) {
            return $this->limit;
        }

        return max(0, $this->limit - $state['count']);
    }

    /**
     * @return array{since: int, count: int}|null
     */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        /** @var array<string, mixed>|null $data */
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data) || !isset($data['since'], $data['count'])) {
            return null;
        }

        return ['since' => (int) $data['since'], 'count' => (int) $data['count']];
    }

    private function file(string $key): ?string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $key);

        if ($safe === null || $safe === '') {
            return null;
        }

        return rtrim($this->directory, '/\\') . '/' . $safe . '.json';
    }
}

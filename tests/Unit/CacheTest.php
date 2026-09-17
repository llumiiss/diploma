<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'certisub-cache-' . bin2hex(random_bytes(4));
        Cache::useDirectory($this->directory);
        Cache::enable();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        Cache::useDirectory(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'certisub-cache-tests');
    }

    public function testValueIsComputedOnceAndReadFromTheFileLater(): void
    {
        $calls = 0;
        $factory = static function () use (&$calls): array {
            ++$calls;

            return ['total' => 7];
        };

        $this->assertSame(['total' => 7], Cache::remember('dashboard:ADMIN:all', 60, $factory));
        $this->assertSame(['total' => 7], Cache::remember('dashboard:ADMIN:all', 60, $factory));
        $this->assertSame(1, $calls);
    }

    public function testDifferentKeysDoNotShareData(): void
    {
        Cache::remember('dashboard:ADMIN:all', 60, static fn (): array => ['total' => 10]);
        $operator = Cache::remember('dashboard:OPERATOR:4', 60, static fn (): array => ['total' => 2]);

        $this->assertSame(['total' => 2], $operator);
        $this->assertSame(['total' => 10], Cache::remember('dashboard:ADMIN:all', 60, static fn (): array => ['total' => 999]));
    }

    public function testAnyDataChangeInvalidatesEveryEntry(): void
    {
        Cache::remember('task-stats:ADMIN:1', 600, static fn (): array => ['open' => 3]);

        Cache::invalidate();

        $this->assertSame(['open' => 4], Cache::remember('task-stats:ADMIN:1', 600, static fn (): array => ['open' => 4]));
    }

    public function testExpiredEntryIsRecomputed(): void
    {
        Cache::remember('dashboard:ADMIN:all', 1, static fn (): array => ['total' => 1]);
        $file = (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: []);
        $entryFile = array_values(array_filter($file, static fn (string $path): bool => !str_ends_with($path, 'version.json')))[0];
        $entry = json_decode((string) file_get_contents($entryFile), true);
        $entry['expires'] = time() - 5;
        file_put_contents($entryFile, (string) json_encode($entry));

        $this->assertSame(['total' => 2], Cache::remember('dashboard:ADMIN:all', 60, static fn (): array => ['total' => 2]));
    }

    public function testDisabledCacheAlwaysComputesAndUnwritableDirectoryDoesNotBreakAnything(): void
    {
        Cache::disable();
        $calls = 0;
        $factory = static function () use (&$calls): int {
            ++$calls;

            return $calls;
        };
        $this->assertSame(1, Cache::remember('x', 60, $factory));
        $this->assertSame(2, Cache::remember('x', 60, $factory));

        Cache::enable();
        Cache::useDirectory('Z:/nie-ma-takiego-katalogu/cache');
        $this->assertSame(3, Cache::remember('x', 60, $factory));
        $this->assertSame(4, Cache::remember('x', 60, $factory));
    }
}

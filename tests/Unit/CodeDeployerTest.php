<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Deploy\CodeDeployer;
use App\Deploy\DeployException;
use App\Deploy\TarArchive;
use PHPUnit\Framework\TestCase;
use Tests\Support\TarBuilder;

/**
 * Wdrożenie kodu z paczki (deploy-pull.php na hostingu bez SSH). Testy pracują na katalogu
 * tymczasowym i archiwum zbudowanym w pamięci — bez sieci i bez rozszerzenia phar.
 */
final class CodeDeployerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/certisub-deploy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/storage/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->root);
    }

    /**
     * @param array<string, string> $files
     */
    private function archive(array $files): TarArchive
    {
        return TarArchive::fromGzip(TarBuilder::gzip($files));
    }

    public function testWritesApplicationFilesFromTheArchive(): void
    {
        $report = (new CodeDeployer($this->root))->deploy($this->archive([
            'diploma-master/index.php'          => '<?php echo "nowy";',
            'diploma-master/classes/Rbac.php'   => '<?php // rola',
            'diploma-master/lang/pl.php'        => '<?php return [];',
        ]));

        $this->assertSame(['classes/Rbac.php', 'index.php', 'lang/pl.php'], $report['written']);
        $this->assertSame('<?php echo "nowy";', file_get_contents($this->root . '/index.php'));
        $this->assertSame('<?php // rola', file_get_contents($this->root . '/classes/Rbac.php'));
    }

    public function testNeverOverwritesLocalConfigurationStorageOrLogs(): void
    {
        file_put_contents($this->root . '/config/mail.local.php', '<?php return ["sekret"];');
        file_put_contents($this->root . '/storage/cache/aggregate.json', '{"liczby":1}');

        $report = (new CodeDeployer($this->root))->deploy($this->archive([
            'diploma-master/index.php'                     => '<?php // kod',
            'diploma-master/config/mail.local.php'         => '<?php return ["z paczki"];',
            'diploma-master/config/deploy.local.php'       => '<?php return ["token"];',
            'diploma-master/storage/cache/aggregate.json'  => 'podmienione',
            'diploma-master/logs/renewals.log'             => 'podmienione',
        ]));

        $this->assertSame('<?php return ["sekret"];', file_get_contents($this->root . '/config/mail.local.php'));
        $this->assertSame('{"liczby":1}', file_get_contents($this->root . '/storage/cache/aggregate.json'));
        $this->assertFileDoesNotExist($this->root . '/logs/renewals.log');
        $this->assertSame(['config/deploy.local.php', 'config/mail.local.php', 'logs/renewals.log', 'storage/cache/aggregate.json'], $report['protected']);
        $this->assertSame(['index.php'], $report['written']);
    }

    public function testDoesNotUploadTestsDocsAndDeveloperTooling(): void
    {
        $report = (new CodeDeployer($this->root))->deploy($this->archive([
            'diploma-master/index.php'                  => '<?php // kod',
            'diploma-master/tests/Unit/RbacTest.php'    => '<?php // test',
            'diploma-master/docs/MAPA_PROJEKTU.md'      => '# mapa',
            'diploma-master/phpunit.xml.dist'           => '<phpunit/>',
            'diploma-master/composer.lock'              => '{}',
        ]));

        $this->assertSame(['index.php'], $report['written']);
        $this->assertSame(4, $report['skipped']);
        $this->assertFileDoesNotExist($this->root . '/tests/Unit/RbacTest.php');
        $this->assertFileDoesNotExist($this->root . '/docs/MAPA_PROJEKTU.md');
    }

    public function testRejectsPathsLeavingTheApplicationDirectory(): void
    {
        $report = (new CodeDeployer($this->root))->deploy($this->archive([
            'diploma-master/index.php'               => '<?php // kod',
            'diploma-master/../../etc/passwd'        => 'root:x:0:0',
            'diploma-master/../poza-aplikacja.php'   => '<?php // zlosliwy',
        ]));

        $this->assertSame(['index.php'], $report['written']);
        $this->assertCount(2, $report['rejected']);
        $this->assertFileDoesNotExist(dirname($this->root) . '/poza-aplikacja.php');
    }

    public function testUnchangedFilesAreNotRewritten(): void
    {
        file_put_contents($this->root . '/index.php', '<?php // to samo');
        $before = filemtime($this->root . '/index.php');

        $report = (new CodeDeployer($this->root))->deploy($this->archive([
            'diploma-master/index.php'   => '<?php // to samo',
            'diploma-master/logout.php'  => '<?php // nowy',
        ]));

        $this->assertSame(['logout.php'], $report['written']);
        $this->assertSame(1, $report['unchanged']);
        $this->assertSame($before, filemtime($this->root . '/index.php'));
    }

    public function testDryRunReportsChangesWithoutTouchingFiles(): void
    {
        $report = (new CodeDeployer($this->root))->deploy($this->archive([
            'diploma-master/index.php' => '<?php // nowy',
        ]), true);

        $this->assertSame(['index.php'], $report['written']);
        $this->assertFileDoesNotExist($this->root . '/index.php');
    }

    public function testEmptyArchiveIsReportedAsAnError(): void
    {
        $this->expectException(DeployException::class);

        (new CodeDeployer($this->root))->deploy($this->archive([
            'diploma-master/tests/Unit/RbacTest.php' => '<?php // tylko test',
        ]));
    }

    public function testCacheIsClearedAfterDeployment(): void
    {
        file_put_contents($this->root . '/storage/cache/a.json', '{}');
        file_put_contents($this->root . '/storage/cache/b.json', '{}');

        $this->assertSame(2, (new CodeDeployer($this->root))->clearCache());
        $this->assertSame([], glob($this->root . '/storage/cache/*.json'));
    }

    public function testDirectoryGuardsUnderStorageAndLogsCanBeRestored(): void
    {
        // Brak storage/.htaccess na serwerze otwierałby załączniki po HTTP — wdrożenie musi móc go przywrócić,
        // ale samych plików w storage/ nadal nie rusza.
        file_put_contents($this->root . '/storage/cache/aggregate.json', '{"stare":1}');

        $report = (new CodeDeployer($this->root))->deploy($this->archive([
            'diploma-master/index.php'                     => '<?php // kod',
            'diploma-master/storage/.htaccess'             => 'Require all denied',
            'diploma-master/storage/cache/.gitkeep'        => '',
            'diploma-master/storage/cache/aggregate.json'  => '{"z paczki":1}',
        ]));

        $this->assertSame('Require all denied', file_get_contents($this->root . '/storage/.htaccess'));
        $this->assertFileExists($this->root . '/storage/cache/.gitkeep');
        $this->assertSame('{"stare":1}', file_get_contents($this->root . '/storage/cache/aggregate.json'));
        $this->assertSame(['storage/cache/aggregate.json'], $report['protected']);
    }

    public function testProtectedAndSkippedListsCoverTheObviousTraps(): void
    {
        $this->assertTrue(CodeDeployer::matches('config/mail.local.php', CodeDeployer::PROTECTED_PATHS));
        $this->assertTrue(CodeDeployer::matches('config/intake.local.php', CodeDeployer::PROTECTED_PATHS));
        $this->assertTrue(CodeDeployer::matches('storage/attachments/umowa.pdf', CodeDeployer::PROTECTED_PATHS));
        $this->assertTrue(CodeDeployer::matches('logs/mail.log', CodeDeployer::PROTECTED_PATHS));
        $this->assertTrue(CodeDeployer::isProtected('storage/attachments/umowa.pdf'));
        $this->assertFalse(CodeDeployer::isProtected('storage/.htaccess'));
        $this->assertFalse(CodeDeployer::isProtected('logs/.gitkeep'));
        $this->assertFalse(CodeDeployer::matches('config/mail.php', CodeDeployer::PROTECTED_PATHS));
        $this->assertFalse(CodeDeployer::matches('classes/Mailer.php', CodeDeployer::PROTECTED_PATHS));

        $this->assertFalse(CodeDeployer::isSafePath('../poza.php'));
        $this->assertFalse(CodeDeployer::isSafePath('/etc/passwd'));
        $this->assertFalse(CodeDeployer::isSafePath('C:/Windows/system.ini'));
        $this->assertTrue(CodeDeployer::isSafePath('classes/Service/TaskService.php'));
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? self::removeDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}

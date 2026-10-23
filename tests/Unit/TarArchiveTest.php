<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Deploy\DeployException;
use App\Deploy\TarArchive;
use PHPUnit\Framework\TestCase;
use Tests\Support\TarBuilder;

/**
 * Czytnik archiwów tar/tar.gz w czystym PHP — używany przez deploy-pull.php, bo na hostingu
 * współdzielonym phar i ZipArchive bywają wyłączone.
 */
final class TarArchiveTest extends TestCase
{
    /**
     * @return list<array{path: string, type: string, size: int, content: string}>
     */
    private static function read(string $gzipped): array
    {
        return iterator_to_array(TarArchive::fromGzip($gzipped)->entries(), false);
    }

    public function testReadsFilesWithTheirContent(): void
    {
        $entries = self::read(TarBuilder::gzip([
            'paczka/index.php'        => '<?php echo 1;',
            'paczka/lang/pl.php'      => "<?php\nreturn ['a' => 'b'];\n",
        ]));

        $this->assertCount(2, $entries);
        $this->assertSame('paczka/index.php', $entries[0]['path']);
        $this->assertSame('<?php echo 1;', $entries[0]['content']);
        $this->assertSame(13, $entries[0]['size']);
        $this->assertSame("<?php\nreturn ['a' => 'b'];\n", $entries[1]['content']);
    }

    public function testHandlesEmptyFilesAndFilesCrossingTheBlockBoundary(): void
    {
        $long = str_repeat('x', 1025);

        $entries = self::read(TarBuilder::gzip([
            'paczka/.gitkeep'   => '',
            'paczka/duzy.txt'   => $long,
            'paczka/potem.txt'  => 'koniec',
        ]));

        $this->assertSame('', $entries[0]['content']);
        $this->assertSame($long, $entries[1]['content']);
        $this->assertSame('koniec', $entries[2]['content'], 'wyrównanie do 512 bajtów nie może zgubić kolejnego wpisu');
    }

    public function testDirectoriesAreMarkedAndPaxGlobalHeaderIsSkipped(): void
    {
        $builder = (new TarBuilder())
            ->addPaxGlobalHeader()
            ->addDirectory('paczka/classes')
            ->addFile('paczka/classes/Rbac.php', '<?php // rola');

        $entries = iterator_to_array((new TarArchive($builder->finish()))->entries(), false);

        $this->assertCount(2, $entries, 'nagłówek pax_global_header nie jest wpisem do wdrożenia');
        $this->assertSame('dir', $entries[0]['type']);
        $this->assertSame('paczka/classes', $entries[0]['path']);
        $this->assertSame('file', $entries[1]['type']);
    }

    public function testSupportsGnuLongNames(): void
    {
        $path = 'paczka/classes/Service/' . str_repeat('Bardzo', 12) . 'DlugaNazwa.php';
        $builder = (new TarBuilder())->addFileWithLongName($path, '<?php // kod');

        $entries = iterator_to_array((new TarArchive($builder->finish()))->entries(), false);

        $this->assertCount(1, $entries);
        $this->assertSame($path, $entries[0]['path']);
        $this->assertSame('<?php // kod', $entries[0]['content']);
    }

    public function testRejectsSomethingThatIsNotAGzipArchive(): void
    {
        $this->expectException(DeployException::class);
        $this->expectExceptionMessageMatches('/nie są archiwum/u');

        TarArchive::fromGzip('<html><body>Strona logowania hostingu</body></html>');
    }

    public function testRejectsCorruptedGzipData(): void
    {
        $this->expectException(DeployException::class);

        TarArchive::fromGzip("\x1f\x8b" . random_bytes(64));
    }

    public function testRejectsAHeaderPointingOutsideTheArchive(): void
    {
        $broken = TarBuilder::gzip(['paczka/plik.txt' => 'tresc']);
        $raw = (string) gzdecode($broken);
        // Rozmiar wpisu podkręcony na 1 MB — danych w pliku nie ma.
        $raw = substr_replace($raw, str_pad(sprintf('%011o', 1048576) . ' ', 12, "\0"), 124, 12);

        $this->expectException(DeployException::class);
        $this->expectExceptionMessageMatches('/Uszkodzony nagłówek/u');

        iterator_to_array((new TarArchive($raw))->entries(), false);
    }
}

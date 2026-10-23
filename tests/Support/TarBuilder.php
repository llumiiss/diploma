<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Buduje archiwum tar (i .tar.gz) w pamięci, żeby testy czytnika i wdrożenia nie potrzebowały
 * ani sieci, ani plików pomocniczych, ani rozszerzenia phar.
 */
final class TarBuilder
{
    private const BLOCK = 512;

    private string $raw = '';

    /**
     * @param array<string, string> $files ścieżka w archiwum => treść
     */
    public static function gzip(array $files): string
    {
        $builder = new self();
        foreach ($files as $path => $content) {
            $builder->addFile($path, $content);
        }

        return (string) gzencode($builder->finish());
    }

    public function addFile(string $path, string $content, string $type = '0'): self
    {
        $this->raw .= self::header($path, strlen($content), $type);
        $this->raw .= $content;

        $padding = (self::BLOCK - strlen($content) % self::BLOCK) % self::BLOCK;
        $this->raw .= str_repeat("\0", $padding);

        return $this;
    }

    public function addDirectory(string $path): self
    {
        $this->raw .= self::header(rtrim($path, '/') . '/', 0, '5');

        return $this;
    }

    /**
     * Wpis z nagłówkiem GNU „długa nazwa” — używany, gdy ścieżka nie mieści się w 100 znakach.
     */
    public function addFileWithLongName(string $path, string $content): self
    {
        $this->addFile('././@LongLink', $path . "\0", 'L');
        $this->raw .= self::header(substr($path, 0, 100), strlen($content), '0');
        $this->raw .= $content;
        $this->raw .= str_repeat("\0", (self::BLOCK - strlen($content) % self::BLOCK) % self::BLOCK);

        return $this;
    }

    /**
     * Nagłówek pax z globalnym komentarzem — tak zaczynają się paczki z GitHuba.
     */
    public function addPaxGlobalHeader(string $comment = 'comment=deadbeef'): self
    {
        $record = sprintf("%d %s\n", strlen($comment) + strlen((string) (strlen($comment) + 4)) + 2, $comment);

        return $this->addFile('pax_global_header', $record, 'g');
    }

    public function finish(): string
    {
        return $this->raw . str_repeat("\0", self::BLOCK * 2);
    }

    private static function header(string $path, int $size, string $type): string
    {
        $name = substr($path, 0, 100);
        $prefix = strlen($path) > 100 ? substr($path, 100, 155) : '';

        $header = str_pad($name, 100, "\0");
        $header .= str_pad('0000644', 8, "\0");          // mode
        $header .= str_pad('0000000', 8, "\0");          // uid
        $header .= str_pad('0000000', 8, "\0");          // gid
        $header .= str_pad(sprintf('%011o', $size) . ' ', 12, "\0");
        $header .= str_pad(sprintf('%011o', 0) . ' ', 12, "\0");
        $header .= str_repeat(' ', 8);                   // suma kontrolna — liczona niżej
        $header .= $type;
        $header .= str_repeat("\0", 100);                // linkname
        $header .= "ustar\0" . '00';
        $header .= str_repeat("\0", 32 + 32 + 8 + 8);    // uname, gname, devmajor, devminor
        $header .= str_pad($prefix, 155, "\0");
        $header = str_pad($header, self::BLOCK, "\0");

        $checksum = 0;
        for ($i = 0; $i < self::BLOCK; ++$i) {
            $checksum += ord($header[$i]);
        }

        return substr_replace($header, str_pad(sprintf('%06o', $checksum) . "\0 ", 8, ' '), 148, 8);
    }
}

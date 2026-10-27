<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader-bundle package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\Tests\Integration\Dump;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\DumpFormat;
use DumpLoader\Dump\DumpNotFound;
use DumpLoader\Dump\FileSize;
use DumpLoader\Dump\Flysystem\FlysystemDumpSource;
use DumpLoader\Dump\StreamedSqlInput;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlysystemDumpSourceTest extends TestCase
{
    #[Test]
    public function it_should_list_only_sql_dumps_of_the_directory(): void
    {
        $filesystem = $this->filesystem([
            'dumps/prod.sql' => 'SELECT 1;',
            'dumps/prod.sql.gz' => 'gzipped',
            'dumps/notes.txt' => 'ignored',
            'dumps/archive/old.sql' => 'ignored',
            'elsewhere/other.sql' => 'ignored',
        ]);
        $source = new FlysystemDumpSource($filesystem, 'dumps');

        $paths = array_map(
            static fn (Dump $dump): string => $dump->path,
            iterator_to_array($source->list()),
        );

        sort($paths);
        self::assertSame(['dumps/prod.sql', 'dumps/prod.sql.gz'], $paths);
    }

    #[Test]
    public function it_should_list_the_most_recent_dump_first(): void
    {
        $filesystem = $this->filesystem([]);
        $filesystem->write('dumps/old.sql', 'SELECT 1;', ['timestamp' => 1_700_000_000]);
        $filesystem->write('dumps/recent.sql', 'SELECT 2;', ['timestamp' => 1_800_000_000]);
        $source = new FlysystemDumpSource($filesystem, 'dumps');

        $dumps = iterator_to_array($source->list());

        self::assertSame('dumps/recent.sql', $dumps[0]->path);
        self::assertSame('dumps/old.sql', $dumps[1]->path);
    }

    #[Test]
    public function it_should_describe_a_dump(): void
    {
        $filesystem = $this->filesystem([]);
        $filesystem->write('dumps/prod.sql.gz', 'gzipped', ['timestamp' => 1_700_000_000]);
        $source = new FlysystemDumpSource($filesystem, 'dumps');

        $dump = $source->find('dumps/prod.sql.gz');

        self::assertSame('prod.sql.gz', $dump->name);
        self::assertSame(7, $dump->size->bytes);
        self::assertSame(DumpFormat::GzippedSql, $dump->format);
        self::assertSame(1_700_000_000, $dump->modifiedAt->getTimestamp());
    }

    #[Test]
    public function it_should_refuse_a_path_outside_of_the_listing(): void
    {
        $filesystem = $this->filesystem([
            'dumps/prod.sql' => 'SELECT 1;',
            'elsewhere/secret.sql' => 'SELECT 2;',
        ]);
        $source = new FlysystemDumpSource($filesystem, 'dumps');

        $this->expectException(DumpNotFound::class);
        $source->find('elsewhere/secret.sql');
    }

    #[Test]
    public function it_should_refuse_a_missing_path(): void
    {
        $source = new FlysystemDumpSource($this->filesystem([]), 'dumps');

        $this->expectException(DumpNotFound::class);
        $source->find('dumps/nothing.sql');
    }

    #[Test]
    public function it_should_open_a_dump_as_a_sql_stream(): void
    {
        $filesystem = $this->filesystem(['dumps/prod.sql' => "SELECT 1;\nSELECT 2;\n"]);
        $source = new FlysystemDumpSource($filesystem, 'dumps');

        $input = $source->open($source->find('dumps/prod.sql'));

        self::assertInstanceOf(StreamedSqlInput::class, $input);
        self::assertSame("SELECT 1;\nSELECT 2;\n", implode('', iterator_to_array($input, false)));
        self::assertSame(20, $input->rawBytesRead()->bytes);
    }

    #[Test]
    public function it_should_read_the_root_directory_by_default(): void
    {
        $source = new FlysystemDumpSource($this->filesystem(['prod.sql' => 'SELECT 1;']));

        self::assertCount(1, $source->list());
    }

    #[Test]
    public function it_should_refuse_to_open_a_dump_that_vanished(): void
    {
        $source = new FlysystemDumpSource($this->filesystem([]));

        $this->expectException(DumpNotFound::class);

        $source->open(Dump::fromPath('prod.sql', FileSize::fromBytes(10), new \DateTimeImmutable('2026-09-18 08:00:00')));
    }

    /**
     * @param array<string, string> $files
     */
    private function filesystem(array $files): FilesystemOperator
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());

        foreach ($files as $path => $contents) {
            $filesystem->write($path, $contents);
        }

        return $filesystem;
    }
}

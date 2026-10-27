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

namespace DumpLoader\Tests\Unit\Dump;

use DumpLoader\Dump\DumpFormat;
use DumpLoader\Dump\DumpNotFound;
use DumpLoader\Dump\GzipSqlStream;
use DumpLoader\Dump\PlainSqlStream;
use DumpLoader\Dump\UnsupportedDumpFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DumpFormatTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/dumps';

    #[DataProvider('recognizedPaths')]
    #[Test]
    public function it_should_be_deduced_from_the_extension(string $path, DumpFormat $expected): void
    {
        self::assertSame($expected, DumpFormat::fromPath($path));
    }

    /**
     * @return iterable<string, array{string, DumpFormat}>
     */
    public static function recognizedPaths(): iterable
    {
        yield 'sql' => ['/dumps/backup.sql', DumpFormat::Sql];
        yield 'uppercase sql' => ['/dumps/BACKUP.SQL', DumpFormat::Sql];
        yield 'gzipped sql' => ['/dumps/backup.sql.gz', DumpFormat::GzippedSql];
        yield 'uppercase gzipped sql' => ['/dumps/BACKUP.SQL.GZ', DumpFormat::GzippedSql];
        yield 'dots in the name' => ['/dumps/2026-01-05.preprod.sql.gz', DumpFormat::GzippedSql];
        yield 'no directory' => ['backup.sql', DumpFormat::Sql];
    }

    #[DataProvider('rejectedPaths')]
    #[Test]
    public function it_should_refuse_an_unknown_extension(string $path): void
    {
        $this->expectException(UnsupportedDumpFormat::class);

        DumpFormat::fromPath($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedPaths(): iterable
    {
        yield 'text file' => ['/dumps/readme.txt'];
        yield 'plain gzip' => ['/dumps/backup.gz'];
        yield 'no extension' => ['/dumps/backup'];
        yield 'other archive' => ['/dumps/backup.sql.zip'];
        yield 'extension only' => ['/dumps/.sql.bak'];
        yield 'empty path' => [''];
    }

    #[Test]
    public function it_should_open_a_plain_stream(): void
    {
        $stream = DumpFormat::Sql->open(self::open('simple.sql'));

        self::assertInstanceOf(PlainSqlStream::class, $stream);
        self::assertSame(file_get_contents(self::FIXTURES.'/simple.sql'), implode('', iterator_to_array($stream, false)));
    }

    #[Test]
    public function it_should_open_a_gzipped_stream(): void
    {
        $stream = DumpFormat::GzippedSql->open(self::open('simple.sql.gz'));

        self::assertInstanceOf(GzipSqlStream::class, $stream);
        self::assertSame(file_get_contents(self::FIXTURES.'/simple.sql'), implode('', iterator_to_array($stream, false)));
    }

    #[Test]
    public function it_should_read_the_beginning_of_a_plain_file(): void
    {
        $header = DumpFormat::Sql->header(self::FIXTURES.'/simple.sql', 64);

        self::assertGreaterThanOrEqual(64, \strlen($header));
        self::assertStringStartsWith('-- MySQL dump', $header);
    }

    #[Test]
    public function it_should_read_the_beginning_of_a_gzipped_file_decompressed(): void
    {
        $header = DumpFormat::GzippedSql->header(self::FIXTURES.'/simple.sql.gz', 64);

        self::assertStringStartsWith('-- MySQL dump', $header);
    }

    #[Test]
    public function it_should_read_the_whole_file_when_the_window_exceeds_its_size(): void
    {
        $whole = DumpFormat::Sql->header(self::FIXTURES.'/simple.sql', \PHP_INT_MAX);

        self::assertSame(file_get_contents(self::FIXTURES.'/simple.sql'), $whole);
    }

    #[Test]
    public function it_should_refuse_a_file_it_cannot_open(): void
    {
        $this->expectException(DumpNotFound::class);

        DumpFormat::Sql->header(self::FIXTURES.'/nothing.sql', 64);
    }

    /**
     * @return resource
     */
    private static function open(string $fixture)
    {
        $stream = fopen(self::FIXTURES.'/'.$fixture, 'r');
        self::assertIsResource($stream);

        return $stream;
    }
}

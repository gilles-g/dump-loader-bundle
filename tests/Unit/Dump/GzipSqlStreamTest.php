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

use DumpLoader\Dump\CorruptedDump;
use DumpLoader\Dump\GzipSqlStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GzipSqlStreamTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/dumps';

    #[Test]
    public function it_should_inflate_the_whole_file(): void
    {
        $stream = GzipSqlStream::over(self::open('simple.sql.gz'));

        self::assertSame(self::expectedSql(), implode('', iterator_to_array($stream, false)));
    }

    #[Test]
    public function it_should_inflate_every_member_of_a_multi_member_gzip(): void
    {
        $stream = GzipSqlStream::over(self::open('multi-member.sql.gz'), readSize: 64);

        self::assertSame(self::expectedSql(), implode('', iterator_to_array($stream, false)));
    }

    #[Test]
    public function it_should_refuse_a_truncated_gzip(): void
    {
        $stream = GzipSqlStream::over(self::open('truncated.sql.gz'));

        $this->expectException(CorruptedDump::class);

        iterator_to_array($stream, false);
    }

    #[Test]
    public function it_should_refuse_a_file_that_is_not_gzipped_at_all(): void
    {
        $source = fopen('php://memory', 'r+');
        self::assertIsResource($source);
        fwrite($source, 'SELECT 1; -- plain SQL wearing a .gz extension');
        rewind($source);

        $this->expectException(CorruptedDump::class);

        iterator_to_array(GzipSqlStream::over($source), false);
    }

    #[Test]
    public function it_should_yield_chunks_of_at_most_the_given_size(): void
    {
        $stream = GzipSqlStream::over(self::open('simple.sql.gz'), readSize: 64, chunkSize: 128);

        $chunks = iterator_to_array($stream, false);

        self::assertGreaterThan(1, \count($chunks));
        foreach ($chunks as $chunk) {
            self::assertNotSame('', $chunk);
            self::assertLessThanOrEqual(128, \strlen($chunk));
        }
        self::assertSame(self::expectedSql(), implode('', $chunks));
    }

    #[Test]
    public function it_should_count_the_compressed_bytes_read(): void
    {
        $stream = GzipSqlStream::over(self::open('simple.sql.gz'), readSize: 64);

        self::assertSame(0, $stream->rawBytesRead()->bytes);

        $readings = [];
        foreach ($stream as $chunk) {
            $readings[] = $stream->rawBytesRead()->bytes;
        }

        self::assertSame(filesize(self::FIXTURES.'/simple.sql.gz'), $stream->rawBytesRead()->bytes);
        $sorted = $readings;
        sort($sorted);
        self::assertSame($sorted, $readings);
    }

    private static function expectedSql(): string
    {
        $sql = file_get_contents(self::FIXTURES.'/simple.sql');
        self::assertIsString($sql);

        return $sql;
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

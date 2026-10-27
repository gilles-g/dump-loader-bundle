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

use DumpLoader\Dump\PlainSqlStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlainSqlStreamTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/dumps';

    #[Test]
    public function it_should_yield_the_whole_file(): void
    {
        $stream = PlainSqlStream::over(self::open('simple.sql'));

        self::assertSame(self::expectedSql(), implode('', iterator_to_array($stream, false)));
    }

    #[Test]
    public function it_should_yield_chunks_of_at_most_the_given_size(): void
    {
        $stream = PlainSqlStream::over(self::open('simple.sql'), chunkSize: 64);

        $chunks = iterator_to_array($stream, false);

        self::assertGreaterThan(1, \count($chunks));
        foreach ($chunks as $chunk) {
            self::assertNotSame('', $chunk);
            self::assertLessThanOrEqual(64, \strlen($chunk));
        }
        self::assertSame(self::expectedSql(), implode('', $chunks));
    }

    #[Test]
    public function it_should_yield_nothing_for_an_empty_stream(): void
    {
        $empty = fopen('php://memory', 'r+');
        self::assertIsResource($empty);

        $stream = PlainSqlStream::over($empty);

        self::assertSame([], iterator_to_array($stream, false));
        self::assertSame(0, $stream->rawBytesRead()->bytes);
    }

    #[Test]
    public function it_should_count_nothing_before_being_read(): void
    {
        $stream = PlainSqlStream::over(self::open('simple.sql'));

        self::assertSame(0, $stream->rawBytesRead()->bytes);
    }

    #[Test]
    public function it_should_count_the_raw_bytes_read_while_yielding(): void
    {
        $stream = PlainSqlStream::over(self::open('simple.sql'), chunkSize: 64);

        $readings = [];
        foreach ($stream as $chunk) {
            $readings[] = $stream->rawBytesRead()->bytes;
        }

        self::assertSame(filesize(self::FIXTURES.'/simple.sql'), end($readings));
        self::assertSame($readings, array_values(array_unique($readings)));
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

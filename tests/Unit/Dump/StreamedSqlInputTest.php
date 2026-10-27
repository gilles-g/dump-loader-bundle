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
use DumpLoader\Dump\StreamedSqlInput;
use DumpLoader\Execution\ShellCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamedSqlInputTest extends TestCase
{
    #[Test]
    public function it_should_read_the_beginning_without_losing_it(): void
    {
        $input = $this->over('SELECT 1;SELECT 2;SELECT 3;');

        $header = $input->header(8);

        self::assertSame('SELECT 1;', $header);
        self::assertSame('SELECT 1;SELECT 2;SELECT 3;', implode('', iterator_to_array($input, false)));
    }

    #[Test]
    public function it_should_read_the_beginning_only_once(): void
    {
        $input = $this->over('SELECT 1;SELECT 2;');

        $first = $input->header(8);
        $second = $input->header(8);

        self::assertSame('SELECT 1;', $first);
        self::assertSame('SELECT 1;', $second);
    }

    #[Test]
    public function it_should_stop_at_the_end_of_a_stream_shorter_than_the_window(): void
    {
        $input = $this->over('SELECT 1;');

        self::assertSame('SELECT 1;', $input->header(4096));
    }

    #[Test]
    public function it_should_yield_everything_when_the_beginning_was_never_read(): void
    {
        $input = $this->over('SELECT 1;SELECT 2;');

        self::assertSame('SELECT 1;SELECT 2;', implode('', iterator_to_array($input, false)));
    }

    #[Test]
    public function it_should_feed_a_command_with_itself(): void
    {
        $input = $this->over('SELECT 1;');

        $command = $input->feed(new ShellCommand(argv: ['mysql', 'shop']));

        self::assertSame(['mysql', 'shop'], $command->argv);
        self::assertSame($input, $command->input);
    }

    #[Test]
    public function it_should_report_the_raw_bytes_of_the_stream_it_wraps(): void
    {
        $input = $this->over('SELECT 1;');

        self::assertSame(0, $input->rawBytesRead()->bytes);

        $input->header(4);

        self::assertSame(9, $input->rawBytesRead()->bytes);
    }

    private function over(string $sql): StreamedSqlInput
    {
        $source = fopen('php://memory', 'r+');
        self::assertIsResource($source);
        fwrite($source, $sql);
        rewind($source);

        return new StreamedSqlInput(PlainSqlStream::over($source, 9));
    }
}

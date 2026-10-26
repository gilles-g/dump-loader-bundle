<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\Tests\Unit\LoadJob;

use DumpLoader\LoadJob\ExecutionLog;
use DumpLoader\LoadJob\LogLine;
use DumpLoader\LoadJob\LogTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExecutionLogTest extends TestCase
{
    #[Test]
    public function it_should_have_no_line_and_no_omission_when_empty(): void
    {
        $log = ExecutionLog::empty();

        self::assertSame([], $log->lines);
        self::assertSame(0, $log->omittedLines);
    }

    #[Test]
    public function it_should_leave_the_original_log_untouched_when_appending(): void
    {
        $log = ExecutionLog::empty();

        $appended = $log->append($this->line('restoring database'));

        self::assertSame([], $log->lines);
        self::assertCount(1, $appended->lines);
    }

    #[Test]
    public function it_should_keep_the_insertion_order(): void
    {
        $log = ExecutionLog::empty()
            ->append($this->line('queued', LogTag::Queued))
            ->append($this->line('running', LogTag::Run))
            ->append($this->line('done', LogTag::Done));

        self::assertSame(['queued', 'running', 'done'], array_map(
            static fn (LogLine $line): string => $line->message,
            $log->lines,
        ));
        self::assertSame(LogTag::Queued, $log->lines[0]->tag);
    }

    #[Test]
    public function it_should_keep_the_most_recent_lines_and_count_the_omitted_ones(): void
    {
        $log = ExecutionLog::empty();

        for ($i = 1; $i <= ExecutionLog::MAX_LINES + 100; ++$i) {
            $log = $log->append($this->line('line '.$i));
        }

        self::assertCount(ExecutionLog::MAX_LINES, $log->lines);
        self::assertSame(100, $log->omittedLines);
        self::assertSame('line 101', $log->lines[0]->message);
        self::assertSame('line '.(ExecutionLog::MAX_LINES + 100), $log->lines[ExecutionLog::MAX_LINES - 1]->message);
    }

    #[Test]
    public function it_should_be_rebuilt_from_its_lines_and_its_omission_count(): void
    {
        $log = ExecutionLog::fromLines([$this->line('restoring database')], 42);

        self::assertCount(1, $log->lines);
        self::assertSame(42, $log->omittedLines);
    }

    #[Test]
    public function it_should_refuse_a_negative_omission_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExecutionLog::fromLines([], -1);
    }

    private function line(string $message, LogTag $tag = LogTag::Run): LogLine
    {
        return new LogLine(
            at: new \DateTimeImmutable('2026-09-18 10:00:00'),
            tag: $tag,
            message: $message,
        );
    }
}

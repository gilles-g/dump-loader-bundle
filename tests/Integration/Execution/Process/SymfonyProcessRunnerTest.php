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

namespace DumpLoader\Tests\Integration\Execution\Process;

use DumpLoader\Execution\CommandFailed;
use DumpLoader\Execution\Process\SymfonyProcessRunner;
use DumpLoader\Execution\ShellCommand;
use DumpLoader\LoadJob\LoadJobAborted;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\Step\StepTracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SymfonyProcessRunnerTest extends TestCase
{
    #[Test]
    public function it_should_report_the_exit_code_of_a_successful_command(): void
    {
        $result = $this->runner()->run(new ShellCommand(argv: ['sh', '-c', 'exit 0']), $this->tracker($this->logs()));

        self::assertSame(0, $result->exitCode);
        self::assertSame([], $result->errorOutput);
    }

    #[Test]
    public function it_should_feed_the_input_to_the_process(): void
    {
        $logs = $this->logs();
        $command = (new ShellCommand(argv: ['sh', '-c', 'wc -c']))->withInput($this->chunks(3, 4));

        $result = $this->runner()->run($command, $this->tracker($logs));

        self::assertSame(0, $result->exitCode);
        self::assertContains('12', $logs->getArrayCopy());
    }

    #[Test]
    public function it_should_keep_the_error_output_of_a_failed_command(): void
    {
        $logs = $this->logs();

        $result = $this->runner()->run(new ShellCommand(argv: ['sh', '-c', 'echo boom >&2; exit 3']), $this->tracker($logs));

        self::assertSame(3, $result->exitCode);
        self::assertSame(['boom'], $result->errorOutput);
        self::assertContains('boom', $logs->getArrayCopy());

        $this->expectException(CommandFailed::class);
        $result->ensureSuccess();
    }

    #[Test]
    public function it_should_pass_the_environment_to_the_process(): void
    {
        $logs = $this->logs();
        $command = new ShellCommand(argv: ['sh', '-c', 'echo "$SECRET"'], env: ['SECRET' => 'from-env']);

        $this->runner()->run($command, $this->tracker($logs));

        self::assertContains('from-env', $logs->getArrayCopy());
    }

    #[Test]
    public function it_should_stop_the_process_and_abort_when_the_tracker_requests_it(): void
    {
        $this->expectException(LoadJobAborted::class);

        $this->runner()->run(
            new ShellCommand(argv: ['sh', '-c', 'sleep 30']),
            $this->tracker($this->logs(), abortRequested: true),
        );
    }

    #[Test]
    public function it_should_keep_ticking_while_a_slow_process_produces_output(): void
    {
        $logs = $this->logs();
        $command = new ShellCommand(argv: ['sh', '-c', 'echo first; sleep 1; echo second']);

        $result = $this->runner()->run($command, $this->tracker($logs));

        self::assertSame(0, $result->exitCode);
        self::assertContains('first', $logs->getArrayCopy());
        self::assertContains('second', $logs->getArrayCopy());
    }

    private function runner(): SymfonyProcessRunner
    {
        return new SymfonyProcessRunner(new MockClock('2026-09-18 22:20:00'));
    }

    /**
     * @return \ArrayObject<int, string>
     */
    private function logs(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /**
     * @param \ArrayObject<int, string> $logs
     */
    private function tracker(\ArrayObject $logs, bool $abortRequested = false): StepTracker
    {
        $tracker = $this->createMock(StepTracker::class);
        $tracker->method('log')->willReturnCallback(static function (LogTag $tag, string $message) use ($logs): void {
            $logs[] = $message;
        });
        $tracker->method('abortRequested')->willReturn($abortRequested);

        return $tracker;
    }

    /**
     * @return \Generator<int, string>
     */
    private function chunks(int $count, int $size): \Generator
    {
        for ($i = 0; $i < $count; ++$i) {
            yield str_repeat('a', $size);
        }
    }
}

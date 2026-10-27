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

namespace DumpLoader\Execution\Process;

use DumpLoader\Dump\SqlStream;
use DumpLoader\Execution\ProcessRunner;
use DumpLoader\Execution\ShellCommand;
use DumpLoader\Execution\ShellCommandResult;
use DumpLoader\LoadJob\LoadJobAborted;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\Step\StepTracker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Process\Process;

final readonly class SymfonyProcessRunner implements ProcessRunner
{
    private const int BUSY_TICK_MICROSECONDS = 1_000;
    private const int IDLE_TICK_MICROSECONDS = 20_000;
    private const int IDLE_AFTER_SECONDS = 1;
    private const int ERROR_OUTPUT_LINES = 20;
    private const int STOP_TIMEOUT_SECONDS = 5;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function run(ShellCommand $command, StepTracker $tracker): ShellCommandResult
    {
        $process = new Process($command->argv, $command->cwd, $command->env);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->setInput($this->lazyInput($command->input));

        $errorOutput = new OutputLines();
        $outputBytes = 0;

        $process->start(static function (string $type, string $buffer) use ($tracker, &$errorOutput, &$outputBytes): void {
            $outputBytes += \strlen($buffer);
            $lines = OutputLines::fromBuffer($buffer);
            $tag = Process::ERR === $type ? LogTag::Error : LogTag::Run;

            foreach ($lines->values as $line) {
                $tracker->log($tag, $line);
            }

            if (Process::ERR === $type) {
                $errorOutput = $errorOutput->appendKeepingLast($lines, self::ERROR_OUTPUT_LINES);
            }
        });

        $aborted = false;
        $lastActivity = 0;
        $lastActivityAt = $this->clock->now();

        try {
            while ($process->isRunning()) {
                if ($tracker->abortRequested()) {
                    $aborted = true;

                    break;
                }

                $activity = $outputBytes + $this->bytesConsumed($command->input);
                $now = $this->clock->now();

                if ($activity !== $lastActivity) {
                    $lastActivity = $activity;
                    $lastActivityAt = $now;
                }

                usleep($now->getTimestamp() - $lastActivityAt->getTimestamp() >= self::IDLE_AFTER_SECONDS
                    ? self::IDLE_TICK_MICROSECONDS
                    : self::BUSY_TICK_MICROSECONDS);
            }
        } finally {
            $process->stop(self::STOP_TIMEOUT_SECONDS);
        }

        if ($aborted) {
            $tracker->log(LogTag::Stop, \sprintf('Stopped on request: %s', $command->display()));

            throw LoadJobAborted::onRequest();
        }

        return new ShellCommandResult($process->getExitCode() ?? -1, $errorOutput->values);
    }

    /**
     * @param iterable<string> $input
     *
     * @return \Generator<int, string>
     */
    private function lazyInput(iterable $input): \Generator
    {
        foreach ($input as $chunk) {
            yield $chunk;
        }
    }

    /**
     * @param iterable<string> $input
     */
    private function bytesConsumed(iterable $input): int
    {
        return $input instanceof SqlStream ? $input->rawBytesRead()->bytes : 0;
    }
}

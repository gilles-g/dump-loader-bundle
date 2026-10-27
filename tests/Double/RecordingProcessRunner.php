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

namespace DumpLoader\Tests\Double;

use DumpLoader\Execution\ProcessRunner;
use DumpLoader\Execution\ShellCommand;
use DumpLoader\Execution\ShellCommandResult;
use DumpLoader\Step\StepTracker;

final class RecordingProcessRunner implements ProcessRunner
{
    /**
     * @var list<ShellCommand>
     */
    public array $commands = [];

    private function __construct(
        private readonly ShellCommandResult $result,
    ) {
    }

    public static function succeeding(): self
    {
        return new self(new ShellCommandResult(0, []));
    }

    /**
     * @param list<string> $errorOutput
     */
    public static function failingWith(int $exitCode, array $errorOutput = []): self
    {
        return new self(new ShellCommandResult($exitCode, $errorOutput));
    }

    public function run(ShellCommand $command, StepTracker $tracker): ShellCommandResult
    {
        $this->commands[] = $command;

        return $this->result;
    }

    public function lastCommand(): ShellCommand
    {
        if ([] === $this->commands) {
            throw new \LogicException('No command has been run.');
        }

        return $this->commands[\count($this->commands) - 1];
    }
}

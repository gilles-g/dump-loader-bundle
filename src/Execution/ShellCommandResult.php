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

namespace DumpLoader\Execution;

final readonly class ShellCommandResult
{
    /**
     * @param list<string> $errorOutput
     */
    public function __construct(
        public int $exitCode,
        public array $errorOutput,
    ) {
    }

    public function ensureSuccess(): void
    {
        if (0 !== $this->exitCode) {
            throw CommandFailed::withExitCode($this->exitCode, $this->errorOutput);
        }
    }
}

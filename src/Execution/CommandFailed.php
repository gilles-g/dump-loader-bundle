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

namespace DumpLoader\Execution;

final class CommandFailed extends \RuntimeException
{
    /**
     * @param list<string> $errorOutput
     */
    public static function withExitCode(int $exitCode, array $errorOutput): self
    {
        return new self(\sprintf('The command exited with code %d: %s', $exitCode, [] === $errorOutput ? 'no error output.' : implode(' | ', $errorOutput)));
    }
}

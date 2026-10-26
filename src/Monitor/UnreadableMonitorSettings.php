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

namespace DumpLoader\Monitor;

final class UnreadableMonitorSettings extends \RuntimeException
{
    public static function at(string $path, string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('Cannot read the monitor settings published at "%s": %s', $path, $reason), previous: $previous);
    }
}

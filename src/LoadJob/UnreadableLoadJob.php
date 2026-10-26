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

namespace DumpLoader\LoadJob;

final class UnreadableLoadJob extends \RuntimeException
{
    public static function at(string $location, string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('Cannot read the load job stored at "%s": %s', $location, $reason), previous: $previous);
    }
}

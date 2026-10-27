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

namespace DumpLoader\Dump;

final class CorruptedDump extends \RuntimeException implements DumpNotRestorable
{
    public static function truncated(): self
    {
        return new self('The gzipped dump ends before the end of its compressed stream: it is truncated or corrupted.');
    }

    public static function undecodable(): self
    {
        return new self('The gzipped dump cannot be inflated: it is not a valid gzip stream.');
    }
}

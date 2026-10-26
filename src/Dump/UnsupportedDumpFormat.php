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

namespace DumpLoader\Dump;

final class UnsupportedDumpFormat extends \RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(\sprintf('"%s" is not a dump: only .sql and .sql.gz files are supported.', $path));
    }
}

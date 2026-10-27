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

namespace DumpLoader\Database\Mysql;

final class MysqlBinaryNotFound extends \RuntimeException
{
    public static function named(string $binary, string $fallback): self
    {
        return new self(\sprintf('Neither "%s" nor "%s" was found in the PATH: install a MySQL or MariaDB client in the PHP image.', $binary, $fallback));
    }

    public static function at(string $path): self
    {
        return new self(\sprintf('The configured MySQL client "%s" is not an executable file.', $path));
    }
}
